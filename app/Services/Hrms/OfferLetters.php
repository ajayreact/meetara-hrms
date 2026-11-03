<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use App\Support\IndianAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferLetters
{
    public const POSITIONS = [
        'opt_recruiter' => 'OPT Recruiter',
        'remote_opt_recruiter' => 'Remote OPT Recruiter',
        'bench_sales_recruiter' => 'Bench Sales Recruiter',
        'opt_team_lead' => 'OPT Team Lead',
        'bench_sales_team_lead' => 'Bench Sales Team Lead',
    ];

    public function __construct(
        private readonly EmployeeOnboarding $onboarding,
        private readonly OfferLetterReadiness $readiness,
        private readonly OfferLetterCatalog $catalog,
    ) {}

    public function ready(): bool
    {
        return Schema::hasTable('offer_letters');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): int
    {
        $this->guard($actor);
        $values = $this->values($actor, $data);

        if (Schema::hasColumn('offer_letters', 'template_version_id')) {
            $country = (string) ($values['country'] ?? ($data['country'] ?? 'india'));
            $active = $this->catalog->activeTemplate((int) $actor->companyId(), $country);
            $values['template_version_id'] = $active?->id;
        }

        $this->assertCreatable($actor, $values);
        $this->assertAppointment($actor, $data, $values);

        return (int) DB::table('offer_letters')->insertGetId([
            ...$values,
            'tenant_id' => $actor->companyId(),
            'status' => 'draft',
            'version' => 1,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function preview(User $actor, int $letterId): string
    {
        return DB::transaction(function () use ($actor, $letterId): string {
            $letter = $this->lock($actor, $letterId);
            $this->assertOpen($letter);
            $this->assertJoiningAfterIssue($letter);
            $stored = (string) ($letter->preview_body ?? '');
            $body = OfferLetterMarkup::looksLikeHtml($stored) ? $stored : $this->previewBody($actor, $letter);
            $this->assertResolvedBody($body);
            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => 'generated',
                'preview_body' => $body,
                'updated_at' => now(),
            ]);

            return $body;
        });
    }

    public function issue(User $actor, int $letterId): void
    {
        DB::transaction(function () use ($actor, $letterId): void {
            $letter = $this->lock($actor, $letterId);
            $this->assertJoiningAfterIssue($letter);

            if ($letter->issued_body !== null) {
                throw ValidationException::withMessages(['letter' => 'An issued letter cannot be changed. Create a new version.']);
            }

            $country = $this->countryOf($letter);
            $direct = $country === 'india' && ! $this->approvalRequired((int) $letter->tenant_id);

            if ($direct) {
                $prepared = (string) ($letter->preview_body ?? '');

                if ($prepared === '' || ! OfferLetterMarkup::looksLikeHtml($prepared)) {
                    $prepared = $this->letterDocument($letter);
                    $letter->preview_body = $prepared;
                    $letter->status = 'generated';
                    DB::table('offer_letters')->where('id', $letter->id)->update([
                        'preview_body' => $prepared,
                        'status' => 'generated',
                        'updated_at' => now(),
                    ]);
                }

                $this->assertResolvedBody($prepared);
            } else {
                if ($letter->preview_body === null || ! in_array($letter->status, ['generated', 'pending_approval', 'approved'], true)) {
                    throw ValidationException::withMessages(['letter' => 'Preview the letter before issuing it.']);
                }

                $this->readiness->assertReadyFor((int) $letter->tenant_id, $country);

                if (Schema::hasColumn('offer_letters', 'approved_at') && $letter->status !== 'approved') {
                    throw ValidationException::withMessages(['letter' => 'Approve the offer letter before issuing it. The writer cannot approve their own letter.']);
                }
            }

            $path = $letter->employee_id
                ? 'employee-documents/'.(int) $letter->tenant_id.'/'.(int) $letter->employee_id.'/'.$letter->id.'-v'.$letter->version.'.pdf'
                : 'offer-letters/'.(int) $letter->tenant_id.'/'.$letter->id.'-v'.$letter->version.'.pdf';
            $template = $this->frozenTemplate($letter, $country);
            $letterheadId = $template !== null && $template->letterhead_id !== null ? (int) $template->letterhead_id : null;
            $layout = $this->catalog->printLayout((int) $letter->tenant_id, $country, $letterheadId);
            $signature = $this->catalog->signatureLayout((int) $letter->tenant_id, $country);
            $preview = (string) $letter->preview_body;
            $this->assertResolvedBody($preview);

            if ($country === 'india') {
                $this->catalog->assertLetterheadConfirmed((int) $letter->tenant_id, 'india');
                $this->catalog->assertLetterheadBrand((int) $letter->tenant_id, 'india');
            }

            $closesWithSignature = str_contains($preview, '"type":"closing"')
                || str_contains($preview, '"type": "closing"')
                || str_contains($preview, 'data-signature="hr"');

            if (($signature['enabled'] ?? false) && $closesWithSignature && ($signature['path'] === null || ! ($signature['active'] ?? false))) {
                throw ValidationException::withMessages([
                    'letter' => 'Automatic HR signature is turned on, but no active signature image is on file. Upload a signature or turn automatic placement off.',
                ]);
            }

            Storage::disk('local')->put($path, (new OfferLetterPdf)->render($preview, $layout['image'], $layout['margins'], [
                ...$layout,
                'signature' => $signature,
            ]));
            $issued = [
                'status' => 'issued',
                'issued_body' => $letter->preview_body,
                'issued_values' => json_encode([
                    'position' => $letter->position_code,
                    'employee_name' => $letter->employee_name,
                    'monthly_salary' => $letter->monthly_salary,
                    'annual_ctc' => $letter->annual_ctc,
                    'salary_in_words' => IndianAmount::words((string) $letter->monthly_salary),
                    'annual_ctc_in_words' => IndianAmount::words((string) $letter->annual_ctc),
                    'joining_date' => $letter->joining_date,
                    'notice_months' => $letter->notice_months,
                    'working_hours' => $this->attr($letter, 'working_hours'),
                    'salutation' => $this->attr($letter, 'salutation'),
                    'hr_name' => $letter->hr_name,
                    'letterhead_id' => $layout['letterheadId'] ?? null,
                    'signature_version' => ($signature['path'] ?? null) !== null ? $signature['version'] : null,
                ]),
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'file_path' => $path,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('offer_letters', 'reference_code')) {
                $issued['reference_code'] = 'OL-'.$letter->tenant_id.'-'.$letter->id.'-v'.$letter->version;
                $issued['template_version_id'] = $template?->id;
            }

            DB::table('offer_letters')->where('id', $letter->id)->update($issued);
            $this->attachGeneratedCopy($actor, $letter, $path);
        });
    }

    public function decide(User $actor, int $letterId, string $status): void
    {
        DB::transaction(function () use ($actor, $letterId, $status): void {
            $letter = $this->lock($actor, $letterId);

            if ($letter->status !== 'issued') {
                throw ValidationException::withMessages(['letter' => 'Only an issued letter can be accepted or declined.']);
            }

            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
        });
    }

    public function revise(User $actor, int $letterId): int
    {
        return DB::transaction(function () use ($actor, $letterId): int {
            $letter = $this->lock($actor, $letterId);

            if (! in_array($letter->status, ['issued', 'accepted', 'declined'], true)) {
                throw ValidationException::withMessages(['letter' => 'Revise a letter only after it has been issued.']);
            }

            $copy = [
                'tenant_id' => $letter->tenant_id,
                'employee_id' => $letter->employee_id,
                'position_code' => $letter->position_code,
                'employee_name' => $letter->employee_name,
                'monthly_salary' => $letter->monthly_salary,
                'annual_ctc' => $letter->annual_ctc,
                'joining_date' => $letter->joining_date,
                'notice_months' => $letter->notice_months,
                'hr_name' => $letter->hr_name,
                'hr_signature' => $letter->hr_signature,
                'status' => 'draft',
                'version' => ((int) $letter->version) + 1,
                'replaces_letter_id' => $letter->id,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach ([
                'country', 'employee_email', 'compensation_type', 'hourly_rate', 'basic_salary', 'hra', 'special_allowance',
                'probation_months', 'department_name', 'work_location', 'employment_type', 'work_mode', 'working_hours',
                'notice_days', 'date_of_issue', 'designation_name', 'reporting_manager_name', 'employee_phone', 'offer_notes',
            ] as $column) {
                if (Schema::hasColumn('offer_letters', $column)) {
                    $copy[$column] = $letter->{$column} ?? null;
                }
            }

            return (int) DB::table('offer_letters')->insertGetId($copy);
        });
    }

    public function renderStoredPreview(User $actor, int $letterId): string
    {
        $letter = $this->find($actor, $letterId);

        if (in_array($letter->status, ['issued', 'accepted', 'declined'], true) && is_string($letter->file_path) && Storage::disk('local')->exists($letter->file_path)) {
            return (string) Storage::disk('local')->get($letter->file_path);
        }

        $this->assertJoiningAfterIssue($letter);
        abort_if($letter->preview_body === null, 404);

        return $this->renderLetterPdf($letter, (string) $letter->preview_body);
    }

    public function previewDocument(User $actor, int $letterId, ?string $body): string
    {
        $letter = $this->find($actor, $letterId);

        if (in_array($letter->status, ['issued', 'accepted', 'declined'], true) && is_string($letter->file_path) && Storage::disk('local')->exists($letter->file_path)) {
            return (string) Storage::disk('local')->get($letter->file_path);
        }

        $this->assertJoiningAfterIssue($letter);
        $html = is_string($body) && trim($body) !== ''
            ? OfferLetterMarkup::sanitize($body)
            : $this->editorBody($letter);
        $this->assertResolvedBody($html);

        return $this->renderLetterPdf($letter, $html);
    }

    public function saveDraft(User $actor, int $letterId, string $body): void
    {
        DB::transaction(function () use ($actor, $letterId, $body): void {
            $letter = $this->lock($actor, $letterId);
            $this->assertOpen($letter);
            $clean = OfferLetterMarkup::sanitize($body);

            if (trim(strip_tags($clean)) === '' && ! str_contains($clean, 'data-signature="hr"')) {
                throw ValidationException::withMessages(['body' => 'Enter the letter wording before saving this draft.']);
            }

            $this->assertResolvedBody($clean);

            $update = [
                'preview_body' => $clean,
                'status' => 'draft',
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('offer_letters', 'submitted_by')) {
                $update['submitted_by'] = null;
                $update['submitted_at'] = null;
                $update['approved_by'] = null;
                $update['approved_at'] = null;
            }

            if (Schema::hasColumn('offer_letters', 'template_version_id') && ($letter->template_version_id ?? null) === null) {
                $active = $this->catalog->activeTemplate((int) $letter->tenant_id, $this->countryOf($letter));
                $update['template_version_id'] = $active?->id;
            }

            DB::table('offer_letters')->where('id', $letter->id)->update($update);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function editing(User $actor, int $letterId): array
    {
        $letter = $this->find($actor, $letterId);
        $country = $this->countryOf($letter);
        $signature = $this->catalog->signatureLayout((int) $letter->tenant_id, $country);
        $facts = $this->catalog->signatureDetails((int) $letter->tenant_id, $country);
        $letterhead = $this->activeLetterhead($letter, $country);
        $body = $this->editorBody($letter);

        return [
            'id' => (int) $letter->id,
            'status' => $this->statusLabel((string) $letter->status),
            'statusKey' => (string) $letter->status,
            'country' => $country,
            'body' => $body,
            'editable' => in_array($letter->status, ['draft', 'generated'], true) && $letter->issued_body === null,
            'own' => (int) ($letter->created_by ?? 0) === (int) $actor->id,
            'employeeName' => (string) $letter->employee_name,
            'reference' => $this->attr($letter, 'reference_code'),
            'submittedAt' => $this->attr($letter, 'submitted_at'),
            'approvedAt' => $this->attr($letter, 'approved_at'),
            'issuedAt' => $letter->issued_at === null ? null : (string) $letter->issued_at,
            'issuable' => $letter->status === 'approved' && $this->readiness->ready((int) $letter->tenant_id, $country),
            'letterheadId' => $letterhead['id'],
            'pageWidth' => $letterhead['pageWidth'],
            'pageHeight' => $letterhead['pageHeight'],
            'marginTop' => $letterhead['marginTop'],
            'marginRight' => $letterhead['marginRight'],
            'marginBottom' => $letterhead['marginBottom'],
            'marginLeft' => $letterhead['marginLeft'],
            'headerHeight' => $letterhead['headerHeight'],
            'footerHeight' => $letterhead['footerHeight'],
            'mime' => $letterhead['mime'],
            'showSignature' => ($signature['path'] ?? null) !== null,
            'signatureWidth' => (int) ($signature['maxWidth'] ?? 140),
            'signatureHeight' => (int) ($signature['maxHeight'] ?? 48),
            'signatureAlign' => (string) ($signature['align'] ?? 'left'),
            'signatureGapBefore' => (int) ($signature['before'] ?? 8),
            'signatureGapAfter' => (int) ($signature['after'] ?? 6),
            'unresolved' => OfferLetterCatalog::unresolvedTokens($body),
            'blocks' => $this->generationBlocks($letter),
            'correction' => $this->correctionState($actor, $letter),
            'signatureOnFile' => $facts['onFile'],
            'signatureEnabled' => $facts['enabled'],
            'signatureImageWidth' => $facts['imageWidth'],
            'signatureImageHeight' => $facts['imageHeight'],
        ];
    }

    public function applyWorkingHours(User $actor, int $letterId, string $hours): void
    {
        DB::transaction(function () use ($actor, $letterId, $hours): void {
            $letter = $this->lock($actor, $letterId);

            if (in_array($letter->status, ['issued', 'accepted', 'declined'], true) || $letter->issued_body !== null) {
                throw ValidationException::withMessages(['working_hours' => 'An issued letter cannot be changed.']);
            }

            $value = trim($hours);

            if ($value === '') {
                throw ValidationException::withMessages([
                    'working_hours' => 'Enter the working hours for this offer. A saved shift can be selected, and nothing is filled in when the employee has no shift.',
                ]);
            }

            $update = ['updated_at' => now()];

            if (Schema::hasColumn('offer_letters', 'working_hours')) {
                $update['working_hours'] = $value;
            }

            $stored = (string) ($letter->preview_body ?? '');

            if ($stored !== '' && str_contains($stored, '{{working_hours}}')) {
                $update['preview_body'] = str_replace(
                    '{{working_hours}}',
                    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $stored,
                );
            }

            DB::table('offer_letters')->where('id', $letter->id)->update($update);
        });
    }

    public function download(User $actor, int $letterId): StreamedResponse
    {
        $letter = $this->find($actor, $letterId);
        $prefixes = [
            'offer-letters/'.(int) $letter->tenant_id.'/',
            'employee-documents/'.(int) $letter->tenant_id.'/',
        ];
        $allowed = false;

        foreach ($prefixes as $prefix) {
            if (is_string($letter->file_path) && str_starts_with($letter->file_path, $prefix) && ! str_contains($letter->file_path, '..')) {
                $allowed = true;
            }
        }

        abort_unless(in_array($letter->status, ['issued', 'accepted', 'declined'], true) && $allowed, 404);
        abort_unless(Storage::disk('local')->exists($letter->file_path), 404);

        if (Schema::hasTable('offer_letter_downloads')) {
            DB::table('offer_letter_downloads')->insert([
                'tenant_id' => $letter->tenant_id,
                'offer_letter_id' => $letter->id,
                'user_id' => $actor->id,
                'created_at' => now(),
            ]);
        }

        return Storage::disk('local')->download($letter->file_path, 'offer-letter-v'.$letter->version.'.pdf', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function issuance(User $actor): array
    {
        return $this->readiness->present($actor->companyId());
    }

    /**
     * @return array{total: int, drafts: int, generated: int}
     */
    public function counts(User $actor): array
    {
        if (! $this->ready() || ! $this->allowed($actor) || $actor->companyId() === null) {
            return ['total' => 0, 'drafts' => 0, 'generated' => 0];
        }

        $rows = DB::table('offer_letters')->where('tenant_id', $actor->companyId())->select('status')->get();
        $generated = $rows->whereIn('status', ['issued', 'accepted', 'declined'])->count();

        return [
            'total' => $rows->count(),
            'drafts' => $rows->count() - $generated,
            'generated' => $generated,
        ];
    }

    public function approvalRequired(?int $companyId): bool
    {
        if ($companyId === null || ! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'offer_approval_required')) {
            return true;
        }

        $value = DB::table('tenants')->where('id', $companyId)->value('offer_approval_required');

        return $value === null ? true : (bool) $value;
    }

    public function setApprovalRequired(User $actor, bool $required): void
    {
        $this->guard($actor);
        abort_unless($actor->applicationRole() === 'company_admin', 403);
        abort_unless(Schema::hasColumn('tenants', 'offer_approval_required'), 404);

        DB::table('tenants')->where('id', $actor->companyId())->update([
            'offer_approval_required' => $required,
            'updated_at' => now(),
        ]);
    }

    public function saveAppointmentPreview(User $actor, int $letterId): string
    {
        return DB::transaction(function () use ($actor, $letterId): string {
            $letter = $this->lock($actor, $letterId);
            $this->assertOpen($letter);
            $this->assertJoiningAfterIssue($letter);
            $body = $this->letterDocument($letter);
            $this->assertResolvedBody($body);
            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => 'generated',
                'preview_body' => $body,
                'updated_at' => now(),
            ]);

            return $body;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(User $actor, int $letterId, array $data): void
    {
        DB::transaction(function () use ($actor, $letterId, $data): void {
            $letter = $this->lock($actor, $letterId);
            $this->assertOpen($letter);
            $values = $this->values($actor, $data);
            $this->assertCreatable($actor, $values);
            $this->assertAppointment($actor, $data, $values);
            $values['status'] = 'draft';
            $values['preview_body'] = null;
            $values['updated_at'] = now();
            DB::table('offer_letters')->where('id', $letter->id)->update($values);
        });
    }

    /**
     * Correct an unissued offer whose joining date is missing or not later than the issue date.
     * The employee master and salary register are left unchanged, and no PDF is written.
     *
     * @param  array<string, mixed>  $data
     */
    public function correct(User $actor, int $letterId, array $data): void
    {
        DB::transaction(function () use ($actor, $letterId, $data): void {
            $letter = $this->lock($actor, $letterId);

            if ($this->letterHasIssuedFile($letter) || in_array((string) $letter->status, ['issued', 'accepted', 'declined'], true)) {
                throw ValidationException::withMessages(['letter' => 'An offer that already has a PDF cannot be corrected here.']);
            }

            if (! $this->offerNeedsCorrection($letter)) {
                throw ValidationException::withMessages(['letter' => 'This offer does not need a joining-date correction.']);
            }

            $this->assertOfferDates($data['joining_date'] ?? null, $data['date_of_issue'] ?? null);
            $joining = $this->dateOnly($data['joining_date'] ?? null);
            $issue = $this->dateOnly($data['date_of_issue'] ?? null);

            if ($joining === null || $issue === null || $joining <= $issue) {
                throw ValidationException::withMessages(['joining_date' => 'Joining date must be later than the date of issue.']);
            }

            $reason = trim((string) ($data['reason'] ?? ''));

            if (strlen($reason) < 10) {
                throw ValidationException::withMessages(['reason' => 'Enter a short reason for this correction.']);
            }

            $previous = [
                'joining_date' => $this->dateOnly($letter->joining_date),
                'date_of_issue' => $this->dateOnly($this->attr($letter, 'date_of_issue')),
                'status' => (string) $letter->status,
                'monthly_salary' => (string) $letter->monthly_salary,
                'basic_salary' => $this->attr($letter, 'basic_salary'),
                'hra' => $this->attr($letter, 'hra'),
                'special_allowance' => $this->attr($letter, 'special_allowance'),
            ];
            $updated = [
                'joining_date' => $joining,
                'date_of_issue' => $issue,
                'status' => 'draft',
                'preview_body' => null,
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'updated_at' => now(),
            ];

            if (! empty($data['apply_salary'])) {
                $salary = $this->currentSalaries((int) $letter->tenant_id)[(int) $letter->employee_id] ?? null;

                if ($salary === null || $salary['appliesTo'] !== $this->countryOf($letter)) {
                    throw ValidationException::withMessages(['apply_salary' => 'No current salary record is available for this offer.']);
                }

                $monthlyCents = Money::cents($salary['monthly']);
                $updated['basic_salary'] = $salary['basic'];
                $updated['hra'] = $salary['hra'];
                $updated['special_allowance'] = $salary['special'];
                $updated['monthly_salary'] = $salary['monthly'];
                $updated['annual_ctc'] = Money::format($monthlyCents * 12);
            }

            DB::table('offer_letters')->where('id', $letter->id)->update($updated);

            if (Schema::hasTable('offer_letter_corrections')) {
                DB::table('offer_letter_corrections')->insert([
                    'tenant_id' => $letter->tenant_id,
                    'offer_letter_id' => $letter->id,
                    'actor_id' => $actor->id,
                    'reason' => $reason,
                    'previous_values' => json_encode($previous),
                    'updated_values' => json_encode([
                        'joining_date' => $joining,
                        'date_of_issue' => $issue,
                        'monthly_salary' => $updated['monthly_salary'] ?? $previous['monthly_salary'],
                        'basic_salary' => $updated['basic_salary'] ?? $previous['basic_salary'],
                        'hra' => $updated['hra'] ?? $previous['hra'],
                        'special_allowance' => $updated['special_allowance'] ?? $previous['special_allowance'],
                    ]),
                    'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formState(User $actor, int $letterId): array
    {
        $letter = $this->find($actor, $letterId);

        return [
            'id' => (int) $letter->id,
            'employeeId' => (int) $letter->employee_id,
            'country' => $this->countryOf($letter),
            'positionCode' => (string) $letter->position_code,
            'designation' => (string) ($this->attr($letter, 'designation_name') ?? ''),
            'department' => (string) ($this->attr($letter, 'department_name') ?? ''),
            'reportingManager' => (string) ($this->attr($letter, 'reporting_manager_name') ?? ''),
            'workLocation' => (string) ($this->attr($letter, 'work_location') ?? ''),
            'employmentType' => (string) ($this->attr($letter, 'employment_type') ?? ''),
            'workMode' => (string) ($this->attr($letter, 'work_mode') ?? ''),
            'joiningDate' => $this->dateOnly($letter->joining_date) ?? '',
            'dateOfIssue' => $this->dateOnly($this->attr($letter, 'date_of_issue')) ?? '',
            'monthlySalary' => (string) $letter->monthly_salary,
            'hourlyRate' => (string) ($this->attr($letter, 'hourly_rate') ?? ''),
            'basicSalary' => (string) ($this->attr($letter, 'basic_salary') ?? ''),
            'hra' => (string) ($this->attr($letter, 'hra') ?? ''),
            'specialAllowance' => (string) ($this->attr($letter, 'special_allowance') ?? ''),
            'probationMonths' => $this->attr($letter, 'probation_months'),
            'noticeMonths' => (int) $letter->notice_months,
            'workingHours' => (string) ($this->attr($letter, 'working_hours') ?? ''),
            'salutation' => (string) ($this->attr($letter, 'salutation') ?? ''),
            'candidateAddress' => (string) ($this->attr($letter, 'candidate_address') ?? ''),
            'hrName' => (string) $letter->hr_name,
            'locked' => ! in_array($letter->status, ['draft', 'generated'], true) || $letter->issued_body !== null,
        ];
    }

    public function submit(User $actor, int $letterId): void
    {
        DB::transaction(function () use ($actor, $letterId): void {
            $letter = $this->lock($actor, $letterId);
            $this->assertJoiningAfterIssue($letter);

            if ($letter->status !== 'generated' || $letter->preview_body === null) {
                throw ValidationException::withMessages(['letter' => 'Preview the letter before submitting it for approval.']);
            }

            if (! Schema::hasColumn('offer_letters', 'submitted_by')) {
                throw ValidationException::withMessages(['letter' => 'Offer letter approval is not available until the latest migration is applied.']);
            }

            $this->assertResolvedBody((string) $letter->preview_body);

            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => 'pending_approval',
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'updated_at' => now(),
            ]);
        });
    }

    public function approve(User $actor, int $letterId): void
    {
        DB::transaction(function () use ($actor, $letterId): void {
            $letter = $this->lock($actor, $letterId);
            $this->assertJoiningAfterIssue($letter);

            if ($letter->status !== 'pending_approval') {
                throw ValidationException::withMessages(['letter' => 'Submit the offer letter for approval first.']);
            }

            if ((int) $letter->created_by === (int) $actor->id || (int) $letter->submitted_by === (int) $actor->id) {
                throw ValidationException::withMessages(['letter' => 'The person who wrote or submitted this offer letter cannot approve it.']);
            }

            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employees(User $actor): array
    {
        if (! $this->ready() || ! $this->allowed($actor)) {
            return [];
        }

        $companyId = (int) $actor->companyId();
        $salaries = $this->currentSalaries($companyId);

        return Employee::query()
            ->where('tenant_id', $companyId)
            ->with(['department', 'designation', 'office', 'manager'])
            ->orderBy('employee_code')
            ->get()
            ->map(function (Employee $employee) use ($salaries): array {
                $profile = $this->employeeProfile($employee);
                $name = trim($employee->first_name.' '.$employee->last_name);

                return [
                    'id' => (int) $employee->id,
                    'label' => $employee->employee_code.' · '.$name,
                    'code' => (string) $employee->employee_code,
                    'name' => $name,
                    'email' => $profile['email'],
                    'phone' => $profile['phone'],
                    'department' => $profile['department'],
                    'designation' => $profile['designation'],
                    'manager' => $profile['manager'],
                    'joiningDate' => $employee->date_of_joining?->toDateString(),
                    'employmentType' => $profile['employmentType'],
                    'employmentTypeLabel' => $this->choiceLabel($profile['employmentType'], [
                        'full_time' => 'Full time',
                        'part_time' => 'Part time',
                        'contract' => 'Contract',
                    ]),
                    'workLocation' => $profile['workLocation'],
                    'workMode' => $profile['workMode'],
                    'workModeLabel' => $this->choiceLabel($profile['workMode'], [
                        'office' => 'Office',
                        'hybrid' => 'Hybrid',
                        'remote' => 'Remote',
                    ]),
                    'salary' => $salaries[$employee->id] ?? null,
                    'workingHours' => $this->recordedHours($employee),
                ];
            })->all();
    }

    /**
     * @return array{india: string, usa: string}
     */
    public function signatories(User $actor): array
    {
        $names = ['india' => '', 'usa' => ''];

        if (! $this->allowed($actor) || ! Schema::hasTable('company_legal_profiles')) {
            return $names;
        }

        $rows = DB::table('company_legal_profiles')->where('tenant_id', $actor->companyId())->get();

        foreach ($rows as $row) {
            $country = Schema::hasColumn('company_legal_profiles', 'country') ? (string) ($row->country ?? 'india') : 'india';

            if (array_key_exists($country, $names)) {
                $names[$country] = (string) ($row->signatory_name ?? '');
            }
        }

        return $names;
    }

    /**
     * Saved company shifts HR may select. A shift without both clock times is omitted.
     *
     * @return list<array{id: int, name: string, hours: string}>
     */
    public function shifts(User $actor): array
    {
        if (! $this->allowed($actor) || $actor->companyId() === null || ! Schema::hasTable('shifts')) {
            return [];
        }

        return DB::table('shifts')
            ->where('tenant_id', $actor->companyId())
            ->orderBy('name')
            ->get()
            ->map(function (object $shift): ?array {
                $hours = $this->hoursPhrase($shift->start_time ?? null, $shift->end_time ?? null);

                if ($hours === null) {
                    return null;
                }

                return [
                    'id' => (int) $shift->id,
                    'name' => (string) $shift->name,
                    'hours' => $hours,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Offer letters already stored for one employee. Salary figures stay on the offer-letter page.
     *
     * @return list<array{id: int, position: string, status: string, version: int}>
     */
    public function forEmployee(User $actor, int $employeeId): array
    {
        if (! $this->ready() || ! $this->allowed($actor) || $actor->companyId() === null) {
            return [];
        }

        return DB::table('offer_letters')
            ->where('tenant_id', $actor->companyId())
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get(['id', 'position_code', 'status', 'version'])
            ->map(fn ($letter) => [
                'id' => (int) $letter->id,
                'position' => self::POSITIONS[$letter->position_code] ?? (string) $letter->position_code,
                'status' => $this->statusLabel((string) $letter->status),
                'version' => (int) $letter->version,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor): array
    {
        if (! $this->ready() || ! $this->allowed($actor)) {
            return [];
        }

        return DB::table('offer_letters')
            ->leftJoin('employees', 'employees.id', '=', 'offer_letters.employee_id')
            ->where('offer_letters.tenant_id', $actor->companyId())
            ->orderByDesc('offer_letters.id')
            ->limit(50)
            ->get(['offer_letters.*', 'employees.employee_code'])
            ->map(fn ($letter) => [
                'id' => (int) $letter->id,
                'employeeId' => $letter->employee_id === null ? null : (int) $letter->employee_id,
                'employeeCode' => $letter->employee_code === null ? null : (string) $letter->employee_code,
                'position' => self::POSITIONS[$letter->position_code] ?? $letter->position_code,
                'designation' => trim((string) ($this->attr($letter, 'designation_name') ?: (self::POSITIONS[$letter->position_code] ?? $letter->position_code))),
                'joiningDate' => $letter->joining_date === null ? null : (string) $letter->joining_date,
                'download' => in_array($letter->status, ['issued', 'accepted', 'declined'], true) && is_string($letter->file_path) && $letter->file_path !== '',
                'employeeName' => (string) $letter->employee_name,
                'status' => $this->statusLabel((string) $letter->status),
                'version' => (int) $letter->version,
                'monthly' => Money::inr((string) $letter->monthly_salary),
                'annual' => Money::inr((string) $letter->annual_ctc),
                'noticeMonths' => (int) $letter->notice_months,
                'noticeLabel' => $this->noticeLabel($letter),
                'country' => $this->countryOf($letter),
                'reference' => $this->attr($letter, 'reference_code'),
                'email' => $this->attr($letter, 'employee_email'),
                'createdAt' => (string) $letter->created_at,
                'issuedAt' => $letter->issued_at === null ? null : (string) $letter->issued_at,
                'submittedAt' => $this->attr($letter, 'submitted_at'),
                'approvedAt' => $this->attr($letter, 'approved_at'),
                'own' => (int) ($letter->created_by ?? 0) === (int) $actor->id,
                'needsCorrection' => $this->offerNeedsCorrection($letter),
                'issuable' => $letter->status === 'approved' && $this->readiness->ready((int) $actor->companyId(), $this->countryOf($letter)),
                'preview' => $letter->status === 'draft' ? null : (string) ($letter->issued_body ?: $letter->preview_body),
            ])->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function body(User $actor, array $values): string
    {
        $company = DB::table('tenants')->where('id', $actor->companyId())->value('name');
        $position = self::POSITIONS[$values['position_code']];

        return implode("\n", [
            'DRAFT — NOT APPROVED FOR ISSUANCE',
            '[PLACEHOLDER: approved appointment-letter wording is not on file]',
            'Company: '.($company ?: 'Not recorded'),
            'Position: '.$position,
            'Employee: '.$values['employee_name'],
            'Monthly salary: '.Money::inr((string) $values['monthly_salary']),
            'Annual amount (monthly x 12): '.Money::inr((string) $values['annual_ctc']),
            'Date of joining: '.$values['joining_date'],
            'Notice period: '.$this->noticeText($values),
            'HR: '.$values['hr_name'],
            'HR signature: '.$values['hr_signature'],
            '[PLACEHOLDER: duties for '.$position.' require the approved letter]',
            '[PLACEHOLDER: clauses, header, footer, and acceptance wording require the approved PDF]',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(User $actor, array $data): array
    {
        $companyId = (int) $actor->companyId();
        $employeeId = (int) $data['employee_id'];
        $employee = Employee::query()->whereKey($employeeId)->where('tenant_id', $companyId)->first();

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an employee from this company.']);
        }

        $this->assertOfferDates($data['joining_date'] ?? null, $data['date_of_issue'] ?? null);

        $name = trim($employee->first_name.' '.$employee->last_name);

        $monthly = Money::format(Money::cents((string) $data['monthly_salary']));
        $country = in_array($data['country'] ?? 'india', OfferLetterCatalog::COUNTRIES, true) ? $data['country'] : 'india';
        $hourly = $country === 'usa' && isset($data['hourly_rate']) && $data['hourly_rate'] !== null && $data['hourly_rate'] !== ''
            ? Money::format(Money::cents((string) $data['hourly_rate']))
            : null;
        $annual = $hourly !== null
            ? Money::format(Money::cents($hourly) * 2080)
            : Money::format(Money::cents($monthly) * 12);
        $profile = $this->employeeProfile($employee);
        $noticeDays = ($data['notice_unit'] ?? 'months') === 'days' ? (int) $data['notice_days'] : null;

        $values = [
            'employee_id' => $employeeId,
            'position_code' => $data['position_code'],
            'employee_name' => $name,
            'monthly_salary' => $monthly,
            'annual_ctc' => $annual,
            'joining_date' => $data['joining_date'],
            'notice_months' => $noticeDays !== null ? 0 : (int) ($data['notice_months'] ?? 3),
            'hr_name' => trim((string) $data['hr_name']),
            'hr_signature' => trim((string) $data['hr_signature']),
            ...$this->offerSnapshot($data, $profile, $noticeDays),
        ];

        if (! Schema::hasColumn('offer_letters', 'country')) {
            return $values;
        }

        return [
            ...$values,
            'country' => $country,
            'employee_email' => (string) ($employee->email ?? ''),
            'compensation_type' => $hourly !== null ? 'hourly' : ($country === 'usa' ? 'annual' : 'monthly'),
            'hourly_rate' => $hourly,
            'basic_salary' => $country === 'india' ? $this->moneyOrNull($data['basic_salary'] ?? null) : null,
            'hra' => $country === 'india' ? $this->moneyOrNull($data['hra'] ?? null) : null,
            'special_allowance' => $country === 'india' ? $this->moneyOrNull($data['special_allowance'] ?? null) : null,
            'probation_months' => isset($data['probation_months']) && $data['probation_months'] !== '' ? (int) $data['probation_months'] : null,
            'department_name' => array_key_exists('department', $data) ? $this->textOrNull($data['department']) : $profile['department'],
            'work_location' => array_key_exists('work_location', $data) ? $this->textOrNull($data['work_location']) : $profile['workLocation'],
            'employment_type' => array_key_exists('employment_type', $data) ? $this->textOrNull($data['employment_type']) : $profile['employmentType'],
            'work_mode' => Schema::hasColumn('offer_letters', 'work_mode')
                ? (array_key_exists('work_mode', $data) ? $this->textOrNull($data['work_mode']) : $profile['workMode'])
                : null,
            'working_hours' => Schema::hasColumn('offer_letters', 'working_hours') ? $this->textOrNull($data['working_hours'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{department: ?string, designation: ?string, manager: ?string, workLocation: ?string, employmentType: ?string, workMode: ?string, phone: ?string}  $profile
     * @return array<string, mixed>
     */
    private function offerSnapshot(array $data, array $profile, ?int $noticeDays): array
    {
        $snapshot = [];

        if (Schema::hasColumn('offer_letters', 'notice_days')) {
            $snapshot['notice_days'] = $noticeDays;
        }

        if (Schema::hasColumn('offer_letters', 'date_of_issue')) {
            $snapshot['date_of_issue'] = $this->textOrNull($data['date_of_issue'] ?? null);
        }

        if (Schema::hasColumn('offer_letters', 'designation_name')) {
            $snapshot['designation_name'] = array_key_exists('designation', $data) ? $this->textOrNull($data['designation']) : $profile['designation'];
        }

        if (Schema::hasColumn('offer_letters', 'reporting_manager_name')) {
            $snapshot['reporting_manager_name'] = array_key_exists('reporting_manager', $data) ? $this->textOrNull($data['reporting_manager']) : $profile['manager'];
        }

        if (Schema::hasColumn('offer_letters', 'employee_phone')) {
            $snapshot['employee_phone'] = $profile['phone'];
        }

        if (Schema::hasColumn('offer_letters', 'offer_notes')) {
            $snapshot['offer_notes'] = $this->textOrNull($data['offer_notes'] ?? null);
        }

        if (Schema::hasColumn('offer_letters', 'salutation')) {
            $snapshot['salutation'] = $this->salutationCode($data['salutation'] ?? null);
        }

        if (Schema::hasColumn('offer_letters', 'candidate_address')) {
            $snapshot['candidate_address'] = $this->textOrNull($data['candidate_address'] ?? null);
        }

        return $snapshot;
    }

    private function previewBody(User $actor, object $letter): string
    {
        $country = $this->countryOf($letter);
        $template = $this->catalog->activeTemplate((int) $letter->tenant_id, $country);

        if ($template === null || ! $this->readiness->ready((int) $letter->tenant_id, $country)) {
            return $this->body($actor, [
                'position_code' => $letter->position_code,
                'employee_name' => $letter->employee_name,
                'monthly_salary' => $letter->monthly_salary,
                'annual_ctc' => $letter->annual_ctc,
                'joining_date' => $letter->joining_date,
                'notice_months' => $letter->notice_months,
                'notice_days' => $this->attr($letter, 'notice_days'),
                'hr_name' => $letter->hr_name,
                'hr_signature' => $letter->hr_signature,
            ]);
        }

        return $this->catalog->render((string) $template->body, $this->placeholderMap($letter, true));
    }

    private function editorBody(object $letter): string
    {
        $stored = (string) ($letter->issued_body ?: $letter->preview_body);

        if (in_array($letter->status, ['issued', 'accepted', 'declined'], true) && $stored !== '') {
            return $stored;
        }

        if (OfferLetterMarkup::looksLikeHtml($stored)) {
            return $stored;
        }

        try {
            return $this->letterDocument($letter);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['letter'])) {
                throw $exception;
            }

            return '<p>'.e(OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED).'</p>';
        }
    }

    private function letterDocument(object $letter): string
    {
        return $this->catalog->render($this->letterSource($letter), $this->placeholderMap($letter, false));
    }

    private function creatableSource(object $letter): string
    {
        $template = $this->frozenTemplate($letter, $this->countryOf($letter));

        return trim((string) ($template->body ?? ''));
    }

    private function letterSource(object $letter): string
    {
        $country = $this->countryOf($letter);
        $template = $this->frozenTemplate($letter, $country);
        $body = trim((string) ($template->body ?? ''));

        if ($country === 'india' && $body === '') {
            throw ValidationException::withMessages(['letter' => OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED]);
        }

        if ($body === '') {
            $body = "Dear {{employee_name}},\n\nWe offer the role of {{designation}}.\nJoining date: {{joining_date}}\nCompensation: {{salary}}\nAnnual amount: {{annual_ctc}}\n\n{{company_legal_name}}\n{{company_address}}\n{{hr_name}}";
        }

        return OfferLetterMarkup::looksLikeHtml($body) ? $body : $this->paragraphs($body);
    }

    private function paragraphs(string $text): string
    {
        $parts = preg_split("/\n{2,}/", trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return '<p></p>';
        }

        return implode('', array_map(function (string $part): string {
            return '<p>'.nl2br(htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>';
        }, $parts));
    }

    /**
     * @return array<string, string>
     */
    private function placeholderMap(object $letter, bool $verifiedOnly): array
    {
        $country = $this->countryOf($letter);
        $legal = $this->legalProfile((int) $letter->tenant_id, $country, $verifiedOnly);
        $company = DB::table('tenants')->where('id', $letter->tenant_id)->first();
        $timezone = is_string($company->timezone ?? null) && $company->timezone !== '' ? $company->timezone : 'Asia/Kolkata';
        $india = $country === 'india';
        $code = $letter->employee_id === null
            ? ''
            : (string) DB::table('employees')->where('tenant_id', $letter->tenant_id)->where('id', $letter->employee_id)->value('employee_code');

        return [
            'employee_name' => (string) $letter->employee_name,
            'employee_id' => $code,
            'employee_email' => (string) ($this->attr($letter, 'employee_email') ?? ''),
            'company_name' => (string) ($company->name ?? ''),
            'company_legal_name' => (string) ($legal->legal_name ?? ''),
            'company_address' => (string) ($legal->legal_address ?? ''),
            'designation' => (string) ($this->attr($letter, 'designation_name') ?: (self::POSITIONS[$letter->position_code] ?? $letter->position_code)),
            'department' => (string) ($this->attr($letter, 'department_name') ?? ''),
            'reporting_manager' => (string) ($this->attr($letter, 'reporting_manager_name') ?? ''),
            'employment_type' => $this->employmentLabel($this->attr($letter, 'employment_type')),
            'salary' => (string) ($this->attr($letter, 'hourly_rate') ?: $letter->monthly_salary),
            'monthly_salary' => $india ? IndianAmount::figures((string) $letter->monthly_salary) : (string) $letter->monthly_salary,
            'salary_in_words' => $india ? IndianAmount::words((string) $letter->monthly_salary) : '',
            'annual_ctc' => $india ? IndianAmount::figures((string) $letter->annual_ctc) : (string) $letter->annual_ctc,
            'annual_ctc_in_words' => $india ? IndianAmount::words((string) $letter->annual_ctc) : '',
            'basic_salary' => (string) ($this->attr($letter, 'basic_salary') ?? ''),
            'hra' => (string) ($this->attr($letter, 'hra') ?? ''),
            'special_allowance' => (string) ($this->attr($letter, 'special_allowance') ?? ''),
            'joining_date' => $this->longDate($letter->joining_date),
            'date_of_issue' => $this->attr($letter, 'date_of_issue')
                ? $this->longDate($this->attr($letter, 'date_of_issue'))
                : now()->timezone($timezone)->format('F d, Y'),
            'notice_period' => $this->noticePhrase($letter),
            'probation_period' => $this->attr($letter, 'probation_months') !== null ? $this->periodPhrase((int) $this->attr($letter, 'probation_months')) : '',
            'work_location' => (string) ($this->attr($letter, 'work_location') ?? ''),
            'work_mode' => $this->workModeLabel($this->attr($letter, 'work_mode') ?: $this->attr($letter, 'employment_type')),
            'working_hours' => (string) ($this->attr($letter, 'working_hours') ?? ''),
            'signatory_name' => (string) ($legal->signatory_name ?? ''),
            'signatory_designation' => (string) ($legal->signatory_designation ?? ''),
            'hr_name' => (string) $letter->hr_name,
            'hr_email' => (string) ($legal->hr_email ?? ''),
            'salutation_line' => $this->salutationLine($letter),
            'candidate_address' => (string) ($this->attr($letter, 'candidate_address') ?? ''),
        ];
    }

    private function salutationLine(object $letter): string
    {
        $name = trim((string) ($letter->employee_name ?? ''));
        $labels = ['mr' => 'Mr.', 'ms' => 'Ms.', 'mrs' => 'Mrs.', 'dr' => 'Dr.'];
        $code = strtolower(trim((string) ($this->attr($letter, 'salutation') ?? '')));

        if (! isset($labels[$code])) {
            return 'Dear '.$name.',';
        }

        return 'Dear '.$labels[$code].' '.$name.',';
    }

    private function salutationCode(mixed $value): ?string
    {
        $code = strtolower(trim((string) $value));

        return in_array($code, ['mr', 'ms', 'mrs', 'dr'], true) ? $code : null;
    }

    /**
     * @return list<string>
     */
    private function generationBlocks(object $letter): array
    {
        $country = $this->countryOf($letter);
        $blocks = [];

        if ($country === 'india' && $this->catalog->activeTemplate((int) $letter->tenant_id, 'india') === null && ($letter->template_version_id ?? null) === null) {
            $blocks[] = OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED;
        }

        if ($country === 'india') {
            $brand = $this->catalog->letterheadIssueBlock((int) $letter->tenant_id, 'india');

            if ($brand !== null) {
                $blocks[] = $brand;
            } elseif ($this->catalog->letterheadConfirmationPending((int) $letter->tenant_id, 'india')) {
                $blocks[] = 'A company admin must confirm that the active letterhead belongs to the legal company before a PDF can be generated.';
            }
        }

        if ($this->offerNeedsCorrection($letter)) {
            $blocks[] = 'The joining date must be later than the date of issue. Correct this offer before a PDF is generated. No PDF is created by the correction.';
        }

        if ($this->approvalRequired((int) $letter->tenant_id) && ! in_array((string) $letter->status, ['approved', 'issued', 'accepted', 'declined'], true)) {
            $blocks[] = 'A different authorized person must approve this offer before the PDF is generated.';
        }

        if ($this->approvalRequired((int) $letter->tenant_id) && ! $this->readiness->ready((int) $letter->tenant_id, $country)) {
            $blocks[] = 'The appointment template must be approved and active, and the legal details must be verified by someone who did not save them.';
        }

        return $blocks;
    }

    private function legalProfile(int $companyId, string $country, bool $verifiedOnly): ?object
    {
        if (! Schema::hasTable('company_legal_profiles')) {
            return null;
        }

        $query = DB::table('company_legal_profiles')->where('tenant_id', $companyId);

        if ($verifiedOnly) {
            $query->where('status', 'verified');
        }

        if (Schema::hasColumn('company_legal_profiles', 'country')) {
            $query->where('country', $country);
        }

        return $query->first();
    }

    private function frozenTemplate(object $letter, string $country): ?object
    {
        if (Schema::hasColumn('offer_letters', 'template_version_id') && ($letter->template_version_id ?? null) !== null) {
            $stored = DB::table('offer_letter_template_versions')
                ->where('tenant_id', $letter->tenant_id)
                ->where('id', $letter->template_version_id)
                ->first();

            if ($stored !== null) {
                return $stored;
            }
        }

        return $this->catalog->activeTemplate((int) $letter->tenant_id, $country);
    }

    private function renderLetterPdf(object $letter, string $body): string
    {
        $country = $this->countryOf($letter);
        $template = $this->frozenTemplate($letter, $country);
        $letterheadId = $template !== null && $template->letterhead_id !== null ? (int) $template->letterhead_id : null;

        return (new OfferLetterPdf)->render($body, null, [], [
            ...$this->catalog->printLayout((int) $letter->tenant_id, $country, $letterheadId),
            'signature' => $this->catalog->signatureLayout((int) $letter->tenant_id, $country),
        ]);
    }

    /**
     * @return array{id: int|null, mime: string|null, pageWidth: float|null, pageHeight: float|null, marginTop: int, marginRight: int, marginBottom: int, marginLeft: int, headerHeight: int, footerHeight: int}
     */
    private function activeLetterhead(object $letter, string $country): array
    {
        $empty = [
            'id' => null,
            'mime' => null,
            'pageWidth' => null,
            'pageHeight' => null,
            'marginTop' => $country === 'india' ? 110 : 72,
            'marginRight' => $country === 'india' ? 54 : 48,
            'marginBottom' => $country === 'india' ? 70 : 56,
            'marginLeft' => $country === 'india' ? 54 : 48,
            'headerHeight' => 0,
            'footerHeight' => 0,
        ];
        if (! Schema::hasTable('offer_letterheads')) {
            return $empty;
        }

        $template = $this->frozenTemplate($letter, $country);
        $query = DB::table('offer_letterheads')->where('tenant_id', $letter->tenant_id);

        if ($template !== null && $template->letterhead_id !== null) {
            $query->where('id', $template->letterhead_id);
        } else {
            $query->where('country', $country)->where('status', 'active');
        }

        $row = $query->first();

        if ($row === null || ! str_starts_with((string) $row->mime, 'image/')) {
            return $empty;
        }

        return [
            'id' => (int) $row->id,
            'mime' => (string) $row->mime,
            'pageWidth' => isset($row->page_width) ? (float) $row->page_width : null,
            'pageHeight' => isset($row->page_height) ? (float) $row->page_height : null,
            'marginTop' => (int) $row->margin_top,
            'marginRight' => (int) $row->margin_right,
            'marginBottom' => (int) $row->margin_bottom,
            'marginLeft' => (int) $row->margin_left,
            'headerHeight' => (int) ($row->header_height ?? 0),
            'footerHeight' => (int) ($row->footer_height ?? 0),
        ];
    }

    private function employmentLabel(mixed $value): string
    {
        return match ((string) $value) {
            'full_time' => 'Full time',
            'part_time' => 'Part time',
            'contract' => 'Contract',
            default => (string) $value,
        };
    }

    private function workModeLabel(mixed $value): string
    {
        return match ((string) $value) {
            'office' => 'Work From Office',
            'hybrid' => 'Hybrid',
            'remote' => 'Remote',
            default => (string) $value,
        };
    }

    private function longDate(mixed $value): string
    {
        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            return '';
        }

        $date = $this->dateOnly($value);

        return $date === null ? '' : Carbon::parse($date)->format('d F Y');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function correctionState(User $actor, object $letter): ?array
    {
        if (! $this->offerNeedsCorrection($letter)) {
            return null;
        }

        $salary = $letter->employee_id === null
            ? null
            : ($this->currentSalaries((int) $letter->tenant_id)[(int) $letter->employee_id] ?? null);
        $offerBasic = $this->moneyOrNull($this->attr($letter, 'basic_salary'));
        $offerHra = $this->moneyOrNull($this->attr($letter, 'hra'));
        $offerSpecial = $this->moneyOrNull($this->attr($letter, 'special_allowance'));
        $differs = $salary !== null && (
            $offerBasic !== $salary['basic']
            || $offerHra !== $salary['hra']
            || $offerSpecial !== $salary['special']
        );

        return [
            'joiningDate' => $this->dateOnly($letter->joining_date) ?? '',
            'dateOfIssue' => $this->dateOnly($this->attr($letter, 'date_of_issue')) ?? '',
            'salaryDiffers' => $differs,
            'offerSalary' => [
                'basic' => $offerBasic,
                'hra' => $offerHra,
                'special' => $offerSpecial,
                'monthly' => (string) $letter->monthly_salary,
            ],
            'currentSalary' => $salary === null ? null : [
                'basic' => $salary['basic'],
                'hra' => $salary['hra'],
                'special' => $salary['special'],
                'monthly' => $salary['monthly'],
                'effectiveFrom' => $salary['effectiveFrom'],
            ],
            'canCorrect' => $this->allowed($actor),
        ];
    }

    private function offerNeedsCorrection(object $letter): bool
    {
        if ($this->letterHasIssuedFile($letter) || ! in_array((string) $letter->status, ['generated', 'pending_approval', 'approved'], true)) {
            return false;
        }

        $joining = $this->dateOnly($letter->joining_date);
        $issue = $this->dateOnly($this->attr($letter, 'date_of_issue'));

        return $joining === null || $issue === null || $joining <= $issue;
    }

    private function letterHasIssuedFile(object $letter): bool
    {
        return $letter->issued_body !== null || (is_string($letter->file_path) && $letter->file_path !== '');
    }

    private function assertJoiningAfterIssue(object $letter): void
    {
        $this->assertOfferDates($letter->joining_date, $this->attr($letter, 'date_of_issue'));
    }

    private function assertOfferDates(mixed $joining, mixed $issue): void
    {
        $joiningDate = $this->dateOnly($joining);
        $issueDate = $this->dateOnly($issue);

        if ($joiningDate === null || $issueDate === null || $joiningDate > $issueDate) {
            return;
        }

        throw ValidationException::withMessages([
            'joining_date' => 'Joining date must be later than the date of issue.',
        ]);
    }

    private function dateOnly(mixed $value): ?string
    {
        $text = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
    }

    private function periodPhrase(int $months): string
    {
        $words = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve'];
        $word = $words[$months] ?? (string) $months;

        return $word.' '.($months === 1 ? 'month' : 'months');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function noticeText(array $values): string
    {
        $days = isset($values['notice_days']) ? (int) $values['notice_days'] : 0;

        if ($days > 0) {
            return $days.' '.($days === 1 ? 'day' : 'days');
        }

        $months = (int) ($values['notice_months'] ?? 0);

        return $months.' '.($months === 1 ? 'month' : 'months');
    }

    private function noticePhrase(object $letter): string
    {
        $days = $this->attr($letter, 'notice_days');

        if (is_numeric($days) && (int) $days > 0) {
            $count = (int) $days;

            return $count.' '.($count === 1 ? 'day' : 'days');
        }

        return $this->periodPhrase((int) $letter->notice_months);
    }

    private function noticeLabel(object $letter): string
    {
        $days = $this->attr($letter, 'notice_days');

        if (is_numeric($days) && (int) $days > 0) {
            $count = (int) $days;

            return $count.' '.($count === 1 ? 'day' : 'days');
        }

        $months = (int) $letter->notice_months;

        return $months.' '.($months === 1 ? 'month' : 'months');
    }

    private function countryOf(object $letter): string
    {
        $country = $this->attr($letter, 'country') ?? 'india';

        return in_array($country, OfferLetterCatalog::COUNTRIES, true) ? $country : 'india';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_approval' => 'Pending approval',
            default => ucfirst($status),
        };
    }

    private function attr(object $letter, string $key): mixed
    {
        return property_exists($letter, $key) ? $letter->{$key} : null;
    }

    /**
     * @return array{path: ?string, margins: array{top?: int, right?: int, bottom?: int, left?: int}}
     */
    private function letterheadLayout(?object $template): array
    {
        $empty = ['path' => null, 'margins' => []];

        if ($template === null || $template->letterhead_id === null) {
            return $empty;
        }

        $row = DB::table('offer_letterheads')->where('id', $template->letterhead_id)->where('tenant_id', $template->tenant_id)->first();

        if ($row === null) {
            return $empty;
        }

        $margins = [
            'top' => (int) $row->margin_top,
            'right' => (int) $row->margin_right,
            'bottom' => (int) $row->margin_bottom,
            'left' => (int) $row->margin_left,
        ];
        $path = $row->background_path;

        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return ['path' => null, 'margins' => $margins];
        }

        $absolute = Storage::disk('local')->path($path);

        return [
            'path' => is_file($absolute) ? $absolute : null,
            'margins' => $margins,
        ];
    }

    private function moneyOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::format(Money::cents((string) $value));
    }

    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $values
     */
    private function assertAppointment(User $actor, array $data, array $values): void
    {
        if (($data['flow'] ?? null) !== 'appointment' || ($values['country'] ?? 'india') !== 'india') {
            return;
        }

        $letter = (object) [
            ...$values,
            'tenant_id' => (int) $actor->companyId(),
            'issued_body' => null,
            'preview_body' => null,
        ];
        $missing = OfferLetterCatalog::missingValues(IndiaAppointment::html(), $this->placeholderMap($letter, false));
        $errors = $missing === [] ? [] : $this->placeholderErrors($missing);

        if (trim((string) ($values['designation_name'] ?? '')) === '') {
            $errors['designation'] = 'Enter the designation for this offer. The employee record is not changed.';
        }

        if ((int) ($values['notice_months'] ?? 0) < 1 && (int) ($values['notice_days'] ?? 0) < 1) {
            $errors['notice_months'] = 'Enter the notice period for this offer.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertCreatable(User $actor, array $values): void
    {
        $letter = (object) [
            ...$values,
            'tenant_id' => (int) $actor->companyId(),
            'issued_body' => null,
            'preview_body' => null,
        ];
        $missing = OfferLetterCatalog::missingValues($this->creatableSource($letter), $this->placeholderMap($letter, false));

        if ($missing !== []) {
            throw ValidationException::withMessages($this->placeholderErrors($missing));
        }
    }

    /**
     * @param  list<string>  $missing
     * @return array<string, string>
     */
    private function placeholderErrors(array $missing): array
    {
        $errors = ['letter' => OfferLetterCatalog::unresolvedMessage($missing)];
        $fields = [
            'working_hours' => 'working_hours',
            'work_mode' => 'work_mode',
            'designation' => 'designation',
            'probation_period' => 'probation_months',
            'notice_period' => 'notice_months',
            'joining_date' => 'joining_date',
            'monthly_salary' => 'monthly_salary',
            'salary_in_words' => 'monthly_salary',
            'annual_ctc' => 'monthly_salary',
            'annual_ctc_in_words' => 'monthly_salary',
            'date_of_issue' => 'date_of_issue',
            'employee_name' => 'employee_id',
            'hr_name' => 'hr_name',
        ];

        foreach ($missing as $key) {
            $field = $fields[$key] ?? null;

            if ($field !== null) {
                $errors[$field] = OfferLetterCatalog::fieldMessage($key);
            }
        }

        return $errors;
    }

    private function assertResolvedBody(string $body): void
    {
        $missing = OfferLetterCatalog::unresolvedTokens($body);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'letter' => OfferLetterCatalog::unresolvedMessage($missing),
                'body' => OfferLetterCatalog::unresolvedMessage($missing),
            ]);
        }
    }

    private function recordedHours(Employee $employee): ?string
    {
        if (! Schema::hasTable('shifts')) {
            return null;
        }

        $shift = null;

        if (Schema::hasTable('shift_assignments')) {
            $today = now()->toDateString();
            $assignment = DB::table('shift_assignments')
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->where('effective_from', '<=', $today)
                ->where(function ($query) use ($today): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                })
                ->orderByDesc('effective_from')
                ->first();

            if ($assignment !== null) {
                $shift = DB::table('shifts')->where('tenant_id', $employee->tenant_id)->where('id', $assignment->shift_id)->first();
            }
        }

        if ($shift === null && Schema::hasColumn('employees', 'shift_id') && $employee->shift_id) {
            $shift = DB::table('shifts')->where('tenant_id', $employee->tenant_id)->where('id', $employee->shift_id)->first();
        }

        if ($shift === null) {
            return null;
        }

        return $this->hoursPhrase($shift->start_time ?? null, $shift->end_time ?? null);
    }

    private function hoursPhrase(mixed $start, mixed $end): ?string
    {
        if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
            return null;
        }

        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);

        if (! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end) || $start === $end) {
            return null;
        }

        return $this->twelveHour($start).' to '.$this->twelveHour($end);
    }

    private function twelveHour(string $clock): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $clock));
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $hour = $hour % 12;

        if ($hour === 0) {
            $hour = 12;
        }

        return $hour.':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).' '.$suffix;
    }

    /**
     * @return array{department: ?string, designation: ?string, manager: ?string, workLocation: ?string, employmentType: ?string, workMode: ?string, phone: ?string, email: ?string}
     */
    private function employeeProfile(Employee $employee): array
    {
        $employee->loadMissing(['department', 'designation', 'office', 'manager']);
        $manager = $employee->manager;

        return [
            'department' => $this->textOrNull($employee->department?->name),
            'designation' => $this->textOrNull($employee->designation?->name),
            'manager' => $manager === null ? null : $this->textOrNull(trim($manager->first_name.' '.$manager->last_name)),
            'workLocation' => $this->textOrNull($employee->office?->name),
            'employmentType' => $this->textOrNull($employee->employment_type),
            'workMode' => $this->textOrNull($employee->work_mode),
            'phone' => $this->textOrNull($employee->phone),
            'email' => $this->textOrNull($employee->email),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function choiceLabel(?string $value, array $labels): ?string
    {
        if ($value === null) {
            return null;
        }

        return $labels[$value] ?? $value;
    }

    /**
     * Latest salary already in effect today, using the payroll rule: the newest
     * effective date on or before today. Future and superseded rows are not returned.
     * Salary records have no approval status. Monthly pay is basic + HRA + special allowance.
     *
     * @return array<int, array{basic: string, hra: string, special: string, monthly: string, currency: string, effectiveFrom: string, appliesTo: string}>
     */
    private function currentSalaries(int $companyId): array
    {
        if (! Schema::hasTable('salary_structures')) {
            return [];
        }

        $today = now()->toDateString();
        $grouped = DB::table('salary_structures')
            ->where('tenant_id', $companyId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');
        $current = [];

        foreach ($grouped as $employeeId => $rows) {
            $row = $rows->first(function ($item) use ($today): bool {
                $effective = substr((string) ($item->effective_from ?? ''), 0, 10);

                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective) === 1 && $effective <= $today;
            });

            if ($row === null) {
                continue;
            }

            $basic = $this->moneyOrNull($row->basic ?? null);
            $hra = $this->moneyOrNull($row->hra ?? null);
            $special = $this->moneyOrNull($row->special_allowance ?? null);
            $currency = strtoupper((string) ($row->currency ?? ''));
            $appliesTo = match ($currency) {
                'INR' => 'india',
                'USD' => 'usa',
                default => null,
            };

            if ($basic === null || $hra === null || $special === null || $appliesTo === null) {
                continue;
            }

            $current[(int) $employeeId] = [
                'basic' => $basic,
                'hra' => $hra,
                'special' => $special,
                'monthly' => Money::format(Money::cents($basic) + Money::cents($hra) + Money::cents($special)),
                'currency' => $currency,
                'effectiveFrom' => substr((string) $row->effective_from, 0, 10),
                'appliesTo' => $appliesTo,
            ];
        }

        return $current;
    }

    private function attachGeneratedCopy(User $actor, object $letter, string $path): void
    {
        if ($letter->employee_id === null || ! $this->onboarding->ready()) {
            return;
        }

        $employee = Employee::query()->whereKey($letter->employee_id)->where('tenant_id', $letter->tenant_id)->first();

        if ($employee === null) {
            return;
        }

        $this->onboarding->open($actor, $employee);

        $alreadyStored = DB::table('employee_documents')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('file_path', $path)->exists();

        if (! $alreadyStored) {
            DB::table('employee_documents')->insert([
                'tenant_id' => $letter->tenant_id,
                'employee_id' => $employee->id,
                'category' => 'generated_offer',
                'title' => 'Generated offer letter',
                'file_path' => $path,
                'original_name' => 'offer-letter-v'.$letter->version.'.pdf',
                'review_status' => 'verified',
                'uploaded_by' => $actor->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->onboarding->record($employee, $actor, 'offer_issued', (string) $letter->position_code);
        }

        $this->onboarding->sync($employee);
    }

    private function lock(User $actor, int $letterId): object
    {
        $this->guard($actor);
        $letter = DB::table('offer_letters')->where('tenant_id', $actor->companyId())->where('id', $letterId)->lockForUpdate()->first();
        abort_if($letter === null, 404);

        return $letter;
    }

    private function find(User $actor, int $letterId): object
    {
        $this->guard($actor);
        $letter = DB::table('offer_letters')->where('tenant_id', $actor->companyId())->where('id', $letterId)->first();
        abort_if($letter === null, 404);

        return $letter;
    }

    private function assertOpen(object $letter): void
    {
        if (! in_array($letter->status, ['draft', 'generated'], true) || $letter->issued_body !== null) {
            throw ValidationException::withMessages(['letter' => 'An issued letter cannot be changed. Create a new version.']);
        }
    }

    private function guard(User $actor): void
    {
        abort_unless($this->ready() && $this->allowed($actor), 404);
    }

    private function allowed(User $actor): bool
    {
        return $actor->companyId() !== null && in_array($actor->applicationRole(), ['company_admin', 'hr'], true);
    }
}
