<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocuments
{
    public const CATEGORIES = [
        'generated_offer' => 'Generated offer letter',
        'signed_offer' => 'Signed offer letter',
        'pan' => 'PAN',
        'aadhaar' => 'Aadhaar',
        'bank_proof' => 'Bank proof',
        'resume' => 'Resume',
        'other' => 'Other',
    ];

    private const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(private readonly EmployeeOnboarding $onboarding) {}

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category] ?? 'Other';
    }

    public static function mask(string $value): string
    {
        return preg_replace('/\d{4,}/', '****', $value) ?? '';
    }

    public function store(User $actor, Employee $employee, string $category, UploadedFile $file): void
    {
        $this->assertReady($actor, $employee);
        $this->assertCategory($actor, $employee, $category);
        $latest = $this->latest($employee, $category);

        if ($latest !== null && $latest->review_status !== 'rejected') {
            throw ValidationException::withMessages([
                'file' => 'This category already has a document awaiting review or already verified. A replacement is accepted after rejection.',
            ]);
        }

        $mime = (string) $file->getMimeType();
        $extension = self::MIMES[$mime] ?? null;

        if ($extension === null) {
            throw ValidationException::withMessages([
                'file' => 'Upload a PDF, JPEG, or PNG file.',
            ]);
        }

        $directory = 'employee-documents/'.(int) $employee->tenant_id.'/'.(int) $employee->id;
        $path = $file->storeAs($directory, (string) Str::uuid().'.'.$extension, 'local');

        if ($path === false || ! str_starts_with($path, $directory.'/')) {
            throw ValidationException::withMessages([
                'file' => 'The file could not be stored.',
            ]);
        }

        DB::transaction(function () use ($actor, $employee, $category, $file, $path, $latest): void {
            DB::table('employee_documents')->insert([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'category' => $category,
                'title' => self::label($category),
                'file_path' => $path,
                'original_name' => $this->originalName($file),
                'review_status' => 'pending',
                'uploaded_by' => $actor->id,
                'replaces_document_id' => $latest?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->onboarding->sync($employee);
            $this->onboarding->record($employee, $actor, 'document_uploaded', $category);
        });
    }

    public function review(User $actor, Employee $employee, int $documentId, string $status, ?string $note): void
    {
        $this->assertReady($actor, $employee);
        abort_unless($actor->can('update', $employee) && (int) $employee->user_id !== (int) $actor->id, 403);

        $document = $this->find($employee, $documentId);

        DB::transaction(function () use ($actor, $employee, $document, $status, $note): void {
            DB::table('employee_documents')->where('id', $document->id)->update([
                'review_status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'updated_at' => now(),
            ]);
            $this->onboarding->sync($employee);
            $this->onboarding->record($employee, $actor, 'document_reviewed', (string) $document->category);
        });
    }

    public function download(User $actor, Employee $employee, int $documentId): StreamedResponse
    {
        $this->assertReady($actor, $employee);
        $self = (int) $employee->user_id === (int) $actor->id;
        abort_unless($actor->can('update', $employee) || $self, 403);

        $document = $this->find($employee, $documentId);
        $prefix = 'employee-documents/'.(int) $employee->tenant_id.'/'.(int) $employee->id.'/';
        abort_unless(str_starts_with((string) $document->file_path, $prefix) && ! str_contains((string) $document->file_path, '..'), 404);
        abort_unless(Storage::disk('local')->exists((string) $document->file_path), 404);

        return Storage::disk('local')->download((string) $document->file_path, self::mask((string) $document->original_name), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assertReady(User $actor, Employee $employee): void
    {
        abort_unless($this->onboarding->ready(), 404);
        abort_if((int) $actor->companyId() !== (int) $employee->tenant_id, 404);
    }

    private function assertCategory(User $actor, Employee $employee, string $category): void
    {
        $self = (int) $employee->user_id === (int) $actor->id;
        abort_unless($actor->can('update', $employee) || $self, 403);

        if (! array_key_exists($category, self::CATEGORIES) || ($category === 'generated_offer' && ! $actor->can('update', $employee))) {
            throw ValidationException::withMessages([
                'category' => 'Choose a document category you are allowed to upload.',
            ]);
        }
    }

    private function latest(Employee $employee, string $category): ?object
    {
        return DB::table('employee_documents')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('category', $category)
            ->orderByDesc('id')
            ->first();
    }

    private function find(Employee $employee, int $documentId): object
    {
        $document = DB::table('employee_documents')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('id', $documentId)
            ->first();
        abort_if($document === null, 404);

        return $document;
    }

    private function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = preg_replace('/[^\w.\- ]+/u', '', $name) ?? 'document';

        return substr($name === '' ? 'document' : $name, 0, 191);
    }
}
