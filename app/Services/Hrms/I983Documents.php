<?php

namespace App\Services\Hrms;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class I983Documents
{
    public const STUDENT_SNAPSHOT = 'student_snapshot';

    public const HR_WORKING = 'hr_working';

    public const EMPLOYER_SIGNED = 'employer_signed';

    public const FINAL_SIGNED = 'final_signed';

    public function __construct(
        private readonly I983Pdf $pdf,
        private readonly I983StudentCertifications $studentCertifications,
    ) {}

    /**
     * @return list<array{id: int, kind: string, version: int, sha256: string, createdAt: string, createdBy: string, bytes: int, sourceDocumentId: int|null}>
     */
    public function list(int $tenantId, int $caseId): array
    {
        if (! DB::getSchemaBuilder()->hasTable('i983_documents') || ! DB::getSchemaBuilder()->hasColumn('i983_documents', 'version')) {
            return [];
        }

        return DB::table('i983_documents')
            ->leftJoin('users', 'users.id', '=', 'i983_documents.created_by')
            ->where('i983_documents.tenant_id', $tenantId)
            ->where('i983_documents.case_id', $caseId)
            ->orderBy('i983_documents.id')
            ->get([
                'i983_documents.id',
                'i983_documents.kind',
                'i983_documents.version',
                'i983_documents.sha256',
                'i983_documents.size',
                'i983_documents.created_at',
                'i983_documents.created_by',
                'i983_documents.source_document_id',
                'users.name as creator',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'kind' => (string) $row->kind,
                'version' => (int) $row->version,
                'sha256' => (string) ($row->sha256 ?? ''),
                'createdAt' => substr((string) $row->created_at, 0, 16),
                'createdBy' => $row->created_by === null ? 'Student' : (string) ($row->creator ?? 'HR'),
                'bytes' => (int) $row->size,
                'sourceDocumentId' => $row->source_document_id === null ? null : (int) $row->source_document_id,
            ])
            ->all();
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function draft(object $case): array
    {
        $values = $this->values((int) $case->id);
        $this->pdf->assertFits($values, 'draft');

        return [
            'bytes' => $this->pdf->render($values, null, $this->studentCertification($case), true),
            'filename' => $this->downloadName($case, 'draft', 1),
        ];
    }

    public function studentSnapshot(int $caseId, ?int $actorId): void
    {
        $case = DB::table('i983_cases')->where('id', $caseId)->first();

        if ($case === null || $case->student_submitted_at === null) {
            throw ValidationException::withMessages([
                'form' => 'The student-submitted PDF is created after Section 1 and the Section 2 certification are saved.',
            ]);
        }

        $this->store($case, self::STUDENT_SNAPSHOT, 'student', $actorId);
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function file(int $tenantId, int $caseId, int $documentId): array
    {
        $row = DB::table('i983_documents')
            ->where('id', $documentId)
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->first();
        $bytes = $row === null ? null : Storage::disk('local')->get((string) $row->file_path);

        if (! is_string($bytes) || $bytes === '' || ! str_starts_with($bytes, '%PDF')) {
            abort(404);
        }

        return [
            'bytes' => $bytes,
            'filename' => $this->downloadName($this->caseRow($caseId), (string) $row->kind, (int) $row->version),
        ];
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function finalSignedFile(object $case): array
    {
        $row = $this->latest($case, self::FINAL_SIGNED);
        $bytes = $row === null ? null : Storage::disk('local')->get((string) $row->file_path);

        if (! is_string($bytes) || $bytes === '' || ! str_starts_with($bytes, '%PDF')) {
            abort(404);
        }

        return [
            'bytes' => $bytes,
            'filename' => $this->downloadName($case, self::FINAL_SIGNED, (int) $row->version),
        ];
    }

    public function sign(object $case, int $actorId, string $imagePath, string $name, string $title, string $organization): void
    {
        $working = $this->latest($case, self::HR_WORKING);

        if ($working === null) {
            throw ValidationException::withMessages([
                'form' => 'Generate an HR working PDF before applying the employer signature.',
            ]);
        }

        if ($this->sourced((int) $case->id, self::EMPLOYER_SIGNED, (int) $working->id)) {
            throw ValidationException::withMessages([
                'form' => 'This HR working PDF version is already signed. The signed file was not replaced.',
            ]);
        }

        $this->assertCurrent($case, (string) $working->created_at);
        $signature = [
            'image' => $imagePath,
            'name_title' => $name.', '.$title,
            'date' => now()->format('m-d-Y'),
            'organization' => $organization,
        ];
        $values = $this->values((int) $case->id);
        $studentCertification = $this->studentCertification($case);
        $this->pdf->assertFits($values, 'working');
        $this->pdf->assertSignatureFits($signature);
        $bytes = $this->pdf->render($values, $signature, $studentCertification);
        $documentId = $this->persist($case, self::EMPLOYER_SIGNED, $bytes, $actorId, (int) $working->id, 'signature_applied');
        $copy = 'i983/'.$case->tenant_id.'/'.$case->id.'/signature-v'.$documentId.'.'.pathinfo($imagePath, PATHINFO_EXTENSION);
        Storage::disk('local')->put($copy, (string) file_get_contents($imagePath));
        $existing = DB::table('i983_certifications')->where('case_id', $case->id)->where('party', 'employer')->first();
        $certification = [
            'printed_name' => $name,
            'title' => $title,
            'certified_on' => now()->toDateString(),
            'signature_path' => $copy,
            'acknowledged' => true,
            'updated_at' => now(),
        ];

        if ($existing === null) {
            DB::table('i983_certifications')->insert([
                ...$certification,
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'party' => 'employer',
                'created_at' => now(),
            ]);
        } else {
            if (is_string($existing->signature_path) && $existing->signature_path !== '') {
                Storage::disk('local')->delete($existing->signature_path);
            }
            DB::table('i983_certifications')->where('id', $existing->id)->update($certification);
        }
    }

    public function finalize(object $case, int $actorId): void
    {
        $signed = $this->latest($case, self::EMPLOYER_SIGNED);

        if ($signed === null) {
            throw ValidationException::withMessages([
                'form' => 'Apply the employer signature before finalizing the I-983.',
            ]);
        }

        if ($this->sourced((int) $case->id, self::FINAL_SIGNED, (int) $signed->id)) {
            throw ValidationException::withMessages([
                'form' => 'This signed I-983 is already finalized. The finalized PDF was not replaced.',
            ]);
        }

        $working = DB::table('i983_documents')->where('id', $signed->source_document_id)->first();

        if ($working === null || $this->latest($case, self::HR_WORKING)?->id !== $working->id) {
            throw ValidationException::withMessages([
                'form' => 'Generate a new HR working PDF and apply the signature again before finalizing.',
            ]);
        }

        $this->assertCurrent($case, (string) $signed->created_at);
        $this->pdf->assertFits($this->values((int) $case->id), 'working');
        $bytes = Storage::disk('local')->get((string) $signed->file_path);

        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF') || preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes) !== 5) {
            throw ValidationException::withMessages([
                'form' => 'The signed PDF is missing one of the five official pages, so it was not finalized.',
            ]);
        }

        $this->persist($case, self::FINAL_SIGNED, $bytes, $actorId, (int) $signed->id, 'i983_finalized');
    }

    public function workingCopy(int $caseId, int $actorId): void
    {
        $case = DB::table('i983_cases')->where('id', $caseId)->first();

        if ($case === null || $case->student_submitted_at === null) {
            throw ValidationException::withMessages([
                'form' => 'Generate the HR working PDF after the student submits Section 1 and certifies Section 2.',
            ]);
        }

        $this->store($case, self::HR_WORKING, 'working', $actorId);
    }

    public function assertStudentFormFits(int $caseId): void
    {
        $this->pdf->assertFits($this->values($caseId), 'student');
    }

    private function store(object $case, string $kind, string $mode, ?int $actorId): void
    {
        $values = $this->values((int) $case->id);
        $studentCertification = $this->studentCertification($case);
        $signature = $mode === 'working' ? $this->employerSignature($case) : null;
        $this->pdf->assertFits($values, $mode);

        if ($signature !== null) {
            $this->pdf->assertSignatureFits($signature);
        }

        $this->persist($case, $kind, $this->pdf->render($values, $signature, $studentCertification), $actorId, null, 'pdf_generated');
    }

    /**
     * @return array{image: string, printed_name: string, date: string}|null
     */
    private function studentCertification(object $case): ?array
    {
        $certification = $this->studentCertifications->pdfPayload($case);

        if ($certification !== null) {
            $this->pdf->assertStudentCertificationFits($certification);
        }

        return $certification;
    }

    private function persist(object $case, string $kind, string $bytes, ?int $actorId, ?int $sourceId, string $action): int
    {
        $version = ((int) DB::table('i983_documents')->where('case_id', $case->id)->where('kind', $kind)->max('version')) + 1;
        $path = 'i983/'.$case->tenant_id.'/'.$case->id.'/'.$kind.'-v'.$version.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        try {
            $id = DB::table('i983_documents')->insertGetId([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'kind' => $kind,
                'version' => $version,
                'sha256' => hash('sha256', $bytes),
                'source_document_id' => $sourceId,
                'file_path' => $path,
                'original_name' => $this->downloadName($case, $kind, $version),
                'mime' => 'application/pdf',
                'size' => strlen($bytes),
                'created_by' => $actorId,
                'created_at' => now(),
            ]);
            DB::table('i983_events')->insert([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'actor_id' => $actorId,
                'actor_type' => $action === 'signature_applied' || $action === 'i983_finalized' ? 'signatory' : ($actorId === null ? 'student' : 'hr'),
                'action' => $action,
                'subject' => hash('sha256', $bytes),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return (int) $id;
    }

    private function latest(object $case, string $kind): ?object
    {
        return DB::table('i983_documents')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->first();
    }

    private function sourced(int $caseId, string $kind, int $sourceId): bool
    {
        return DB::table('i983_documents')
            ->where('case_id', $caseId)
            ->where('kind', $kind)
            ->where('source_document_id', $sourceId)
            ->exists();
    }

    private function assertCurrent(object $case, string $createdAt): void
    {
        foreach (['i983_students', 'i983_employers', 'i983_training_plans'] as $table) {
            $updated = DB::table($table)->where('case_id', $case->id)->value('updated_at');

            if ($updated !== null && (string) $updated > $createdAt) {
                throw ValidationException::withMessages([
                    'form' => 'The case changed after that PDF was stored. Generate a new HR working PDF before signing or finalizing. Earlier files were not replaced.',
                ]);
            }
        }
    }

    private function caseRow(int $caseId): object
    {
        return DB::table('i983_cases')->where('id', $caseId)->first();
    }

    private function downloadName(object $case, string $kind, int $version): string
    {
        $student = DB::table('i983_students')->where('case_id', $case->id)->first();
        $label = trim(trim((string) ($student->given_name ?? '')).' '.trim((string) ($student->surname ?? '')));

        if ($label === '') {
            $label = trim((string) $case->candidate_name);
        }

        $safe = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_');
        $safe = $safe === '' ? 'Student' : $safe;
        $suffix = match ($kind) {
            'draft' => 'Draft',
            self::HR_WORKING => 'HR_Working_v'.$version,
            self::EMPLOYER_SIGNED => 'Employer_Signed_v'.$version,
            self::FINAL_SIGNED => $version === 1 ? 'Final_Signed' : 'Final_Signed_v'.$version,
            default => 'Student_Submitted_v'.$version,
        };

        return 'I983_'.$safe.'_'.$suffix.'.pdf';
    }

    /**
     * @return array<string, string>
     */
    private function values(int $caseId): array
    {
        $student = DB::table('i983_students')->where('case_id', $caseId)->first();
        $employer = DB::table('i983_employers')->where('case_id', $caseId)->first();
        $training = DB::table('i983_training_plans')->where('case_id', $caseId)->first();
        $surname = trim((string) ($student->surname ?? ''));
        $given = trim((string) ($student->given_name ?? ''));
        $studentName = $surname === '' ? $given : ($given === '' ? $surname : $surname.', '.$given);

        return [
            'student_name' => $studentName,
            'email' => trim((string) ($student->email ?? '')),
            'school_recommending' => trim((string) ($student->school_recommending ?? '')),
            'school_degree' => trim((string) ($student->school_degree ?? '')),
            'sevis_school_code' => trim((string) ($student->sevis_school_code ?? '')),
            'dso_name' => trim((string) ($student->dso_name ?? '')),
            'dso_email' => trim((string) ($student->dso_email ?? '')),
            'dso_phone' => trim((string) ($student->dso_phone ?? '')),
            'sevis_id' => $this->reveal($student->sevis_id_ciphertext ?? null),
            'opt_start' => $this->printedDate($student->opt_start ?? null),
            'opt_end' => $this->printedDate($student->opt_end ?? null),
            'major_cip' => trim((string) ($student->major_cip ?? '')),
            'degree_level' => trim((string) ($student->degree_level ?? '')),
            'degree_awarded_on' => $this->printedDate($student->degree_awarded_on ?? null),
            'prior_degree' => $student === null || $student->prior_degree === null ? '' : (in_array($student->prior_degree, [1, '1', true, 'yes'], true) ? 'yes' : 'no'),
            'ead' => $this->reveal($student->ead_ciphertext ?? null),
            'employer_name' => trim((string) ($employer->legal_name ?? '')),
            'street' => trim((string) ($employer->street ?? '')),
            'suite' => trim((string) ($employer->suite ?? '')),
            'website' => trim((string) ($employer->website ?? '')),
            'city' => trim((string) ($employer->city ?? '')),
            'state' => trim((string) ($employer->state ?? '')),
            'zip' => trim((string) ($employer->zip ?? '')),
            'ein' => $this->reveal($employer->ein_ciphertext ?? null),
            'employee_count' => $employer === null || $employer->employee_count === null ? '' : (string) $employer->employee_count,
            'naics' => trim((string) ($employer->naics ?? '')),
            'opt_hours' => $employer === null || $employer->opt_hours === null ? '' : (string) $employer->opt_hours,
            'salary' => $employer === null ? '' : $this->annualSalary($employer),
            'start_date' => $this->printedDate($employer->start_date ?? null),
            'other_compensation' => trim((string) ($employer->other_compensation ?? '')),
            'site_name' => trim((string) ($training->site_name ?? '')),
            'site_address' => trim((string) ($training->site_address ?? '')),
            'official_name' => trim((string) ($training->official_name ?? '')),
            'official_title' => trim((string) ($training->official_title ?? '')),
            'official_email' => trim((string) ($training->official_email ?? '')),
            'official_phone' => trim((string) ($training->official_phone ?? '')),
            'student_role' => trim((string) ($training->student_role ?? '')),
            'goals' => trim((string) ($training->goals ?? '')),
            'oversight' => trim((string) ($training->oversight ?? '')),
            'measures' => trim((string) ($training->measures ?? '')),
            'remarks' => trim((string) ($training->remarks ?? '')),
        ];
    }

    /**
     * @return array{image: string, name_title: string, date: string, organization: string}
     */
    private function employerSignature(object $case): array
    {
        try {
            $profile = app(I983Signatory::class)->ready((int) $case->tenant_id);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'signature' => 'Save the employer official\'s signature, printed name, and title in I-983 settings before generating the official form.',
            ]);
        }

        $organization = trim((string) DB::table('i983_employers')->where('case_id', $case->id)->value('legal_name'));

        if ($organization === '') {
            throw ValidationException::withMessages([
                'employer_name' => 'Enter the printed name of the employing organization before generating the official form.',
            ]);
        }

        $arrangement = trim((string) DB::table('i983_training_plans')->where('case_id', $case->id)->value('work_arrangement'));

        if (! in_array($arrangement, ['remote', 'on_site', 'hybrid'], true)) {
            throw ValidationException::withMessages([
                'work_arrangement' => 'Select whether the training site is remote, on-site, or hybrid before generating the official form.',
            ]);
        }

        return [
            'image' => $profile['path'],
            'name_title' => $profile['name'].', '.$profile['title'],
            'date' => now()->format('m-d-Y'),
            'organization' => $organization,
        ];
    }

    private function annualSalary(object $employer): string
    {
        $stored = property_exists($employer, 'annual_compensation') ? $employer->annual_compensation : null;
        $frequency = (string) ($employer->salary_frequency ?? '');
        $amount = $stored !== null
            ? $stored
            : (in_array($frequency, ['hour', 'month'], true) ? null : $employer->salary);

        if ($amount === null || $amount === '' || (float) $amount <= 0) {
            return '';
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function printedDate(mixed $value): string
    {
        $text = substr(trim((string) $value), 0, 10);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return '';
        }

        return substr($text, 5, 2).'-'.substr($text, 8, 2).'-'.substr($text, 0, 4);
    }

    private function reveal(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }
}
