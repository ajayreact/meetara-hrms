<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class I983Cases
{
    public function __construct(
        private readonly I983Documents $documents,
        private readonly I983Signatory $signatory,
        private readonly I983StudentCertifications $studentCertifications,
        private readonly StoredFileQuarantine $quarantine,
    ) {}

    public const TRAINING_FIELDS = [
        'student_name', 'employer_name', 'site_name', 'site_address', 'work_arrangement', 'official_name', 'official_title',
        'official_email', 'official_phone', 'student_role', 'goals', 'oversight', 'measures', 'remarks',
    ];

    public const EMPLOYER_TRAINING_FIELDS = [
        'site_name', 'site_address', 'work_arrangement', 'official_name', 'official_title', 'official_email', 'official_phone',
        'oversight', 'measures', 'remarks',
    ];

    public const SITE_ADDRESS_WIDTH = 268.0;

    public function manages(object $user): bool
    {
        $role = method_exists($user, 'applicationRole') ? $user->applicationRole() : '';

        return in_array($role, ['company_admin', 'hr'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $companyId): array
    {
        if (! Schema::hasTable('i983_cases')) {
            return [];
        }

        return DB::table('i983_cases')
            ->where('tenant_id', $companyId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'reference' => (string) $row->reference,
                'candidate' => (string) $row->candidate_name,
                'email' => (string) $row->candidate_email,
                'status' => (string) $row->status,
                'submitted' => $row->student_submitted_at !== null,
                'createdAt' => substr((string) $row->created_at, 0, 10),
            ])
            ->all();
    }

    public function remove(User $actor, int $caseId): void
    {
        abort_unless($actor->applicationRole() === 'company_admin', 403);
        $companyId = (int) $actor->companyId();
        $case = DB::table('i983_cases')->where('tenant_id', $companyId)->where('id', $caseId)->first();
        abort_if($case === null, 404);

        $directory = 'i983/'.$companyId.'/'.$caseId;

        try {
            $paths = $this->caseFiles($companyId, $caseId, $directory);
            $this->quarantine->commit($paths, function () use ($actor, $companyId, $caseId, $case): void {
                foreach ([
                    'i983_student_certifications',
                    'i983_documents',
                    'i983_certifications',
                    'i983_corrections',
                    'i983_evaluations',
                    'i983_field_revisions',
                    'i983_employers',
                    'i983_students',
                    'i983_training_plans',
                    'i983_links',
                    'i983_events',
                ] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->where('tenant_id', $companyId)->where('case_id', $caseId)->delete();
                    }
                }

                $deleted = DB::table('i983_cases')->where('tenant_id', $companyId)->where('id', $caseId)->delete();
                abort_if($deleted !== 1, 404);
                $this->event($companyId, null, (int) $actor->id, 'hr', 'case_deleted', (string) $case->reference);
            });
        } catch (\Throwable $exception) {
            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'delete' => 'The I-983 case was not deleted. Its stored files are unchanged.',
            ]);
        }

        $this->removeEmptyCaseDirectory($directory);
    }

    /**
     * @return list<string>
     */
    private function caseFiles(int $companyId, int $caseId, string $directory): array
    {
        if ($companyId < 1 || $caseId < 1 || preg_match('#^i983/[1-9][0-9]*/[1-9][0-9]*$#', $directory) !== 1) {
            throw new \RuntimeException('The I-983 directory is not limited to one case.');
        }

        $full = Storage::disk('local')->path($directory);

        if (! is_dir($full)) {
            return [];
        }

        $files = Storage::disk('local')->allFiles($directory);

        foreach ($files as $file) {
            if (! str_starts_with($file, $directory.'/') || str_contains($file, '..')) {
                throw new \RuntimeException('The I-983 directory is not limited to one case.');
            }
        }

        return $files;
    }

    private function removeEmptyCaseDirectory(string $directory): void
    {
        if (preg_match('#^i983/[1-9][0-9]*/[1-9][0-9]*$#', $directory) !== 1) {
            return;
        }

        $disk = Storage::disk('local');
        $full = $disk->path($directory);

        if (! is_dir($full) || $disk->allFiles($directory) !== []) {
            return;
        }

        $disk->deleteDirectory($directory);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int, url: string}
     */
    public function open(User $actor, array $input): array
    {
        $this->assertManager($actor);
        $name = trim((string) ($input['candidate_name'] ?? ''));
        $email = strtolower(trim((string) ($input['candidate_email'] ?? '')));

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'candidate_name' => 'Enter the candidate name and a valid email address.',
            ]);
        }

        $companyId = (int) $actor->companyId();
        $token = bin2hex(random_bytes(32));
        $now = now();
        $suggestion = $this->companySuggestion($companyId);
        $caseId = 0;

        DB::transaction(function () use ($actor, $companyId, $name, $email, $token, $now, $suggestion, &$caseId): void {
            $caseId = DB::table('i983_cases')->insertGetId([
                'tenant_id' => $companyId,
                'reference' => $this->reference($companyId),
                'candidate_name' => $name,
                'candidate_email' => $email,
                'email_hash' => hash('sha256', $email),
                'status' => 'awaiting_student',
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('i983_students')->insert([
                'tenant_id' => $companyId,
                'case_id' => $caseId,
                'email' => $email,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('i983_employers')->insert([
                'tenant_id' => $companyId,
                'case_id' => $caseId,
                'legal_name' => $suggestion['legal_name'],
                'street' => $suggestion['street'],
                'website' => $suggestion['website'],
                'ein_ciphertext' => $this->seal($suggestion['ein']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('i983_training_plans')->insert([
                'tenant_id' => $companyId,
                'case_id' => $caseId,
                'student_name' => $name,
                'employer_name' => $suggestion['legal_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->storeLink($companyId, $caseId, (int) $actor->id, $token, $now);
            $this->event($companyId, $caseId, (int) $actor->id, 'hr', 'case_created', 'case');
            $this->event($companyId, $caseId, (int) $actor->id, 'hr', 'link_issued', 'student_link');
        });

        return ['id' => $caseId, 'url' => $this->url($token)];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, int $caseId): array
    {
        $case = $this->caseFor($actor, $caseId);
        $this->event((int) $case->tenant_id, $caseId, (int) $actor->id, 'hr', 'case_viewed', 'case');
        $student = DB::table('i983_students')->where('case_id', $caseId)->first();
        $employer = DB::table('i983_employers')->where('case_id', $caseId)->first();
        $training = DB::table('i983_training_plans')->where('case_id', $caseId)->first();
        $link = DB::table('i983_links')->where('case_id', $caseId)->orderByDesc('id')->first();

        return [
            'case' => $this->caseArray($case),
            'student' => $this->studentArray($student),
            'employer' => $this->employerArray($employer),
            'link' => [
                'active' => $link !== null && $link->revoked_at === null && $link->expires_at > now(),
                'expiresAt' => $link === null ? '' : substr((string) $link->expires_at, 0, 16),
                'revoked' => $link !== null && $link->revoked_at !== null,
            ],
            'training' => $this->presentTraining($training, $student, $employer),
            'revisions' => $this->revisions($caseId),
            'correction' => $this->openCorrection($caseId),
            'suggestion' => $this->companySuggestion((int) $case->tenant_id),
            'documents' => $this->documents->list((int) $case->tenant_id, $caseId),
            'signatory' => $this->signatory->present((int) $case->tenant_id),
            'canSign' => $this->canSign($actor),
            'studentCertification' => $this->studentCertifications->present($caseId),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveEmployer(User $actor, int $caseId, array $input, bool $finalize): void
    {
        $case = $this->caseFor($actor, $caseId);
        $this->assertEditable($case);
        $values = $this->employerValues($input);

        if ($finalize && ($values['legal_name'] === null || $values['street'] === null || $values['city'] === null || $values['state'] === null || $values['zip'] === null || $values['ein'] === null || $values['employee_count'] === null || $values['naics'] === null || $values['opt_hours'] === null || $values['salary'] === null || $values['start_date'] === null)) {
            throw ValidationException::withMessages([
                'employer' => 'Complete the employer fields, then review them before finalizing.',
            ]);
        }

        DB::table('i983_employers')->where('case_id', $caseId)->update([
            'legal_name' => $values['legal_name'],
            'street' => $values['street'],
            'suite' => $values['suite'],
            'website' => $values['website'],
            'city' => $values['city'],
            'state' => $values['state'],
            'zip' => $values['zip'],
            'ein_ciphertext' => $this->seal($values['ein']),
            'employee_count' => $values['employee_count'],
            'naics' => $values['naics'],
            'opt_hours' => $values['opt_hours'],
            'hourly_rate' => $values['hourly_rate'],
            'salary' => $values['salary'],
            'salary_frequency' => $values['salary_frequency'],
            'annual_compensation' => $values['annual_compensation'],
            'other_compensation' => $values['other_compensation'],
            'start_date' => $values['start_date'],
            'finalized_at' => $finalize ? now() : null,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
        $this->syncDerivedName((int) $case->tenant_id, $caseId, 'employer_name', (string) ($values['legal_name'] ?? ''), 'hr', (int) $actor->id);
        $this->event((int) $case->tenant_id, $caseId, (int) $actor->id, 'hr', $finalize ? 'employer_finalized' : 'employer_saved', 'employer');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveTraining(User $actor, int $caseId, array $input): void
    {
        $case = $this->caseFor($actor, $caseId);
        $this->assertEditable($case);
        $this->protectTraining('hr', $case, $input);
        $this->writeTraining((int) $case->tenant_id, $caseId, $input, 'hr', (int) $actor->id, [...self::EMPLOYER_TRAINING_FIELDS, 'student_role', 'goals']);
        $this->event((int) $case->tenant_id, $caseId, (int) $actor->id, 'hr', 'training_saved', 'training');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function requestCorrection(User $actor, int $caseId, array $input): void
    {
        $case = $this->caseFor($actor, $caseId);
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($case->student_submitted_at === null) {
            throw ValidationException::withMessages(['reason' => 'The student has not submitted Section 1 yet.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason for the correction.']);
        }

        if (DB::table('i983_corrections')->where('case_id', $caseId)->whereNull('resolved_at')->exists()) {
            throw ValidationException::withMessages(['reason' => 'A correction request is already open.']);
        }

        DB::table('i983_corrections')->insert([
            'tenant_id' => $case->tenant_id,
            'case_id' => $caseId,
            'reason' => mb_substr($reason, 0, 2000),
            'requested_by' => $actor->id,
            'requested_at' => now(),
        ]);
        DB::table('i983_cases')->where('id', $caseId)->update([
            'status' => 'correction_requested',
            'updated_at' => now(),
        ]);
        $this->event((int) $case->tenant_id, $caseId, (int) $actor->id, 'hr', 'correction_requested', 'student_fields');
    }

    public function revoke(User $actor, int $caseId): void
    {
        $case = $this->caseFor($actor, $caseId);
        DB::table('i983_links')->where('case_id', $caseId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->event((int) $case->tenant_id, $caseId, (int) $actor->id, 'hr', 'link_revoked', 'student_link');
    }

    public function generateStudentSnapshot(User $actor, int $caseId): void
    {
        $case = $this->caseFor($actor, $caseId);
        $this->assertEditable($case);
        $this->documents->studentSnapshot((int) $case->id, (int) $actor->id);
    }

    public function generateWorkingCopy(User $actor, int $caseId): void
    {
        $case = $this->caseFor($actor, $caseId);
        $this->assertEditable($case);
        $this->documents->workingCopy((int) $case->id, (int) $actor->id);
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function documentFile(User $actor, int $caseId, int $documentId): array
    {
        $case = $this->caseFor($actor, $caseId);

        return $this->documents->file((int) $case->tenant_id, (int) $case->id, $documentId);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function applySignature(User $actor, int $caseId, array $input): void
    {
        abort_unless($this->canSign($actor), 403);
        $case = $this->caseFor($actor, $caseId);
        $this->assertEditable($case);

        if (! $this->confirmed($input)) {
            throw ValidationException::withMessages([
                'confirm' => 'Confirm that this official is authorized to sign this I-983.',
            ]);
        }

        $profile = $this->signatory->ready((int) $case->tenant_id);
        $organization = trim((string) DB::table('i983_employers')->where('case_id', $caseId)->value('legal_name'));

        if ($organization === '') {
            throw ValidationException::withMessages([
                'form' => 'Enter the employer legal name before applying the signature.',
            ]);
        }

        $this->documents->sign($case, (int) $actor->id, $profile['path'], $profile['name'], $profile['title'], $organization);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function finalizeDocument(User $actor, int $caseId, array $input): void
    {
        abort_unless($this->canSign($actor), 403);
        $case = $this->caseFor($actor, $caseId);
        $this->assertEditable($case);

        if (! $this->confirmed($input)) {
            throw ValidationException::withMessages([
                'confirm' => 'Confirm that this five-page I-983 is ready to finalize.',
            ]);
        }

        $this->documents->finalize($case, (int) $actor->id);
        DB::table('i983_cases')->where('id', $caseId)->where('tenant_id', $case->tenant_id)->update([
            'status' => 'finalized',
            'updated_at' => now(),
        ]);
    }

    public function canSign(User $actor): bool
    {
        return (bool) (CompanyRole::permissions($actor->applicationRole())['i983Sign'] ?? false);
    }

    public function reissue(User $actor, int $caseId): string
    {
        $case = $this->caseFor($actor, $caseId);
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($actor, $case, $caseId, $token): void {
            DB::table('i983_links')->where('case_id', $caseId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->storeLink((int) $case->tenant_id, $caseId, (int) $actor->id, $token, now());
            $this->event((int) $case->tenant_id, $caseId, (int) $actor->id, 'hr', 'link_issued', 'student_link');
        });

        return $this->url($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publicPage(string $token): ?array
    {
        $link = $this->link($token);

        if ($link === null) {
            return null;
        }

        $case = DB::table('i983_cases')->where('id', $link->case_id)->where('tenant_id', $link->tenant_id)->first();

        if ($case === null) {
            return null;
        }

        $student = DB::table('i983_students')->where('case_id', $link->case_id)->first();
        $employer = DB::table('i983_employers')->where('case_id', $link->case_id)->first();
        $training = DB::table('i983_training_plans')->where('case_id', $link->case_id)->first();
        $correction = $this->openCorrection((int) $case->id);

        return [
            'reference' => (string) $case->reference,
            'status' => (string) $case->status,
            'expiresAt' => substr((string) $link->expires_at, 0, 16),
            'locked' => $this->sectionLocked($case),
            'finalizedAt' => $this->finalizedOn($case),
            'correctionReason' => $correction['reason'] ?? '',
            'student' => $this->studentArray($student),
            'training' => $this->presentTraining($training, $student, $employer),
            'employer' => $this->employerArray($employer),
            'employerCertification' => $this->presentEmployerCertification((int) $case->id),
            'evaluations' => $this->presentEvaluations((int) $case->id),
            'goalHistory' => $this->revisions((int) $case->id, 'goals'),
            'statements' => I983StudentCertifications::STATEMENTS,
            'timezone' => $this->studentCertifications->timezoneFor((int) $case->tenant_id),
            'localDate' => $this->studentCertifications->localDate((int) $case->tenant_id),
            'certification' => $this->studentCertifications->present((int) $case->id),
        ];
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function studentFinal(string $token): array
    {
        $link = $this->link($token);
        abort_if($link === null, 404);
        $case = DB::table('i983_cases')->where('id', $link->case_id)->where('tenant_id', $link->tenant_id)->first();
        abort_if($case === null || $case->status !== 'finalized', 404);

        return $this->documents->finalSignedFile($case);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveStudent(string $token, array $input, bool $submit): void
    {
        $link = $this->link($token);
        abort_if($link === null, 404);
        $case = DB::table('i983_cases')->where('id', $link->case_id)->first();
        $this->assertEditable($case);
        $locked = $this->sectionLocked($case);

        if ($submit && $locked) {
            throw ValidationException::withMessages(['student' => 'This information has already been submitted.']);
        }

        DB::transaction(function () use ($case, $link, $input, $submit): void {
            $current = DB::table('i983_cases')->where('id', $case->id)->lockForUpdate()->first();
            $locked = $this->sectionLocked($current);

            if ($submit && $locked) {
                throw ValidationException::withMessages(['student' => 'This information has already been submitted.']);
            }

            if (! $locked) {
                $values = $this->studentValues($input, $submit);
                $sevis = $values['sevis_id'];
                $ead = $values['ead'];
                unset($values['sevis_id'], $values['ead']);
                DB::table('i983_students')->where('case_id', $current->id)->update([
                    ...$values,
                    'sevis_id_ciphertext' => $this->seal($sevis),
                    'ead_ciphertext' => $this->seal($ead),
                    'updated_at' => now(),
                ]);
                $this->syncDerivedName((int) $current->tenant_id, (int) $current->id, 'student_name', $this->printedStudentName((object) $values), 'student', null);
            }

            $this->protectTraining('student', $current, $input);
            $this->writeTraining((int) $current->tenant_id, (int) $current->id, $input, 'student', null, $locked ? ['goals'] : ['student_role', 'goals']);

            if ($submit && ! $locked) {
                $this->documents->assertStudentFormFits((int) $current->id);
                $saved = DB::table('i983_students')->where('case_id', $current->id)->first();
                $certification = $this->studentCertifications->record(
                    $current,
                    $link,
                    $this->certifiedPrintedName($saved),
                    trim((string) ($saved->email ?? '')),
                    $input,
                );

                try {
                    $resubmit = $current->status === 'correction_requested';
                    DB::table('i983_cases')->where('id', $current->id)->update([
                        'status' => $resubmit ? 'resubmitted' : 'student_submitted',
                        'student_submitted_at' => $current->student_submitted_at ?? now(),
                        'updated_at' => now(),
                    ]);
                    if ($resubmit) {
                        DB::table('i983_corrections')->where('case_id', $current->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
                    }
                    $this->event((int) $current->tenant_id, (int) $current->id, null, 'student', 'student_certified', 'v'.$certification['version'].' link '.$link->id);
                    $this->event((int) $current->tenant_id, (int) $current->id, null, 'student', $resubmit ? 'student_resubmitted' : 'student_submitted', 'section_1');
                    $this->documents->studentSnapshot((int) $current->id, null);
                } catch (\Throwable $exception) {
                    Storage::disk('local')->delete($certification['path']);

                    throw $exception;
                }
            } else {
                $this->event((int) $current->tenant_id, (int) $current->id, null, 'student', $locked ? 'training_saved' : 'student_draft_saved', $locked ? 'training' : 'section_1');
            }
        });
    }

    private function assertManager(User $actor): void
    {
        abort_unless($actor->companyId() !== null && $this->manages($actor), 403);
        abort_unless(Schema::hasTable('i983_cases'), 404);
    }

    private function caseFor(User $actor, int $caseId): object
    {
        $this->assertManager($actor);
        $case = DB::table('i983_cases')->where('tenant_id', $actor->companyId())->where('id', $caseId)->first();
        abort_if($case === null, 404);

        return $case;
    }

    private function link(string $token): ?object
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token) || ! Schema::hasTable('i983_links')) {
            return null;
        }

        $link = DB::table('i983_links')->where('token_hash', hash('sha256', $token))->first();

        if ($link === null || $link->revoked_at !== null || $link->expires_at <= now()) {
            return null;
        }

        return $link;
    }

    private function storeLink(int $companyId, int $caseId, int $actorId, string $token, mixed $now): void
    {
        DB::table('i983_links')->insert([
            'tenant_id' => $companyId,
            'case_id' => $caseId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(14),
            'created_by' => $actorId,
            'created_at' => $now,
        ]);
    }

    private function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/i983/s/'.$token;
    }

    private function reference(int $companyId): string
    {
        do {
            $reference = 'I983-'.strtoupper(Str::random(6));
        } while (DB::table('i983_cases')->where('tenant_id', $companyId)->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return array{legal_name: ?string, street: ?string, website: ?string, ein: ?string}
     */
    private function companySuggestion(int $companyId): array
    {
        $empty = ['legal_name' => null, 'street' => null, 'website' => null, 'ein' => null];

        if (! Schema::hasTable('usa_offer_profiles')) {
            return $empty;
        }

        $row = DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->first();

        if ($row === null) {
            return $empty;
        }

        return [
            'legal_name' => $this->blank($row->company_name ?? null),
            'street' => $this->blank($row->address_line ?? null),
            'website' => $this->blank($row->website ?? null),
            'ein' => $this->blank($row->federal_id ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function studentValues(array $input, bool $submit): array
    {
        $values = [
            'surname' => $this->limit($input['surname'] ?? null, 80),
            'given_name' => $this->limit($input['given_name'] ?? null, 80),
            'middle_name' => $this->limit($input['middle_name'] ?? null, 80),
            'email' => $this->email($input['email'] ?? null),
            'school_recommending' => $this->limit($input['school_recommending'] ?? null, 191),
            'school_degree' => $this->limit($input['school_degree'] ?? null, 191),
            'sevis_school_code' => $this->limit($input['sevis_school_code'] ?? null, 20),
            'dso_name' => $this->limit($input['dso_name'] ?? null, 120),
            'dso_email' => $this->email($input['dso_email'] ?? null, 'dso_email'),
            'dso_phone' => $this->limit($input['dso_phone'] ?? null, 40),
            'sevis_id' => $this->limit($input['sevis_id'] ?? null, 20),
            'opt_start' => $this->date($input['opt_start'] ?? null),
            'opt_end' => $this->date($input['opt_end'] ?? null),
            'major_cip' => $this->limit($input['major_cip'] ?? null, 160),
            'degree_level' => $this->choice($input['degree_level'] ?? null, ['Bachelor', 'Master', 'Doctorate'], 'degree_level'),
            'degree_awarded_on' => $this->date($input['degree_awarded_on'] ?? null),
            'prior_degree' => $this->yesNo($input['prior_degree'] ?? null),
            'ead' => $this->limit($input['ead'] ?? null, 20),
        ];

        if ($values['opt_start'] !== null && $values['opt_end'] !== null && $values['opt_end'] < $values['opt_start']) {
            throw ValidationException::withMessages(['opt_end' => 'The STEM OPT end date must be on or after the start date.']);
        }

        if ($submit) {
            foreach (['surname', 'given_name', 'email', 'school_recommending', 'school_degree', 'sevis_school_code', 'dso_name', 'dso_email', 'dso_phone', 'sevis_id', 'opt_start', 'opt_end', 'major_cip', 'degree_level', 'degree_awarded_on', 'ead'] as $field) {
                if ($values[$field] === null || $values[$field] === '') {
                    throw ValidationException::withMessages([$field => 'Complete this field before submitting.']);
                }
            }

            if ($values['prior_degree'] === null) {
                throw ValidationException::withMessages(['prior_degree' => 'Select whether this is based on a prior degree.']);
            }
        }

        $stored = $values;
        unset($stored['sevis_id'], $stored['ead']);

        return [...$stored, 'sevis_id' => $values['sevis_id'], 'ead' => $values['ead']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function employerValues(array $input): array
    {
        $hours = $this->blank($input['opt_hours'] ?? null);
        $count = $this->blank($input['employee_count'] ?? null);
        $frequency = $this->choice($input['salary_frequency'] ?? null, ['year', 'month', 'hour'], 'salary_frequency');
        $hourly = $this->money($input['hourly_rate'] ?? null, 'hourly_rate');
        $annual = $this->money($input['annual_compensation'] ?? null, 'annual_compensation');
        $legacy = $this->money($input['salary'] ?? null, 'salary');

        if ($annual === null && $legacy !== null && in_array($frequency, [null, 'year'], true)) {
            $annual = $legacy;
        }

        if ($hourly === null && $legacy !== null && $frequency === 'hour') {
            $hourly = $legacy;
        }

        if ($hours !== null && ((int) $hours < 20 || (int) $hours > 80)) {
            throw ValidationException::withMessages(['opt_hours' => 'OPT hours per week must be at least 20.']);
        }

        return [
            'legal_name' => $this->limit($input['legal_name'] ?? null, 191),
            'street' => $this->limit($input['street'] ?? null, 191),
            'suite' => $this->limit($input['suite'] ?? null, 40),
            'website' => $this->limit($input['website'] ?? null, 191),
            'city' => $this->limit($input['city'] ?? null, 80),
            'state' => $this->state($input['state'] ?? null),
            'zip' => $this->limit($input['zip'] ?? null, 10),
            'ein' => $this->limit($input['ein'] ?? null, 20),
            'employee_count' => $count === null ? null : (int) $count,
            'naics' => $this->limit($input['naics'] ?? null, 10),
            'opt_hours' => $hours === null ? null : (int) $hours,
            'hourly_rate' => $hourly,
            'salary' => $annual ?? ($frequency === 'hour' ? null : $legacy),
            'salary_frequency' => $frequency,
            'annual_compensation' => $annual,
            'other_compensation' => $this->limit($input['other_compensation'] ?? null, 1000),
            'start_date' => $this->date($input['start_date'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    /**
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $input
     */
    private function writeTraining(int $companyId, int $caseId, array $input, string $actorType, ?int $actorId, array $allowed): void
    {
        $current = DB::table('i983_training_plans')->where('case_id', $caseId)->first();
        $update = ['updated_at' => now()];

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $next = $this->limit($input[$field], in_array($field, ['student_role', 'goals', 'oversight', 'measures', 'remarks'], true) ? 5000 : 300);
            $previous = $current === null ? null : $this->blank($current->{$field} ?? null);

            if ($field === 'site_address' && $next !== $previous) {
                $this->assertSiteAddressComplete($next);
                $this->assertSiteAddressFits($next);
            }

            if ($field === 'work_arrangement' && $next !== null && ! in_array($next, ['remote', 'on_site', 'hybrid'], true)) {
                throw ValidationException::withMessages([
                    'work_arrangement' => 'Select remote, on-site, or hybrid for the training site.',
                ]);
            }

            if ($field === 'official_email' && $next !== null && ! filter_var($next, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['official_email' => 'Enter a valid email address.']);
            }

            if ($previous === $next) {
                continue;
            }

            $update[$field] = $next;
            DB::table('i983_field_revisions')->insert([
                'tenant_id' => $companyId,
                'case_id' => $caseId,
                'field_name' => $field,
                'previous_value' => $previous,
                'new_value' => $next,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'created_at' => now(),
            ]);
        }

        if (count($update) > 1) {
            DB::table('i983_training_plans')->where('case_id', $caseId)->update($update);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function caseArray(object $case): array
    {
        return [
            'id' => (int) $case->id,
            'reference' => (string) $case->reference,
            'candidateName' => (string) $case->candidate_name,
            'candidateEmail' => (string) $case->candidate_email,
            'status' => (string) $case->status,
            'submittedAt' => $case->student_submitted_at === null ? '' : substr((string) $case->student_submitted_at, 0, 16),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function studentArray(?object $row): array
    {
        if ($row === null) {
            return [];
        }

        return [
            'surname' => (string) ($row->surname ?? ''),
            'givenName' => (string) ($row->given_name ?? ''),
            'middleName' => (string) ($row->middle_name ?? ''),
            'email' => (string) ($row->email ?? ''),
            'schoolRecommending' => (string) ($row->school_recommending ?? ''),
            'schoolDegree' => (string) ($row->school_degree ?? ''),
            'sevisSchoolCode' => (string) ($row->sevis_school_code ?? ''),
            'dsoName' => (string) ($row->dso_name ?? ''),
            'dsoEmail' => (string) ($row->dso_email ?? ''),
            'dsoPhone' => (string) ($row->dso_phone ?? ''),
            'sevisId' => $this->reveal($row->sevis_id_ciphertext ?? null),
            'optStart' => (string) ($row->opt_start ?? ''),
            'optEnd' => (string) ($row->opt_end ?? ''),
            'majorCip' => (string) ($row->major_cip ?? ''),
            'degreeLevel' => (string) ($row->degree_level ?? ''),
            'degreeAwardedOn' => (string) ($row->degree_awarded_on ?? ''),
            'priorDegree' => $row->prior_degree === null ? '' : ((bool) $row->prior_degree ? 'yes' : 'no'),
            'ead' => $this->reveal($row->ead_ciphertext ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employerArray(?object $row): array
    {
        if ($row === null) {
            return [];
        }

        return [
            'legalName' => (string) ($row->legal_name ?? ''),
            'street' => (string) ($row->street ?? ''),
            'suite' => (string) ($row->suite ?? ''),
            'website' => (string) ($row->website ?? ''),
            'city' => (string) ($row->city ?? ''),
            'state' => (string) ($row->state ?? ''),
            'zip' => (string) ($row->zip ?? ''),
            'ein' => $this->reveal($row->ein_ciphertext ?? null),
            'employeeCount' => $row->employee_count === null ? '' : (string) $row->employee_count,
            'naics' => (string) ($row->naics ?? ''),
            'optHours' => $row->opt_hours === null ? '' : (string) $row->opt_hours,
            'hourlyRate' => $this->printedMoney($this->hourlyAmount($row)),
            'salary' => $row->salary === null ? '' : number_format((float) $row->salary, 2, '.', ''),
            'salaryFrequency' => (string) ($row->salary_frequency ?? ''),
            'annualCompensation' => $this->printedMoney($this->annualAmount($row)),
            'otherCompensation' => (string) ($row->other_compensation ?? ''),
            'startDate' => (string) ($row->start_date ?? ''),
            'finalizedAt' => $row->finalized_at === null ? '' : substr((string) $row->finalized_at, 0, 16),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function trainingArray(?object $row): array
    {
        $values = [];

        foreach (self::TRAINING_FIELDS as $field) {
            $values[Str::camel($field)] = $row === null ? '' : (string) ($row->{$field} ?? '');
        }

        return $values;
    }

    public function saveSignatory(User $actor, string $name, string $title, ?UploadedFile $file): void
    {
        abort_unless($actor->companyId() !== null && $this->manages($actor), 403);
        $this->signatory->save($actor, $name, $title, $file);
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function signatoryImage(User $actor): array
    {
        abort_unless($actor->companyId() !== null && $this->manages($actor), 403);

        return $this->signatory->image((int) $actor->companyId());
    }

    private function assertEditable(object $case): void
    {
        if ($case->status === 'finalized') {
            throw ValidationException::withMessages([
                'form' => 'This I-983 is finalized. Request a correction before changing it. The finalized PDF was not changed.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function confirmed(array $input): bool
    {
        return in_array($input['confirm'] ?? null, [true, 1, '1', 'on', 'true'], true);
    }

    private function sectionLocked(object $case): bool
    {
        return $case->student_submitted_at !== null && $case->status !== 'correction_requested';
    }

    private function finalizedOn(object $case): string
    {
        if ($case->status !== 'finalized') {
            return '';
        }

        $created = DB::table('i983_documents')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->where('kind', I983Documents::FINAL_SIGNED)
            ->orderByDesc('id')
            ->value('created_at');

        return $created === null ? '' : Carbon::parse($created)->format('F j, Y');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function protectTraining(string $actor, object $case, array $input): void
    {
        $student = DB::table('i983_students')->where('case_id', $case->id)->first();
        $employer = DB::table('i983_employers')->where('case_id', $case->id)->first();
        $training = DB::table('i983_training_plans')->where('case_id', $case->id)->first();
        $printedStudent = $this->printedStudentName($student);
        $printedEmployer = $this->printedEmployerName($employer);

        $errors = [];

        if ($actor === 'hr') {
            $this->collectDerived($errors, $input, 'student_name', $printedStudent, $this->blank($training->student_name ?? null), 'Student Name comes from Section 1. Request a correction so the student can change it.');
        } else {
            if ($this->sectionLocked($case)) {
                $this->collectLockedSection($errors, $input, $student);
                $this->collectChange($errors, $input, 'student_role', $this->blank($training->student_role ?? null), 5000, 'Student Role is locked after submission. Ask HR to request a correction.');
            }
            foreach (self::EMPLOYER_TRAINING_FIELDS as $field) {
                $max = in_array($field, ['oversight', 'measures', 'remarks'], true) ? 5000 : 300;
                $this->collectChange($errors, $input, $field, $this->blank($training->{$field} ?? null), $max, 'This field is completed by the employer.');
            }
            $this->collectDerived($errors, $input, 'employer_name', $printedEmployer, $this->blank($training->employer_name ?? null), 'Employer Name comes from Section 3.');
            $this->collectDerived($errors, $input, 'student_name', $printedStudent, $this->blank($training->student_name ?? null), 'Student Name comes from Section 1.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $input
     */
    private function collectChange(array &$errors, array $input, string $field, ?string $current, int $max, string $message): void
    {
        if (! array_key_exists($field, $input)) {
            return;
        }

        if ($this->limit($input[$field], $max) !== $current) {
            $errors[$field] = $message;
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $input
     */
    private function collectDerived(array &$errors, array $input, string $field, string $derived, ?string $stored, string $message): void
    {
        if (! array_key_exists($field, $input)) {
            return;
        }

        $next = $this->limit($input[$field], 300);
        $allowed = array_values(array_unique(array_filter([$derived !== '' ? $derived : null, $stored], fn (?string $value): bool => $value !== null && $value !== '')));

        if ($next === null && $allowed === []) {
            return;
        }

        if (! in_array($next, $allowed, true)) {
            $errors[$field] = $message;
        }
    }

    private function syncDerivedName(int $companyId, int $caseId, string $field, string $printed, string $actor, ?int $actorId): void
    {
        $printed = trim($printed);

        if ($printed === '') {
            return;
        }

        $current = DB::table('i983_training_plans')->where('case_id', $caseId)->first();
        $previous = $current === null ? null : $this->blank($current->{$field} ?? null);

        if ($previous === $printed) {
            return;
        }

        DB::table('i983_field_revisions')->insert([
            'tenant_id' => $companyId,
            'case_id' => $caseId,
            'field_name' => $field,
            'previous_value' => $previous,
            'new_value' => $printed,
            'actor_type' => $actor,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
        DB::table('i983_training_plans')->where('case_id', $caseId)->update([
            $field => $printed,
            'updated_at' => now(),
        ]);
    }

    private function printedStudentName(?object $student): string
    {
        $surname = trim((string) ($student->surname ?? ''));
        $given = trim((string) ($student->given_name ?? ''));

        if ($surname === '') {
            return $given;
        }

        if ($given === '') {
            return $surname;
        }

        return $surname.', '.$given;
    }

    private function certifiedPrintedName(?object $student): string
    {
        $surname = trim((string) ($student->surname ?? ''));
        $given = trim((string) ($student->given_name ?? ''));
        $middle = trim((string) ($student->middle_name ?? ''));
        $rest = trim($given.($middle === '' ? '' : ' '.$middle));

        if ($surname === '') {
            return $rest;
        }

        if ($rest === '') {
            return $surname;
        }

        return $surname.', '.$rest;
    }

    private function printedEmployerName(?object $employer): string
    {
        return trim((string) ($employer->legal_name ?? ''));
    }

    /**
     * @return array<string, string>
     */
    private function presentTraining(?object $training, ?object $student, ?object $employer): array
    {
        $values = $this->trainingArray($training);
        $printedStudent = $this->printedStudentName($student);
        $printedEmployer = $this->printedEmployerName($employer);

        if ($printedStudent !== '') {
            $values['studentName'] = $printedStudent;
        }

        if ($printedEmployer !== '') {
            $values['employerName'] = $printedEmployer;
        }

        return $values;
    }

    /**
     * @return array{signed: bool, printedName: string, title: string, certifiedOn: string}
     */
    private function presentEmployerCertification(int $caseId): array
    {
        $empty = ['signed' => false, 'printedName' => '', 'title' => '', 'certifiedOn' => ''];

        if (! Schema::hasTable('i983_certifications')) {
            return $empty;
        }

        $row = DB::table('i983_certifications')->where('case_id', $caseId)->where('party', 'employer')->first();

        if ($row === null || ! is_string($row->signature_path) || $row->signature_path === '') {
            return $empty;
        }

        return [
            'signed' => true,
            'printedName' => (string) ($row->printed_name ?? ''),
            'title' => (string) ($row->title ?? ''),
            'certifiedOn' => $this->displayDate($row->certified_on ?? null),
        ];
    }

    /**
     * Evaluation signature files stay private. The student page receives only the entered text and dates.
     *
     * @return list<array{kind: string, from: string, to: string, narrative: string, studentSignedOn: string, employerSignedOn: string, studentSigned: bool, employerSigned: bool}>
     */
    private function presentEvaluations(int $caseId): array
    {
        if (! Schema::hasTable('i983_evaluations')) {
            return [];
        }

        return DB::table('i983_evaluations')->where('case_id', $caseId)->orderBy('id')->get()->map(function (object $row): array {
            return [
                'kind' => (string) $row->kind,
                'from' => $this->displayDate($row->date_from ?? null),
                'to' => $this->displayDate($row->date_to ?? null),
                'narrative' => (string) ($row->narrative ?? ''),
                'studentSignedOn' => $this->displayDate($row->student_signed_on ?? null),
                'employerSignedOn' => $this->displayDate($row->employer_signed_on ?? null),
                'studentSigned' => is_string($row->student_signature_path) && $row->student_signature_path !== '',
                'employerSigned' => is_string($row->employer_signature_path) && $row->employer_signature_path !== '',
            ];
        })->all();
    }

    private function displayDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse($value)->format('m-d-Y');
    }

    /**
     * @return list<array<string, string>>
     */
    private function revisions(int $caseId, ?string $field = null): array
    {
        $query = DB::table('i983_field_revisions')->where('case_id', $caseId)->orderByDesc('id');

        if ($field !== null) {
            $query->where('field_name', $field);
        }

        $rows = $query->limit(80)->get();
        $names = $rows->pluck('actor_id')->filter()->isEmpty()
            ? collect()
            : DB::table('users')->whereIn('id', $rows->pluck('actor_id')->filter()->unique()->all())->pluck('name', 'id');

        return $rows->map(function (object $row) use ($names): array {
            $actor = (string) $row->actor_type;
            $name = $row->actor_id === null ? '' : (string) ($names[$row->actor_id] ?? '');

            return [
                'field' => (string) $row->field_name,
                'actor' => $actor,
                'editor' => $name !== '' ? $name : ($actor === 'student' ? 'Student' : 'HR'),
                'previous' => (string) ($row->previous_value ?? ''),
                'next' => (string) ($row->new_value ?? ''),
                'at' => substr((string) $row->created_at, 0, 16),
            ];
        })->all();
    }

    /**
     * @return array{reason: string, requestedAt: string}|null
     */
    private function openCorrection(int $caseId): ?array
    {
        if (! Schema::hasTable('i983_corrections')) {
            return null;
        }

        $row = DB::table('i983_corrections')->where('case_id', $caseId)->whereNull('resolved_at')->orderByDesc('id')->first();

        if ($row === null) {
            return null;
        }

        return [
            'reason' => (string) $row->reason,
            'requestedAt' => substr((string) $row->requested_at, 0, 16),
        ];
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $input
     */
    private function collectLockedSection(array &$errors, array $input, ?object $student): void
    {
        if ($student === null) {
            return;
        }

        $message = 'Section 1 is locked after submission. Ask HR to request a correction.';
        $current = [
            'surname' => $this->blank($student->surname ?? null),
            'given_name' => $this->blank($student->given_name ?? null),
            'middle_name' => $this->blank($student->middle_name ?? null),
            'email' => $this->blank($student->email ?? null),
            'school_recommending' => $this->blank($student->school_recommending ?? null),
            'school_degree' => $this->blank($student->school_degree ?? null),
            'sevis_school_code' => $this->blank($student->sevis_school_code ?? null),
            'dso_name' => $this->blank($student->dso_name ?? null),
            'dso_email' => $this->blank($student->dso_email ?? null),
            'dso_phone' => $this->blank($student->dso_phone ?? null),
            'sevis_id' => $this->blank($this->reveal($student->sevis_id_ciphertext ?? null)),
            'opt_start' => $this->storedDate($student->opt_start ?? null),
            'opt_end' => $this->storedDate($student->opt_end ?? null),
            'major_cip' => $this->blank($student->major_cip ?? null),
            'degree_level' => $this->blank($student->degree_level ?? null),
            'degree_awarded_on' => $this->storedDate($student->degree_awarded_on ?? null),
            'prior_degree' => $student->prior_degree === null ? null : ((bool) $student->prior_degree ? 'yes' : 'no'),
            'ead' => $this->blank($this->reveal($student->ead_ciphertext ?? null)),
        ];

        foreach ($current as $field => $value) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $next = in_array($field, ['email', 'dso_email'], true)
                ? $this->blank(strtolower((string) $this->limit($input[$field], 191)))
                : (in_array($field, ['opt_start', 'opt_end', 'degree_awarded_on'], true)
                    ? $this->storedDate($input[$field])
                    : $this->limit($input[$field], 191));

            if ($next !== $value) {
                $errors[$field] = $message;
            }
        }
    }

    private function storedDate(mixed $value): ?string
    {
        $text = $this->blank($value);

        return $text === null ? null : substr($text, 0, 10);
    }

    private function assertSiteAddressComplete(?string $address): void
    {
        if ($address === null || $address === '') {
            return;
        }

        if (preg_match('/^(.+),\s*([^,]+),\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/', $address) === 1) {
            return;
        }

        throw ValidationException::withMessages([
            'site_address' => 'Enter the training site as street, city, state, and ZIP. This address can differ from the employer\'s registered address.',
        ]);
    }

    private function assertSiteAddressFits(?string $address): void
    {
        if ($address === null) {
            return;
        }

        $pdf = new \FPDF('P', 'pt', [612, 792]);
        $pdf->SetFont('Helvetica', '', 8);

        if ($pdf->GetStringWidth($address) > self::SITE_ADDRESS_WIDTH) {
            throw ValidationException::withMessages([
                'site_address' => 'The site address does not fit on the one-line Site Address field. Shorten the street, city, state, and ZIP.',
            ]);
        }
    }

    private function event(int $companyId, ?int $caseId, ?int $actorId, string $actorType, string $action, ?string $subject): void
    {
        DB::table('i983_events')->insert([
            'tenant_id' => $companyId,
            'case_id' => $caseId,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'action' => $action,
            'subject' => $subject,
            'created_at' => now(),
        ]);
    }

    private function seal(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString($value);
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

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function money(mixed $value, string $field): ?string
    {
        $text = str_replace(',', '', (string) $this->blank($value));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 || (float) $text <= 0) {
            throw ValidationException::withMessages([
                $field => 'Enter a U.S. dollar amount greater than zero.',
            ]);
        }

        return number_format((float) $text, 2, '.', '');
    }

    private function hourlyAmount(object $row): mixed
    {
        $stored = property_exists($row, 'hourly_rate') ? $row->hourly_rate : null;

        if ($stored !== null) {
            return $stored;
        }

        return (string) ($row->salary_frequency ?? '') === 'hour' ? $row->salary : null;
    }

    private function annualAmount(object $row): mixed
    {
        $stored = property_exists($row, 'annual_compensation') ? $row->annual_compensation : null;

        if ($stored !== null) {
            return $stored;
        }

        $frequency = (string) ($row->salary_frequency ?? '');

        return in_array($frequency, ['hour', 'month'], true) ? null : $row->salary;
    }

    private function printedMoney(mixed $value): string
    {
        if ($value === null || $value === '' || (float) $value <= 0) {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function limit(mixed $value, int $max): ?string
    {
        $text = $this->blank($value);

        return $text === null ? null : mb_substr($text, 0, $max);
    }

    private function email(mixed $value, string $field = 'email'): ?string
    {
        $text = strtolower((string) $this->limit($value, 191));

        if ($text === '') {
            return null;
        }

        if (! filter_var($text, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([$field => 'Enter a valid email address.']);
        }

        return $text;
    }

    private function date(mixed $value): ?string
    {
        $text = $this->blank($value);

        if ($text === null) {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            throw ValidationException::withMessages(['date' => 'Enter a valid date.']);
        }

        return $text;
    }

    private function state(mixed $value): ?string
    {
        $text = strtoupper((string) $this->limit($value, 2));

        if ($text === '') {
            return null;
        }

        if (! preg_match('/^[A-Z]{2}$/', $text)) {
            throw ValidationException::withMessages(['state' => 'Enter the two-letter state code.']);
        }

        return $text;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(mixed $value, array $allowed, string $field): ?string
    {
        $text = (string) $this->blank($value);

        if ($text === '') {
            return null;
        }

        if (! in_array($text, $allowed, true)) {
            throw ValidationException::withMessages([$field => 'Choose one of the listed values.']);
        }

        return $text;
    }

    private function yesNo(mixed $value): ?bool
    {
        $text = (string) $this->blank($value);

        if ($text === '') {
            return null;
        }

        if (! in_array($text, ['yes', 'no'], true)) {
            throw ValidationException::withMessages(['prior_degree' => 'Select yes or no.']);
        }

        return $text === 'yes';
    }
}
