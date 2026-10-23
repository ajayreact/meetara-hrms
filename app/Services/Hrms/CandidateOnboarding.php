<?php

namespace App\Services\Hrms;

use App\Contracts\CaptchaVerifier;
use App\Contracts\MalwareScanner;
use App\Models\User;
use App\Support\CandidateForm;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CandidateOnboarding
{
    public function __construct(
        private readonly MalwareScanner $scanner,
        private readonly CaptchaVerifier $captcha,
    ) {}

    public function ready(): bool
    {
        return Schema::hasTable('candidate_onboarding_forms')
            && Schema::hasTable('candidate_submissions')
            && Schema::hasTable('candidate_submission_documents')
            && Schema::hasTable('candidate_submission_operations')
            && Schema::hasTable('candidate_onboarding_events');
    }

    /**
     * @return array{ready: bool, enabled: bool, publicUrl: ?string, notice: string}
     */
    public function settings(User $actor): array
    {
        $companyId = $actor->companyId();
        $row = $this->ready() && $companyId !== null
            ? DB::table('candidate_onboarding_forms')->where('tenant_id', $companyId)->first()
            : null;
        $enabled = $row !== null && (bool) $row->enabled;

        $timeoutMinutes = (int) ceil(((int) config('candidate.password_timeout', 900)) / 60);

        return [
            'ready' => $this->ready(),
            'enabled' => $enabled,
            'canEnable' => $this->protectionsReady(),
            'publicUrl' => $enabled ? $this->publicUrl((string) $row->public_token) : null,
            'notice' => $this->settingsNotice($timeoutMinutes),
        ];
    }

    public function save(User $actor, bool $enabled, bool $confirmDisable): void
    {
        abort_unless($actor->applicationRole() === 'company_admin' && $actor->companyId() !== null, 403);
        abort_unless($this->ready(), 404);

        if ($enabled && ! $this->protectionsReady()) {
            throw ValidationException::withMessages([
                'enabled' => 'The public form stays disabled until malware scanning and the verification check are configured.',
            ]);
        }

        $companyId = (int) $actor->companyId();
        $existing = DB::table('candidate_onboarding_forms')->where('tenant_id', $companyId)->first();

        if ($existing !== null && (bool) $existing->enabled && ! $enabled && ! $confirmDisable) {
            throw ValidationException::withMessages([
                'confirm_disable' => 'Confirm that new submissions should stop. Existing submissions are kept.',
            ]);
        }

        $now = now();
        if ($existing === null) {
            DB::table('candidate_onboarding_forms')->insert([
                'tenant_id' => $companyId,
                'public_token' => bin2hex(random_bytes(32)),
                'enabled' => $enabled,
                'notice_version' => CandidateForm::NOTICE_VERSION,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('candidate_onboarding_forms')->where('id', $existing->id)->update([
                'enabled' => $enabled,
                'notice_version' => CandidateForm::NOTICE_VERSION,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ]);
        }

        if ($existing === null || (bool) $existing->enabled !== $enabled) {
            $this->event($companyId, null, (int) $actor->id, $enabled ? 'form_enabled' : 'form_disabled', null);
        }
    }

    public function rotateToken(User $actor, bool $confirm): void
    {
        abort_unless($actor->applicationRole() === 'company_admin' && $actor->companyId() !== null, 403);
        abort_unless($this->ready(), 404);

        if (! $confirm) {
            throw ValidationException::withMessages([
                'confirm_rotate' => 'Confirm that the current public link should stop working. Existing submissions are kept.',
            ]);
        }

        $companyId = (int) $actor->companyId();
        $existing = DB::table('candidate_onboarding_forms')->where('tenant_id', $companyId)->first();
        abort_if($existing === null, 404);

        DB::table('candidate_onboarding_forms')->where('id', $existing->id)->update([
            'public_token' => bin2hex(random_bytes(32)),
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
        $this->event($companyId, null, (int) $actor->id, 'form_token_rotated', null);
    }

    public function publicUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/apply/'.$token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publicPage(string $token): ?array
    {
        $form = $this->formByToken($token);

        if ($form === null) {
            return null;
        }

        $open = (bool) $form->enabled && $this->tenantIsOpen((int) $form->tenant_id) && $this->protectionsReady();

        return [
            'open' => $open,
            'companyName' => $open ? (string) DB::table('tenants')->where('id', $form->tenant_id)->value('name') : null,
            'notice' => CandidateForm::notice(),
            'noticeVersion' => CandidateForm::NOTICE_VERSION,
            'captcha' => [
                'required' => $open && $this->captcha->required(),
                'siteKey' => $open ? $this->captcha->siteKey() : null,
            ],
            'documents' => collect(CandidateForm::DOCUMENTS)->map(fn (array $document, string $category): array => [
                'name' => CandidateForm::inputName($category),
                'label' => $document['label'],
                'hint' => $document['hint'],
                'required' => $document['required'],
            ])->values()->all(),
            'accept' => 'PDF, JPEG, PNG, or WEBP. Maximum 5 MB each.',
        ];
    }

    public function submit(string $token, Request $request): string
    {
        $form = $this->formByToken($token);
        abort_if($form === null, 404);

        if (! (bool) $form->enabled || ! $this->tenantIsOpen((int) $form->tenant_id)) {
            throw ValidationException::withMessages([
                'form' => 'This form is not accepting submissions.',
            ]);
        }

        if ($request->filled('website')) {
            throw ValidationException::withMessages([
                'form' => 'The form could not be submitted.',
            ]);
        }

        if (! $this->protectionsReady()) {
            throw ValidationException::withMessages([
                'form' => 'This form is not accepting submissions.',
            ]);
        }

        $this->assertCaptcha($request);

        $validated = validator($request->all(), CandidateForm::rules(), CandidateForm::messages())->validate();

        $digits = CandidateForm::ssnDigits((string) $validated['ssn']);
        $account = CandidateForm::accountDigits((string) $validated['account_number']);
        $routing = CandidateForm::routingDigits((string) $validated['routing_number']);
        $bankName = $this->text($validated['bank_name'], 80);

        if ($digits === null) {
            throw ValidationException::withMessages([
                'ssn' => 'Enter a 9-digit Social Security number.',
            ]);
        }

        if ($bankName === '') {
            throw ValidationException::withMessages([
                'bank_name' => 'Enter the bank name.',
            ]);
        }

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_number' => 'Enter an account number of 4 to 17 digits.',
            ]);
        }

        if ($routing === null) {
            throw ValidationException::withMessages([
                'routing_number' => 'Enter a 9-digit routing number.',
            ]);
        }

        $stored = [];

        DB::beginTransaction();

        try {
            $locked = DB::table('candidate_onboarding_forms')->where('id', $form->id)->lockForUpdate()->first();

            if ($locked === null || ! (bool) $locked->enabled || ! $this->tenantIsOpen((int) $locked->tenant_id)) {
                throw ValidationException::withMessages([
                    'form' => 'This form is not accepting submissions.',
                ]);
            }

            $email = strtolower(trim((string) $validated['email']));
            $hash = $this->emailHash($email);
            $duplicate = DB::table('candidate_submissions')
                ->where('tenant_id', $locked->tenant_id)
                ->where('email_hash', $hash)
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'form' => 'The form could not be submitted.',
                ]);
            }

            $stored = $this->storeFiles((int) $locked->tenant_id, $request);
            $reference = $this->reference();
            $now = now();
            $phone = $this->text($validated['phone'], 30);
            $address = $this->text($validated['current_address'], 500);
            $i94 = strtoupper($this->text($validated['i94_number'], 40));
            $ead = strtoupper($this->text($validated['ead_number'], 40));
            $submissionId = DB::table('candidate_submissions')->insertGetId([
                'tenant_id' => $locked->tenant_id,
                'form_id' => $locked->id,
                'reference' => $reference,
                'status' => 'pending_review',
                'first_name' => $this->text($validated['first_name'], 80),
                'last_name' => $this->text($validated['last_name'], 80),
                'gender' => $this->text($validated['gender'], 40),
                'email' => $email,
                'email_hash' => $hash,
                'phone_ciphertext' => Crypt::encryptString($phone),
                'phone_last4' => $this->revealableSuffix($this->digits($phone)),
                'date_of_birth_ciphertext' => Crypt::encryptString((string) $validated['date_of_birth']),
                'address_ciphertext' => Crypt::encryptString($address),
                'university_name' => $this->text($validated['university_name'], 191),
                'job_title' => $this->text($validated['job_title'], 120),
                'employment_type' => $this->text($validated['employment_type'], 80),
                'work_location' => $this->text($validated['work_location'], 120),
                'hourly_rate' => number_format((float) $validated['hourly_rate'], 2, '.', ''),
                'hours_per_week' => (int) $validated['hours_per_week'],
                'start_date' => $validated['start_date'],
                'experience' => $this->text($validated['experience'], 80),
                'responsibility_1' => $this->text($validated['responsibility_1'], 1000),
                'responsibility_2' => $this->nullableText($validated['responsibility_2'] ?? null, 1000),
                'responsibility_3' => $this->nullableText($validated['responsibility_3'] ?? null, 1000),
                'responsibility_4' => $this->nullableText($validated['responsibility_4'] ?? null, 1000),
                'responsibility_5' => $this->nullableText($validated['responsibility_5'] ?? null, 1000),
                'responsibility_6' => $this->nullableText($validated['responsibility_6'] ?? null, 1000),
                'bank_name_ciphertext' => Crypt::encryptString($bankName),
                'account_ciphertext' => Crypt::encryptString($account),
                'account_last4' => substr($account, -4),
                'routing_ciphertext' => Crypt::encryptString($routing),
                'routing_last4' => substr($routing, -4),
                'work_authorization' => $this->text($validated['work_authorization'], 80),
                'i94_ciphertext' => Crypt::encryptString($i94),
                'i94_last4' => $this->revealableSuffix($i94),
                'ead_ciphertext' => Crypt::encryptString($ead),
                'ead_last4' => $this->revealableSuffix($ead),
                'work_authorization_expires_on' => $validated['work_authorization_expires_on'],
                'ssn_ciphertext' => Crypt::encryptString($digits),
                'ssn_last4' => substr($digits, -4),
                'student_role' => $this->text($validated['student_role'], 5000),
                'goals' => $this->text($validated['goals'], 5000),
                'oversight' => $this->text($validated['oversight'], 5000),
                'evaluation_methods' => $this->text($validated['evaluation_methods'], 5000),
                'notice_version' => CandidateForm::NOTICE_VERSION,
                'consented_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $stored = $this->relocate($stored, (int) $locked->tenant_id, $submissionId);

            foreach ($stored as $document) {
                DB::table('candidate_submission_documents')->insert([
                    'tenant_id' => $locked->tenant_id,
                    'submission_id' => $submissionId,
                    'category' => $document['category'],
                    'file_path' => $document['path'],
                    'original_name' => $document['name'],
                    'mime' => $document['mime'],
                    'size' => $document['size'],
                    'created_at' => $now,
                ]);
            }

            $this->event((int) $locked->tenant_id, $submissionId, null, 'submission_received', $reference);
            DB::commit();

            return $reference;
        } catch (ValidationException $exception) {
            DB::rollBack();
            $this->deleteStored($stored);

            throw $exception;
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->deleteStored($stored);
            Log::warning('Candidate submission could not be saved.', [
                'tenant_id' => (int) $form->tenant_id,
                'reason' => 'submit_failed',
            ]);

            throw ValidationException::withMessages([
                'form' => 'The form could not be submitted.',
            ]);
        }
    }

    /**
     * @param  array{search?: string, status?: string, from?: string, to?: string, page?: int}  $filters
     * @return array<string, mixed>
     */
    public function list(User $actor, array $filters): array
    {
        $this->assertReviewer($actor);
        $companyId = (int) $actor->companyId();
        $query = DB::table('candidate_submissions')->where('tenant_id', $companyId);
        $search = trim(substr((string) ($filters['search'] ?? ''), 0, 80));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('reference', 'like', $like);
            });
        }

        $status = (string) ($filters['status'] ?? '');

        if (array_key_exists($status, CandidateForm::STATUSES)) {
            $query->where('status', $status);
        }

        foreach (['from', 'to'] as $bound) {
            $value = (string) ($filters[$bound] ?? '');

            if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $query->whereDate('created_at', $bound === 'from' ? '>=' : '<=', $value);
            }
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, 15)->get([
            'id', 'reference', 'first_name', 'last_name', 'email', 'status', 'job_title', 'created_at',
        ]);

        return [
            'rows' => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'reference' => (string) $row->reference,
                'name' => $row->first_name.' '.$row->last_name,
                'email' => (string) $row->email,
                'jobTitle' => (string) $row->job_title,
                'status' => CandidateForm::STATUSES[$row->status] ?? 'Pending Review',
                'submittedAt' => (string) $row->created_at,
            ])->all(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / 15)),
            'total' => $total,
            'statuses' => CandidateForm::STATUSES,
            'enabled' => (bool) DB::table('candidate_onboarding_forms')->where('tenant_id', $companyId)->value('enabled'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(User $actor, int $submissionId): array
    {
        $row = $this->submission($actor, $submissionId);
        $documents = DB::table('candidate_submission_documents')
            ->where('tenant_id', $row->tenant_id)
            ->where('submission_id', $row->id)
            ->orderBy('id')
            ->get();
        $present = $documents->pluck('category')->all();
        $missing = array_values(array_filter(
            CandidateForm::requiredDocuments(),
            fn (string $category): bool => ! in_array($category, $present, true),
        ));
        $operations = DB::table('candidate_submission_operations')
            ->where('tenant_id', $row->tenant_id)
            ->where('submission_id', $row->id)
            ->first();
        $events = DB::table('candidate_onboarding_events')
            ->where('tenant_id', $row->tenant_id)
            ->where('submission_id', $row->id)
            ->orderBy('id')
            ->get();
        $actors = DB::table('users')->whereIn('id', $events->pluck('actor_id')->filter()->all())->pluck('name', 'id');

        return [
            'id' => (int) $row->id,
            'reference' => (string) $row->reference,
            'status' => (string) $row->status,
            'statusLabel' => CandidateForm::STATUSES[$row->status] ?? 'Pending Review',
            'transitions' => CandidateForm::TRANSITIONS[$row->status] ?? [],
            'statuses' => CandidateForm::STATUSES,
            'submittedAt' => (string) $row->created_at,
            'noticeVersion' => (string) $row->notice_version,
            'consentedAt' => (string) $row->consented_at,
            'personal' => [
                'First name' => (string) $row->first_name,
                'Last name' => (string) $row->last_name,
                'Gender' => (string) $row->gender,
                'Email' => (string) $row->email,
                'Primary contact number' => $this->maskSuffix($row->phone_last4),
                'Date of birth' => 'On file',
                'Current address' => 'On file',
                'University name' => (string) $row->university_name,
                'Timestamp' => (string) $row->created_at,
            ],
            'banking' => [
                'bankStatus' => 'On file',
                'accountMask' => '****'.$row->account_last4,
                'routingMask' => '*****'.$row->routing_last4,
            ],
            'canRevealBanking' => $actor->applicationRole() === 'company_admin',
            'employment' => [
                'Job title' => (string) $row->job_title,
                'Employment type' => (string) $row->employment_type,
                'Work location' => (string) $row->work_location,
                'Hourly rate' => (string) $row->hourly_rate,
                'Hours per week' => (string) $row->hours_per_week,
                'Start date' => (string) $row->start_date,
                'Total professional experience' => (string) $row->experience,
                'Job responsibility 1' => (string) $row->responsibility_1,
                'Job responsibility 2' => (string) ($row->responsibility_2 ?? ''),
                'Job responsibility 3' => (string) ($row->responsibility_3 ?? ''),
                'Job responsibility 4' => (string) ($row->responsibility_4 ?? ''),
                'Job responsibility 5' => (string) ($row->responsibility_5 ?? ''),
                'Job responsibility 6' => (string) ($row->responsibility_6 ?? ''),
            ],
            'authorization' => [
                'Work authorization' => (string) $row->work_authorization,
                'I-94 number' => $this->maskSuffix($row->i94_last4),
                'EAD card document number' => $this->maskSuffix($row->ead_last4),
                'Work authorization expiry date' => (string) $row->work_authorization_expires_on,
            ],
            'ssnMask' => '***-**-'.$row->ssn_last4,
            'training' => [
                'Student role description' => (string) $row->student_role,
                'Goals and objectives' => (string) $row->goals,
                'Employer oversight' => (string) $row->oversight,
                'Training and evaluation methods' => (string) $row->evaluation_methods,
            ],
            'documents' => $documents->map(fn (object $document): array => [
                'id' => (int) $document->id,
                'category' => (string) $document->category,
                'label' => CandidateForm::DOCUMENTS[$document->category]['label'] ?? 'Document',
                'image' => str_starts_with((string) $document->mime, 'image/'),
            ])->all(),
            'missing' => array_map(fn (string $category): string => CandidateForm::DOCUMENTS[$category]['label'], $missing),
            'reviewNote' => (string) ($row->review_note ?? ''),
            'changeRequest' => (string) ($row->change_request ?? ''),
            'operations' => [
                'paymentReceived' => $operations !== null && (bool) $operations->payment_received,
                'amountPaid' => $operations?->amount_paid !== null ? (string) $operations->amount_paid : '',
                'paymentMethod' => (string) ($operations->payment_method ?? ''),
                'trainingAgreementSent' => $operations !== null && (bool) $operations->training_agreement_sent,
                'workDay' => (string) ($operations->work_day ?? ''),
            ],
            'paymentMethods' => CandidateForm::PAYMENT_METHODS,
            'events' => $events->map(fn (object $event): array => [
                'action' => $this->eventLabel((string) $event->action),
                'subject' => (string) ($event->subject ?? ''),
                'actor' => $event->actor_id === null ? 'Candidate' : (string) ($actors[$event->actor_id] ?? 'Reviewer'),
                'at' => (string) $event->created_at,
            ])->all(),
        ];
    }

    public function updateStatus(User $actor, int $submissionId, string $status, ?string $note): void
    {
        $row = $this->submission($actor, $submissionId);
        $allowed = CandidateForm::TRANSITIONS[$row->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose a review status that follows the current status.',
            ]);
        }

        $clean = $this->note($note);

        if ($status === 'changes_requested' && $clean === null) {
            throw ValidationException::withMessages([
                'note' => 'Describe the correction without including a Social Security number or document contents.',
            ]);
        }

        DB::transaction(function () use ($actor, $row, $status, $clean): void {
            DB::table('candidate_submissions')->where('id', $row->id)->update([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $clean ?? $row->review_note,
                'change_request' => $status === 'changes_requested' ? $clean : $row->change_request,
                'updated_at' => now(),
            ]);
            $this->event((int) $row->tenant_id, (int) $row->id, (int) $actor->id, 'status_changed', $row->status.':'.$status);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateOperations(User $actor, int $submissionId, array $input): void
    {
        $row = $this->submission($actor, $submissionId);
        $received = filter_var($input['payment_received'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $agreement = filter_var($input['training_agreement_sent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $method = (string) ($input['payment_method'] ?? '');
        $amount = $input['amount_paid'] ?? null;
        $workDay = $input['work_day'] ?? null;

        if ($received) {
            if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > 9999999.99) {
                throw ValidationException::withMessages(['amount_paid' => 'Enter the amount recorded by HR. This does not create a payment.']);
            }

            if (! array_key_exists($method, CandidateForm::PAYMENT_METHODS)) {
                throw ValidationException::withMessages(['payment_method' => 'Choose a payment method. This does not create a payment.']);
            }
        }

        if ($workDay !== null && $workDay !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $workDay)) {
            throw ValidationException::withMessages(['work_day' => 'Enter a work day as a date.']);
        }

        $now = now();
        $values = [
            'payment_received' => $received,
            'amount_paid' => $received ? number_format((float) $amount, 2, '.', '') : null,
            'payment_method' => $received ? $method : null,
            'training_agreement_sent' => $agreement,
            'work_day' => $workDay !== null && $workDay !== '' ? $workDay : null,
            'updated_by' => $actor->id,
            'updated_at' => $now,
        ];
        $existing = DB::table('candidate_submission_operations')->where('submission_id', $row->id)->first();

        if ($existing === null) {
            DB::table('candidate_submission_operations')->insert([
                ...$values,
                'tenant_id' => $row->tenant_id,
                'submission_id' => $row->id,
                'created_at' => $now,
            ]);
        } else {
            DB::table('candidate_submission_operations')->where('id', $existing->id)->update($values);
        }

        $this->event((int) $row->tenant_id, (int) $row->id, (int) $actor->id, 'operations_updated', null);
    }

    public function download(User $actor, int $submissionId, int $documentId): StreamedResponse
    {
        $row = $this->submission($actor, $submissionId);
        $document = DB::table('candidate_submission_documents')
            ->where('tenant_id', $row->tenant_id)
            ->where('submission_id', $row->id)
            ->where('id', $documentId)
            ->first();
        abort_if($document === null, 404);
        abort_unless(array_key_exists((string) $document->category, CandidateForm::DOCUMENTS), 404);

        $prefix = 'candidate-submissions/'.(int) $row->tenant_id.'/'.(int) $row->id.'/';
        $path = (string) $document->file_path;
        abort_unless(str_starts_with($path, $prefix) && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        $extension = CandidateForm::MIMES[$document->mime] ?? 'bin';
        $filename = $document->category.'.'.$extension;
        $this->event((int) $row->tenant_id, (int) $row->id, (int) $actor->id, 'document_downloaded', (string) $document->category);

        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if (str_starts_with((string) $document->mime, 'image/')) {
            return Storage::disk('local')->response($path, $filename, $headers);
        }

        return Storage::disk('local')->download($path, $filename, $headers);
    }

    public function revealSsn(User $actor, int $submissionId): string
    {
        try {
            $row = $this->submission($actor, $submissionId);
        } catch (HttpException $exception) {
            $this->recordDeniedReveal($actor, $submissionId, 'ssn_reveal_denied');

            throw $exception;
        }

        try {
            $digits = Crypt::decryptString((string) $row->ssn_ciphertext);
        } catch (\Throwable) {
            Log::warning('Candidate Social Security number could not be read.', [
                'tenant_id' => (int) $row->tenant_id,
                'submission_id' => (int) $row->id,
                'reason' => 'decrypt_failed',
            ]);
            abort(404);
        }

        abort_unless(strlen($digits) === 9 && hash_equals((string) $row->ssn_last4, substr($digits, -4)), 404);
        $this->event((int) $row->tenant_id, (int) $row->id, (int) $actor->id, 'ssn_viewed', null);

        return substr($digits, 0, 3).'-'.substr($digits, 3, 2).'-'.substr($digits, 5, 4);
    }

    /**
     * Phone, date of birth, address, I-94, and EAD values for a reviewer in this company.
     * The ordinary review page receives masks only.
     *
     * @return array{phone: string, dateOfBirth: string, address: string, i94Number: string, eadNumber: string}
     */
    public function revealIdentity(User $actor, int $submissionId): array
    {
        try {
            $row = $this->submission($actor, $submissionId);
        } catch (HttpException $exception) {
            $this->recordDeniedReveal($actor, $submissionId, 'identity_reveal_denied');

            throw $exception;
        }

        try {
            $phone = Crypt::decryptString((string) $row->phone_ciphertext);
            $dateOfBirth = Crypt::decryptString((string) $row->date_of_birth_ciphertext);
            $address = Crypt::decryptString((string) $row->address_ciphertext);
            $i94 = Crypt::decryptString((string) $row->i94_ciphertext);
            $ead = Crypt::decryptString((string) $row->ead_ciphertext);
        } catch (\Throwable) {
            Log::warning('Candidate identity details could not be read.', [
                'tenant_id' => (int) $row->tenant_id,
                'submission_id' => (int) $row->id,
                'reason' => 'decrypt_failed',
            ]);
            abort(404);
        }

        $this->assertStoredSuffix($row->phone_last4, $this->digits($phone));
        $this->assertStoredSuffix($row->i94_last4, $i94);
        $this->assertStoredSuffix($row->ead_last4, $ead);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth) === 1, 404);
        $this->event((int) $row->tenant_id, (int) $row->id, (int) $actor->id, 'identity_viewed', null);

        return [
            'phone' => $phone,
            'dateOfBirth' => $dateOfBirth,
            'address' => $address,
            'i94Number' => $i94,
            'eadNumber' => $ead,
        ];
    }

    /**
     * @return array{bankName: string, accountNumber: string, routingNumber: string}
     */
    public function revealBanking(User $actor, int $submissionId): array
    {
        try {
            $row = $this->submission($actor, $submissionId);
        } catch (HttpException $exception) {
            $this->recordDeniedReveal($actor, $submissionId, 'banking_reveal_denied');

            throw $exception;
        }

        if ($actor->applicationRole() !== 'company_admin') {
            $this->recordDeniedReveal($actor, $submissionId, 'banking_reveal_denied');
            abort(403);
        }

        try {
            $bankName = Crypt::decryptString((string) $row->bank_name_ciphertext);
            $account = Crypt::decryptString((string) $row->account_ciphertext);
            $routing = Crypt::decryptString((string) $row->routing_ciphertext);
        } catch (\Throwable) {
            Log::warning('Candidate bank details could not be read.', [
                'tenant_id' => (int) $row->tenant_id,
                'submission_id' => (int) $row->id,
                'reason' => 'decrypt_failed',
            ]);
            abort(404);
        }

        abort_unless(hash_equals((string) $row->account_last4, substr($account, -4)), 404);
        abort_unless(hash_equals((string) $row->routing_last4, substr($routing, -4)), 404);
        $this->event((int) $row->tenant_id, (int) $row->id, (int) $actor->id, 'banking_viewed', null);

        return [
            'bankName' => $bankName,
            'accountNumber' => $account,
            'routingNumber' => $routing,
        ];
    }

    private function settingsNotice(int $timeoutMinutes): string
    {
        $scan = config('candidate.malware_scan') === 'required'
            ? ($this->scanner->available()
                ? 'Malware scanning is required and a scanner command is configured.'
                : 'Malware scanning is required, and no scanner is available, so the form cannot be enabled.')
            : 'Malware scanning is turned off in this environment.';
        $captcha = $this->captcha->required()
            ? ($this->captcha->configured()
                ? 'A verification check is required before a candidate can submit.'
                : 'A verification check is required, and it is not configured, so the form cannot be enabled.')
            : 'The verification check is turned off in this environment.';
        $retention = is_int(config('candidate.retention_days')) && (int) config('candidate.retention_days') > 0
            ? 'A retention period is configured. Automatic deletion is not scheduled.'
            : 'No retention period is approved, so candidate records are not deleted automatically.';

        return 'Candidates can submit this form without an account. It collects identity, bank details, work-authorization details, a Social Security number, and identity documents. Authorized HR staff review submissions for this company only. Phone, date of birth, address, I-94, and EAD values are masked until a Company Admin or HR reviewer reveals them. Revealing a Social Security number or bank details, and downloading an identity document, requires the reviewer\'s password again. That confirmation lasts '.$timeoutMinutes.' minutes. A value shown after a successful reveal is visible in that browser until it is hidden; application encryption does not protect it after an authorized reveal. Full bank details can be revealed by a Company Admin. Enabling the form does not create employees, payroll, or payments. Disabling it stops new submissions and keeps existing records. The public link stays the same until a Company Admin rotates it. '.$scan.' '.$captcha.' Uploaded files are stored on the private disk. Those encrypted values use the application encryption key. After a key rotation, Laravel can still read them only when the old key remains in APP_PREVIOUS_KEYS. Replacing the key without that previous key makes them unreadable. The email duplicate check uses the current key only and does not consult APP_PREVIOUS_KEYS. '.$retention.' The notice text, retention period, and collection of Social Security numbers, bank details, and identity documents need legal and privacy review before production use.';
    }

    private function protectionsReady(): bool
    {
        $scanReady = config('candidate.malware_scan') !== 'required' || $this->scanner->available();
        $captchaReady = ! $this->captcha->required() || $this->captcha->configured();

        return $scanReady && $captchaReady;
    }

    private function assertCaptcha(Request $request): void
    {
        if (! $this->captcha->required()) {
            return;
        }

        $verdict = $this->captcha->verify($request->input('captcha_token'), $request->ip());

        if ($verdict === 'accepted') {
            return;
        }

        $message = match ($verdict) {
            'unavailable' => 'The verification check is unavailable. The form was not submitted.',
            'expired' => 'The verification check expired. Complete it again.',
            default => 'Complete the verification check before submitting.',
        };

        throw ValidationException::withMessages([
            'captcha_token' => $message,
        ]);
    }

    private function formByToken(string $token): ?object
    {
        if (! $this->ready() || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return DB::table('candidate_onboarding_forms')->where('public_token', $token)->first();
    }

    private function tenantIsOpen(int $tenantId): bool
    {
        return DB::table('tenants')->where('id', $tenantId)->value('status') === 'active';
    }

    private function assertReviewer(User $actor): void
    {
        abort_unless($this->ready(), 404);
        abort_unless(in_array($actor->applicationRole(), ['company_admin', 'hr'], true) && $actor->companyId() !== null, 403);
    }

    private function submission(User $actor, int $submissionId): object
    {
        $this->assertReviewer($actor);
        $row = DB::table('candidate_submissions')
            ->where('tenant_id', $actor->companyId())
            ->where('id', $submissionId)
            ->first();
        abort_if($row === null, 404);

        return $row;
    }

    /**
     * @return list<array{category: string, path: string, name: string, mime: string, size: int}>
     */
    private function storeFiles(int $tenantId, Request $request): array
    {
        $stored = [];
        $directory = 'candidate-submissions/'.$tenantId.'/pending-'.Str::uuid();

        try {
            foreach (CandidateForm::DOCUMENTS as $category => $meta) {
                $file = $request->file(CandidateForm::inputName($category));

                if (! $file instanceof UploadedFile) {
                    if ($meta['required']) {
                        throw ValidationException::withMessages([
                            CandidateForm::inputName($category) => $meta['label'].' is required.',
                        ]);
                    }

                    continue;
                }

                $mime = (string) $file->getMimeType();
                $extension = CandidateForm::MIMES[$mime] ?? null;
                $size = (int) $file->getSize();

                if ($extension === null || $size < 1 || $size > CandidateForm::MAX_KILOBYTES * 1024) {
                    throw ValidationException::withMessages([
                        CandidateForm::inputName($category) => 'Upload a PDF, JPEG, PNG, or WEBP file up to 5 MB.',
                    ]);
                }

                if (config('candidate.malware_scan') === 'required') {
                    $verdict = $this->scanner->scan((string) $file->getRealPath());

                    if ($verdict !== 'clean') {
                        throw ValidationException::withMessages([
                            CandidateForm::inputName($category) => $verdict === 'infected'
                                ? 'The file was rejected by the malware check.'
                                : 'The file could not be accepted because malware scanning is unavailable.',
                        ]);
                    }
                }

                $path = $file->storeAs($directory, $category.'-'.Str::uuid().'.'.$extension, 'local');

                if ($path === false || ! str_starts_with($path, $directory.'/') || str_contains($path, '..')) {
                    throw ValidationException::withMessages([
                        CandidateForm::inputName($category) => 'The file could not be stored.',
                    ]);
                }

                $stored[] = [
                    'category' => $category,
                    'path' => $path,
                    'name' => $category.'.'.$extension,
                    'mime' => $mime,
                    'size' => $size,
                ];
            }

            return $stored;
        } catch (\Throwable $exception) {
            $this->deleteStored($stored);

            throw $exception;
        }
    }

    /**
     * @param  list<array{category: string, path: string, name: string, mime: string, size: int}>  $stored
     * @return list<array{category: string, path: string, name: string, mime: string, size: int}>
     */
    private function relocate(array $stored, int $tenantId, int $submissionId): array
    {
        $directory = 'candidate-submissions/'.$tenantId.'/'.$submissionId;
        $moved = [];
        $pending = $stored[0]['path'] ?? null;

        try {
            foreach ($stored as $document) {
                $target = $directory.'/'.basename($document['path']);

                if ($target === $document['path'] || ! Storage::disk('local')->move($document['path'], $target) || str_contains($target, '..')) {
                    throw new \RuntimeException('The upload could not be stored.');
                }

                $document['path'] = $target;
                $moved[] = $document;
            }

            if (is_string($pending)) {
                Storage::disk('local')->deleteDirectory(dirname($pending));
            }

            return $moved;
        } catch (\Throwable $exception) {
            $this->deleteStored($stored);
            $this->deleteStored($moved);

            throw $exception;
        }
    }

    /**
     * @param  list<array{path: string}>  $stored
     */
    private function deleteStored(array $stored): void
    {
        foreach ($stored as $document) {
            $path = (string) ($document['path'] ?? '');
            Storage::disk('local')->delete($path);

            if (str_contains($path, '/pending-')) {
                Storage::disk('local')->deleteDirectory(dirname($path));
            }
        }
    }

    private function reference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'CN'.strtoupper(bin2hex(random_bytes(5)));

            if (! DB::table('candidate_submissions')->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw ValidationException::withMessages([
            'form' => 'The form could not be submitted.',
        ]);
    }

    private function emailHash(string $email): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw ValidationException::withMessages([
                'form' => 'The form could not be submitted.',
            ]);
        }

        return hash_hmac('sha256', $email, $key);
    }

    private function text(mixed $value, int $limit): string
    {
        $text = trim(strip_tags((string) $value));

        return substr($text, 0, $limit);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        return $text === '' ? null : $text;
    }

    private function note(?string $note): ?string
    {
        $clean = $this->nullableText($note, 2000);

        if ($clean !== null && CandidateForm::containsSensitiveNumber($clean)) {
            throw ValidationException::withMessages([
                'note' => 'Do not include a Social Security number or a long identity number in a note.',
            ]);
        }

        return $clean;
    }

    private function revealableSuffix(string $value): ?string
    {
        return strlen($value) > 4 ? substr($value, -4) : null;
    }

    private function maskSuffix(mixed $last4): string
    {
        $suffix = (string) $last4;

        return strlen($suffix) === 4 ? '••••'.$suffix : 'On file';
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function assertStoredSuffix(mixed $stored, string $value): void
    {
        $suffix = $stored === null ? '' : (string) $stored;

        if ($suffix === '') {
            abort_unless(strlen($value) <= 4, 404);

            return;
        }

        abort_unless(strlen($value) > 4 && hash_equals($suffix, substr($value, -4)), 404);
    }

    private function recordDeniedReveal(User $actor, int $submissionId, string $action): void
    {
        Log::warning('Candidate sensitive reveal was denied.', [
            'actor_id' => (int) $actor->id,
            'tenant_id' => $actor->companyId(),
            'submission_id' => $submissionId,
            'reason' => 'unauthorized',
        ]);

        $companyId = $actor->companyId();

        if ($companyId === null) {
            return;
        }

        $owned = DB::table('candidate_submissions')
            ->where('tenant_id', $companyId)
            ->where('id', $submissionId)
            ->exists();

        if ($owned) {
            $this->event($companyId, $submissionId, (int) $actor->id, $action, null);
        }
    }

    private function event(int $tenantId, ?int $submissionId, ?int $actorId, string $action, ?string $subject): void
    {
        DB::table('candidate_onboarding_events')->insert([
            'tenant_id' => $tenantId,
            'submission_id' => $submissionId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject' => $subject,
            'created_at' => now(),
        ]);
    }

    private function eventLabel(string $action): string
    {
        return match ($action) {
            'submission_received' => 'Submission received',
            'status_changed' => 'Review status changed',
            'operations_updated' => 'Internal fields updated',
            'document_downloaded' => 'Document downloaded',
            'ssn_viewed' => 'Social Security number viewed',
            'ssn_reveal_denied' => 'Social Security number reveal denied',
            'identity_viewed' => 'Protected identity details viewed',
            'identity_reveal_denied' => 'Protected identity reveal denied',
            'banking_viewed' => 'Bank details viewed',
            'banking_reveal_denied' => 'Bank detail reveal denied',
            'form_token_rotated' => 'Public link rotated',
            default => 'Review event',
        };
    }
}
