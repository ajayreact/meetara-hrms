<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocuments
{
    private const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'js', 'mjs', 'sh', 'html', 'htm', 'svg', 'dll'];

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

    public function __construct(
        private readonly EmployeeOnboarding $onboarding,
        private readonly CompanyNotifier $notifier,
    ) {}

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category] ?? 'Other';
    }

    public static function mask(string $value): string
    {
        return preg_replace('/\d{4,}/', '****', $value) ?? '';
    }

    public function store(User $actor, Employee $employee, string $category, UploadedFile $file, ?string $reference = null, ?string $description = null): int
    {
        $this->assertReady($actor, $employee);
        $this->assertCategory($actor, $employee, $category);
        $this->assertFile($file);
        $latest = $this->latest($employee, $category);

        if ($latest !== null && $latest->review_status !== 'rejected') {
            throw ValidationException::withMessages([
                'file' => 'This category already has a document awaiting review or already verified. A replacement is accepted after rejection.',
            ]);
        }

        $extension = self::MIMES[(string) $file->getMimeType()];
        $directory = 'employee-documents/'.(int) $employee->tenant_id.'/'.(int) $employee->id;
        $path = $file->storeAs($directory, (string) Str::uuid().'.'.$extension, 'local');

        if ($path === false || ! str_starts_with($path, $directory.'/') || str_contains($path, '..')) {
            throw ValidationException::withMessages([
                'file' => 'The file could not be stored.',
            ]);
        }

        try {
            $id = DB::transaction(function () use ($actor, $employee, $category, $file, $path, $latest, $reference, $description): int {
                $values = [
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'category' => $category,
                    'title' => app(DocumentCatalog::class)->name((int) $employee->tenant_id, $category),
                    'file_path' => $path,
                    'original_name' => $this->originalName($file),
                    'review_status' => 'pending',
                    'uploaded_by' => $actor->id,
                    'replaces_document_id' => $latest?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('employee_documents', 'reference')) {
                    $values['reference'] = $this->shortText($reference, 80);
                    $values['description'] = $this->shortText($description, 500);
                }

                $id = (int) DB::table('employee_documents')->insertGetId($values);
                $this->onboarding->sync($employee);
                $this->onboarding->record($employee, $actor, 'document_uploaded', $category);
                $this->notifyHr($employee, $id, $latest !== null);

                return $id;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return $id;
    }

    public function review(User $actor, Employee $employee, int $documentId, string $status, ?string $note, ?string $notice = null): void
    {
        $this->assertReady($actor, $employee);
        abort_unless($actor->can('update', $employee) && (int) $employee->user_id !== (int) $actor->id, 403);

        $document = $this->find($employee, $documentId);
        $kind = $notice ?? match ($status) {
            'verified' => 'verified',
            'rejected' => 'rejected',
            default => null,
        };

        DB::transaction(function () use ($actor, $employee, $document, $status, $note, $kind): void {
            DB::table('employee_documents')->where('id', $document->id)->update([
                'review_status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'updated_at' => now(),
            ]);
            $this->onboarding->sync($employee);
            $this->onboarding->record($employee, $actor, 'document_reviewed', (string) $document->category);

            if ($kind !== null) {
                $this->notifyEmployee($employee, (int) $document->id, $kind);
            }
        });
    }

    public function requestReplacement(User $actor, Employee $employee, int $documentId, string $note): void
    {
        $this->review($actor, $employee, $documentId, 'rejected', $note, 'replacement');
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

    public function preview(User $actor, Employee $employee, int $documentId): StreamedResponse
    {
        $this->download($actor, $employee, $documentId);
        $document = $this->find($employee, $documentId);
        $extension = strtolower(pathinfo((string) $document->file_path, PATHINFO_EXTENSION));
        $type = array_search($extension, self::MIMES, true);
        abort_unless(is_string($type), 404);

        return Storage::disk('local')->response((string) $document->file_path, self::mask((string) $document->original_name), [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline; filename="'.self::mask((string) $document->original_name).'"',
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

        $known = array_key_exists($category, self::CATEGORIES)
            || app(DocumentCatalog::class)->allows((int) $employee->tenant_id, $category, $actor->can('update', $employee));

        if (! $known || ($category === 'generated_offer' && ! $actor->can('update', $employee))) {
            throw ValidationException::withMessages([
                'category' => 'Choose a document category you are allowed to upload.',
            ]);
        }
    }

    private function assertFile(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true) || ! array_key_exists((string) $file->getMimeType(), self::MIMES)) {
            throw ValidationException::withMessages([
                'file' => 'Upload a PDF, JPEG, or PNG file.',
            ]);
        }
    }

    private function shortText(?string $value, int $limit): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : substr($text, 0, $limit);
    }

    private function notifyHr(Employee $employee, int $documentId, bool $replacement): void
    {
        $ids = [
            ...$this->notifier->roleUserIds((int) $employee->tenant_id, 'Owner'),
            ...$this->notifier->roleUserIds((int) $employee->tenant_id, 'HR'),
        ];
        $ids = array_values(array_diff(array_unique($ids), [(int) $employee->user_id]));
        $this->notifier->onceEach(
            $ids,
            'document-'.$documentId.'-uploaded',
            $replacement ? 'Document replaced' : 'Document uploaded',
            'A document is waiting for verification.',
            route('hrms.documents.employee', $employee->id),
        );
    }

    private function notifyEmployee(Employee $employee, int $documentId, string $kind): void
    {
        if ($employee->user_id === null) {
            return;
        }

        [$title, $message] = match ($kind) {
            'verified' => ['Document verified', 'A document was verified.'],
            'replacement' => ['Document replacement requested', 'Upload a replacement from My Documents.'],
            default => ['Document rejected', 'A document was rejected. Upload a replacement from My Documents.'],
        };

        $this->notifier->once(
            (int) $employee->user_id,
            'document-'.$documentId.'-'.$kind,
            $title,
            $message,
            route('hrms.my-documents'),
        );
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
