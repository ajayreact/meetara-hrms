<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferLetterCatalog
{
    public const COUNTRIES = ['usa', 'india'];

    public const INDIA_TEMPLATE = 'India Appointment Letter';

    public const INDIA_TEMPLATE_REQUIRED = 'The India Appointment Letter must be approved and activated before a new offer can use it.';

    public const PLACEHOLDERS = [
        'employee_name', 'employee_id', 'employee_email', 'company_name', 'company_legal_name',
        'company_address', 'designation', 'department', 'reporting_manager', 'employment_type', 'salary', 'monthly_salary',
        'salary_in_words', 'annual_ctc', 'annual_ctc_in_words', 'basic_salary', 'hra', 'special_allowance',
        'joining_date', 'date_of_issue', 'notice_period', 'probation_period', 'work_location', 'work_mode',
        'working_hours', 'signatory_name', 'signatory_designation', 'hr_name', 'hr_email',
        'salutation_line', 'candidate_address',
    ];

    public const PLACEHOLDER_LABELS = [
        'employee_name' => 'employee name',
        'employee_id' => 'employee ID',
        'employee_email' => 'employee email',
        'company_name' => 'company name',
        'company_legal_name' => 'company legal name',
        'company_address' => 'company address',
        'designation' => 'designation',
        'department' => 'department',
        'reporting_manager' => 'reporting manager',
        'employment_type' => 'employment type',
        'salary' => 'salary',
        'monthly_salary' => 'monthly salary',
        'salary_in_words' => 'salary in words',
        'annual_ctc' => 'annual CTC',
        'annual_ctc_in_words' => 'annual CTC in words',
        'basic_salary' => 'basic salary',
        'hra' => 'HRA',
        'special_allowance' => 'special allowance',
        'joining_date' => 'joining date',
        'date_of_issue' => 'date of issue',
        'notice_period' => 'notice period',
        'probation_period' => 'probation period',
        'work_location' => 'work location',
        'work_mode' => 'work arrangement',
        'working_hours' => 'working hours',
        'signatory_name' => 'signatory name',
        'signatory_designation' => 'signatory designation',
        'hr_name' => 'HR name',
        'hr_email' => 'HR email',
        'salutation_line' => 'salutation',
        'candidate_address' => 'candidate address',
    ];

    public function __construct(private readonly OfferLetterReadiness $readiness) {}

    /**
     * @return array<string, mixed>
     */
    public function page(User $actor, ?string $country): array
    {
        $this->guard($actor);
        $selected = in_array($country, self::COUNTRIES, true) ? $country : 'india';
        $companyId = (int) $actor->companyId();

        return [
            'country' => $selected,
            'placeholders' => self::PLACEHOLDERS,
            'sample' => $this->sample($companyId, $selected),
            'legal' => $this->legal($companyId, $selected),
            'legalEvents' => $this->legalEvents($companyId),
            'letterheads' => $this->letterheads($companyId, $selected),
            'templates' => $this->templates($companyId, $selected),
            'issuance' => $this->readiness->present($companyId, $selected),
            'indiaSource' => $selected === 'india' ? IndiaAppointment::json() : null,
            'indiaHtml' => $selected === 'india' ? IndiaAppointment::html() : null,
            'templateHistory' => $this->templateHistory($companyId, $selected),
            'mailNote' => 'Email delivery is not used for offer letters. Download the PDF and send it from the company email account.',
            'offerApprovals' => $this->pendingApprovals($actor),
        ];
    }

    /**
     * India legal details and appointment letters waiting for a different authorized person.
     * Verified legal records and approved templates are left out.
     *
     * @return array{legal: ?array<string, mixed>, templates: list<array<string, mixed>>}
     */
    public function pendingApprovals(User $actor): array
    {
        $empty = ['legal' => null, 'templates' => []];

        if ($actor->companyId() === null || ! in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            return $empty;
        }

        $companyId = (int) $actor->companyId();
        $legal = $this->legal($companyId, 'india');
        $pendingLegal = null;

        if (is_array($legal) && ($legal['statusKey'] ?? '') === 'pending_verification' && ! ($legal['savedByHead'] ?? false)) {
            $pendingLegal = [
                'legalName' => (string) $legal['legalName'],
                'legalAddress' => (string) $legal['legalAddress'],
                'registrationDetails' => (string) $legal['registrationDetails'],
                'signatoryName' => (string) $legal['signatoryName'],
                'signatoryDesignation' => (string) $legal['signatoryDesignation'],
                'hrEmail' => (string) $legal['hrEmail'],
                'updatedAt' => $legal['updatedAt'],
                'own' => (int) ($legal['updatedBy'] ?? 0) === (int) $actor->id,
            ];
        }

        $templates = [];

        foreach ($this->templates($companyId, 'india') as $template) {
            if (($template['status'] ?? '') !== 'submitted' || ($template['submittedByHead'] ?? false)) {
                continue;
            }

            $templates[] = [
                'id' => (int) $template['id'],
                'name' => (string) $template['name'],
                'updatedAt' => $template['updatedAt'] ?? null,
                'own' => (int) ($template['createdBy'] ?? 0) === (int) $actor->id
                    || (int) ($template['submittedBy'] ?? 0) === (int) $actor->id,
            ];
        }

        return ['legal' => $pendingLegal, 'templates' => $templates];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveLegal(User $actor, array $input): void
    {
        $this->guard($actor);
        $companyId = (int) $actor->companyId();
        $country = $this->normalizeCountry($input['country'] ?? 'india');
        $current = $this->profileQuery($companyId, $country)->first();
        $previous = $current === null ? null : $this->legalSnapshot($current);
        $activate = $country === 'india' && $this->headOfOperations($actor);
        $values = [
            'legal_name' => trim((string) $input['legal_name']),
            'legal_address' => trim((string) $input['legal_address']),
            'registration_details' => $this->blank($input['registration_details'] ?? null),
            'signatory_name' => trim((string) $input['signatory_name']),
            'signatory_designation' => trim((string) $input['signatory_designation']),
            'hr_email' => trim((string) $input['hr_email']),
            'status' => $activate ? 'verified' : 'draft',
            'updated_by' => $actor->id,
            'verified_by' => $activate ? $actor->id : null,
            'verified_at' => $activate ? now() : null,
            'updated_at' => now(),
            ...$this->clearedLetterheadConfirmation(),
        ];

        if ($current === null) {
            DB::table('company_legal_profiles')->insert([
                ...$values,
                'tenant_id' => $companyId,
                ...(Schema::hasColumn('company_legal_profiles', 'country') ? ['country' => $country] : []),
                'created_at' => now(),
            ]);
        } else {
            DB::table('company_legal_profiles')->where('id', $current->id)->update($values);
        }

        $this->legalEvent($companyId, $actor, 'saved', $previous, $values);
    }

    public function verifyLegal(User $actor, ?string $country = null): void
    {
        $this->guard($actor);
        $companyId = (int) $actor->companyId();
        $profile = $this->profileQuery($companyId, $this->normalizeCountry($country ?? 'india'))->first();

        if ($profile === null) {
            throw ValidationException::withMessages(['legal' => 'Save the legal details before they can be verified.']);
        }

        if (! $this->headOfOperations($actor)) {
            throw ValidationException::withMessages(['legal' => 'Only Head of Operations can approve company legal details.']);
        }

        if ((string) $profile->status === 'verified' && $profile->verified_by !== null && $profile->verified_at !== null) {
            return;
        }

        foreach (['legal_name', 'legal_address', 'signatory_name', 'signatory_designation', 'hr_email'] as $field) {
            if (trim((string) $profile->{$field}) === '') {
                throw ValidationException::withMessages(['legal' => 'Legal name, address, signatory, and HR email are required before verification.']);
            }
        }

        DB::table('company_legal_profiles')->where('id', $profile->id)->update([
            'status' => 'verified',
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'updated_at' => now(),
        ]);
        $this->legalEvent($companyId, $actor, 'verified', $this->legalSnapshot($profile), [
            'status' => 'verified',
            'verified_by' => $actor->id,
        ]);
    }

    public function storeLetterhead(User $actor, string $country, UploadedFile $file, array $margins): void
    {
        $this->guard($actor);
        $this->assertCountry($country);
        $companyId = (int) $actor->companyId();
        $stored = $this->storePrivateFile($file, 'offer-letterheads/'.$companyId);
        $version = ((int) DB::table('offer_letterheads')->where('tenant_id', $companyId)->where('country', $country)->max('version')) + 1;
        $absolute = Storage::disk('local')->path($stored['path']);
        $measured = $stored['mime'] === 'application/pdf'
            ? (new OfferLetterPdf)->inspectPdf($absolute)
            : ['pages' => 1, ...$this->imagePageSize($absolute), 'usable' => $stored['background'] !== null];

        DB::transaction(function () use ($actor, $country, $companyId, $stored, $version, $margins, $measured): void {
            DB::table('offer_letterheads')->where('tenant_id', $companyId)->where('country', $country)->where('status', 'active')->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);
            $row = [
                'tenant_id' => $companyId,
                'country' => $country,
                'version' => $version,
                'source_path' => $stored['path'],
                'background_path' => $stored['background'],
                'mime' => $stored['mime'],
                'original_name' => $stored['name'],
                'status' => 'active',
                'page_size' => $this->pageName((float) $measured['width'], (float) $measured['height']),
                'margin_top' => $margins['margin_top'],
                'margin_right' => $margins['margin_right'],
                'margin_bottom' => $margins['margin_bottom'],
                'margin_left' => $margins['margin_left'],
                'uploaded_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('offer_letterheads', 'page_width')) {
                $row['page_width'] = $measured['width'] > 0 ? $measured['width'] : null;
                $row['page_height'] = $measured['height'] > 0 ? $measured['height'] : null;
                $row['header_height'] = $margins['header_height'] ?? null;
                $row['footer_height'] = $margins['footer_height'] ?? null;
            }

            if (Schema::hasColumn('offer_letterheads', 'printed_legal_name')) {
                $row['printed_legal_name'] = $this->blank($margins['printed_legal_name'] ?? null);
                $row['printed_address'] = $this->blank($margins['printed_address'] ?? null);
            }

            DB::table('offer_letterheads')->insert($row);
            $this->clearLetterheadConfirmation($companyId, $country);
            $this->legalEvent($companyId, $actor, 'letterhead_uploaded', null, [
                'country' => $country,
                'version' => $version,
                'mime' => $stored['mime'],
                'page_size' => $row['page_size'],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveLetterheadLayout(User $actor, int $letterheadId, array $input): void
    {
        $this->guard($actor);
        $row = DB::table('offer_letterheads')->where('tenant_id', $actor->companyId())->where('id', $letterheadId)->first();
        abort_if($row === null, 404);
        $values = [
            'margin_top' => (int) $input['margin_top'],
            'margin_right' => (int) $input['margin_right'],
            'margin_bottom' => (int) $input['margin_bottom'],
            'margin_left' => (int) $input['margin_left'],
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('offer_letterheads', 'header_height')) {
            $values['header_height'] = (int) $input['header_height'];
            $values['footer_height'] = (int) $input['footer_height'];
            $values['page_width'] = $input['page_width'];
            $values['page_height'] = $input['page_height'];
            $values['page_size'] = $this->pageName((float) $input['page_width'], (float) $input['page_height']);
        }

        DB::table('offer_letterheads')->where('id', $row->id)->update($values);
        $this->legalEvent((int) $actor->companyId(), $actor, 'letterhead_layout', [
            'version' => (int) $row->version,
            'margin_top' => (int) $row->margin_top,
            'margin_bottom' => (int) $row->margin_bottom,
        ], [
            'version' => (int) $row->version,
            'margin_top' => $values['margin_top'],
            'margin_right' => $values['margin_right'],
            'margin_bottom' => $values['margin_bottom'],
            'margin_left' => $values['margin_left'],
            'header_height' => $values['header_height'] ?? null,
            'footer_height' => $values['footer_height'] ?? null,
        ]);
    }

    public function deactivateLetterhead(User $actor, int $letterheadId): void
    {
        $this->guard($actor);
        $row = DB::table('offer_letterheads')->where('tenant_id', $actor->companyId())->where('id', $letterheadId)->first();
        abort_if($row === null, 404);
        DB::table('offer_letterheads')->where('id', $row->id)->update(['status' => 'inactive', 'updated_at' => now()]);
        $this->clearLetterheadConfirmation((int) $actor->companyId(), (string) $row->country);
        $this->legalEvent((int) $actor->companyId(), $actor, 'letterhead_deactivated', [
            'version' => (int) $row->version,
            'status' => (string) $row->status,
        ], [
            'version' => (int) $row->version,
            'status' => 'inactive',
        ]);
    }

    public function previewLetterhead(User $actor, int $letterheadId): StreamedResponse
    {
        $this->guard($actor);
        $row = DB::table('offer_letterheads')->where('tenant_id', $actor->companyId())->where('id', $letterheadId)->first();
        abort_if($row === null || ! is_string($row->source_path) || str_contains($row->source_path, '..'), 404);
        abort_unless(str_starts_with($row->source_path, 'offer-letterheads/'.(int) $actor->companyId().'/'), 404);
        abort_unless(Storage::disk('local')->exists($row->source_path), 404);

        return Storage::disk('local')->response($row->source_path, $row->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveTemplate(User $actor, array $input): int
    {
        $this->guard($actor);
        $country = (string) $input['country'];
        $this->assertCountry($country);
        $companyId = (int) $actor->companyId();
        $body = $this->plainBody((string) $input['body']);
        $name = trim((string) $input['name']);

        return DB::transaction(function () use ($actor, $companyId, $country, $body, $name, $input): int {
            $templateId = isset($input['template_id']) ? (int) $input['template_id'] : 0;
            $template = $templateId > 0
                ? DB::table('offer_letter_templates')->where('tenant_id', $companyId)->where('id', $templateId)->lockForUpdate()->first()
                : null;
            abort_if($templateId > 0 && $template === null, 404);

            if ($template !== null && (string) $template->status === 'archived') {
                throw ValidationException::withMessages(['template' => 'An archived template cannot be selected for a new appointment letter.']);
            }

            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Enter a template name.']);
            }

            if ($template === null) {
                $template = DB::table('offer_letter_templates')
                    ->where('tenant_id', $companyId)
                    ->where('country', $country)
                    ->where('name', $name)
                    ->where('status', '!=', 'archived')
                    ->lockForUpdate()
                    ->first();
                $templateId = $template === null ? 0 : (int) $template->id;
            }

            if ($template === null) {
                $templateId = (int) DB::table('offer_letter_templates')->insertGetId([
                    'tenant_id' => $companyId,
                    'country' => $country,
                    'name' => $name,
                    'status' => 'draft',
                    'current_version' => 1,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $version = 1;
            } else {
                $latest = DB::table('offer_letter_template_versions')->where('template_id', $template->id)->orderByDesc('version')->lockForUpdate()->first();
                $open = $latest !== null && in_array($latest->status, ['draft', 'submitted'], true) && $latest->approved_at === null;
                $editable = $open && ($country === 'india' || (int) $latest->created_by === (int) $actor->id);

                if ($editable) {
                    DB::table('offer_letter_template_versions')->where('id', $latest->id)->update([
                        'body' => $body,
                        'status' => 'draft',
                        'submitted_by' => null,
                        'submitted_at' => null,
                        'updated_at' => now(),
                    ]);
                    DB::table('offer_letter_templates')->where('id', $template->id)->update(['name' => $name, 'status' => 'draft', 'updated_at' => now()]);
                    $templateId = (int) $template->id;

                    if ($this->publishesImmediately($actor, $country, $input)) {
                        $this->publishHeadTemplate($actor, $templateId);
                    }

                    return $templateId;
                }

                $version = ((int) ($latest->version ?? 0)) + 1;
                DB::table('offer_letter_templates')->where('id', $template->id)->update([
                    'name' => $name,
                    'status' => 'draft',
                    'current_version' => $version,
                    'updated_at' => now(),
                ]);
            }

            DB::table('offer_letter_template_versions')->insert([
                'tenant_id' => $companyId,
                'template_id' => $templateId,
                'version' => $version,
                'country' => $country,
                'body' => $body,
                'letterhead_id' => $this->activeLetterheadId($companyId, $country),
                'status' => 'draft',
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($this->publishesImmediately($actor, $country, $input)) {
                $this->publishHeadTemplate($actor, $templateId);
            }

            return $templateId;
        });
    }

    public function submitTemplate(User $actor, int $templateId): void
    {
        $version = $this->latestVersion($actor, $templateId);

        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['template' => 'Only a draft version can be submitted for review.']);
        }

        if ($this->headOfOperations($actor) && (string) $version->country === 'india') {
            $this->publishHeadTemplate($actor, $templateId);

            return;
        }

        DB::table('offer_letter_template_versions')->where('id', $version->id)->update([
            'status' => 'submitted',
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offer_letter_templates')->where('id', $templateId)->update(['status' => 'submitted', 'updated_at' => now()]);
    }

    public function approveTemplate(User $actor, int $templateId): void
    {
        $version = $this->latestVersion($actor, $templateId);

        if (! $this->headOfOperations($actor)) {
            throw ValidationException::withMessages(['template' => 'Only Head of Operations can approve this appointment letter.']);
        }

        if ($version->status !== 'submitted' || $version->submitted_by === null) {
            throw ValidationException::withMessages(['template' => 'Submit the template for review before it can be approved.']);
        }

        if ((int) $version->submitted_by === (int) $actor->id || (int) $version->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['template' => 'The person who wrote or submitted this version cannot approve it.']);
        }

        DB::table('offer_letter_template_versions')->where('id', $version->id)->update([
            'status' => 'approved',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offer_letter_templates')->where('id', $templateId)->update(['status' => 'approved', 'updated_at' => now()]);
    }

    public function activateTemplate(User $actor, int $templateId): void
    {
        abort_unless($actor->applicationRole() === 'company_admin', 403);
        $version = $this->latestVersion($actor, $templateId);

        if ($version->status !== 'approved' || $version->approved_by === null || $version->approved_at === null) {
            throw ValidationException::withMessages(['template' => 'Only an approved version can become active.']);
        }

        DB::transaction(function () use ($actor, $version, $templateId): void {
            DB::table('offer_letter_template_versions')
                ->where('tenant_id', $actor->companyId())
                ->where('country', $version->country)
                ->where('status', 'active')
                ->update(['status' => 'approved', 'updated_at' => now()]);
            DB::table('offer_letter_templates')
                ->where('tenant_id', $actor->companyId())
                ->where('country', $version->country)
                ->where('status', 'active')
                ->update(['status' => 'approved', 'updated_at' => now()]);
            DB::table('offer_letter_template_versions')->where('id', $version->id)->update([
                'status' => 'active',
                'letterhead_id' => $version->letterhead_id ?: $this->activeLetterheadId((int) $actor->companyId(), (string) $version->country),
                'updated_at' => now(),
            ]);
            DB::table('offer_letter_templates')->where('id', $templateId)->update(['status' => 'active', 'updated_at' => now()]);
        });
    }

    public function activeTemplate(int $companyId, string $country): ?object
    {
        if (! Schema::hasTable('offer_letter_template_versions')) {
            return null;
        }

        return DB::table('offer_letter_template_versions')
            ->where('tenant_id', $companyId)
            ->where('country', $country)
            ->where('status', 'active')
            ->whereNotNull('approved_by')
            ->whereNotNull('approved_at')
            ->first();
    }

    /**
     * @param  array<string, string>  $values
     */
    public function render(string $body, array $values): string
    {
        $replace = [];

        foreach (self::PLACEHOLDERS as $key) {
            $value = trim((string) ($values[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $replace['{{'.$key.'}}'] = $value;
        }

        return strtr($this->plainBody($body), $replace);
    }

    /**
     * @return list<string>
     */
    public static function unresolvedTokens(string $body): array
    {
        preg_match_all('/\{\{([a-z0-9_]+)\}\}/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public static function missingValues(string $source, array $values): array
    {
        $missing = [];

        foreach (self::unresolvedTokens($source) as $key) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $keys
     */
    public static function unresolvedMessage(array $keys): string
    {
        $labels = array_map(
            fn (string $key): string => self::PLACEHOLDER_LABELS[$key] ?? str_replace('_', ' ', $key),
            $keys,
        );

        return 'This letter still has unresolved placeholders: '.implode(', ', $labels).'. Fill them before preview, submission, or issuance.';
    }

    public static function fieldMessage(string $key): string
    {
        if ($key === 'working_hours') {
            return 'Enter the working hours for this offer. A saved shift can be selected, and nothing is filled in when the employee has no shift.';
        }

        $label = self::PLACEHOLDER_LABELS[$key] ?? str_replace('_', ' ', $key);

        return 'Enter the '.$label.'. This letter prints it, and the value is empty.';
    }

    public function samplePdf(User $actor, string $country, string $body): string
    {
        $this->guard($actor);
        $this->assertCountry($country);
        $companyId = (int) $actor->companyId();
        $rendered = $this->render($body, $this->sample($companyId, $country));
        $this->assertResolved($rendered);
        $layout = $this->printLayout($companyId, $country, null);
        $signature = $this->signatureLayout($companyId, $country);

        if ($signature['enabled'] && $this->closingDocument($rendered) && $signature['path'] === null) {
            throw ValidationException::withMessages([
                'letter' => 'Automatic HR signature is turned on, but no active signature image is on file. Upload a signature or turn automatic placement off.',
            ]);
        }

        return (new OfferLetterPdf)->render($rendered, $layout['image'], $layout['margins'], [
            ...$layout,
            'signature' => $signature,
        ]);
    }

    public function loadIndiaSource(User $actor): int
    {
        $this->guard($actor);
        $name = self::INDIA_TEMPLATE;
        $existingRows = DB::table('offer_letter_templates')
            ->where('tenant_id', $actor->companyId())
            ->where('country', 'india')
            ->where('status', '!=', 'archived')
            ->orderByDesc('id')
            ->get();
        $standard = $existingRows->first(fn ($row) => $row->name === self::INDIA_TEMPLATE);
        $review = $existingRows->first(fn ($row) => $row->name === 'India appointment letter (review draft)');
        $older = $existingRows->first(fn ($row) => $row->name === 'India appointment letter');
        $existing = $standard?->id ?? $review?->id ?? $older?->id;

        return $this->saveTemplate($actor, [
            'country' => 'india',
            'name' => $name,
            'body' => IndiaAppointment::html(),
            'template_id' => $existing ? (int) $existing : null,
        ]);
    }

    public function duplicateTemplate(User $actor, int $templateId): int
    {
        $this->guard($actor);
        $companyId = (int) $actor->companyId();
        $template = DB::table('offer_letter_templates')->where('tenant_id', $companyId)->where('id', $templateId)->first();
        abort_if($template === null, 404);
        $version = DB::table('offer_letter_template_versions')->where('template_id', $template->id)->orderByDesc('version')->first();
        abort_if($version === null, 404);
        $base = 'Copy of '.(string) $template->name;
        $name = $base;
        $suffix = 2;

        while (DB::table('offer_letter_templates')
            ->where('tenant_id', $companyId)
            ->where('country', $template->country)
            ->where('name', $name)
            ->where('status', '!=', 'archived')
            ->exists()) {
            $name = $base.' '.$suffix;
            $suffix++;
        }

        return $this->saveTemplate($actor, [
            'country' => (string) $template->country,
            'name' => mb_substr($name, 0, 191),
            'body' => (string) $version->body,
            'publish' => false,
        ]);
    }

    public function standardIndiaBody(int $companyId): string
    {
        if (! Schema::hasTable('offer_letter_templates')) {
            return '';
        }

        $template = DB::table('offer_letter_templates')
            ->where('tenant_id', $companyId)
            ->where('country', 'india')
            ->where('status', '!=', 'archived')
            ->orderByDesc('id')
            ->get()
            ->first(fn ($row) => $row->name === self::INDIA_TEMPLATE);
        $body = '';

        if ($template !== null) {
            $body = (string) DB::table('offer_letter_template_versions')
                ->where('template_id', $template->id)
                ->orderByDesc('version')
                ->value('body');
        }

        return OfferLetterMarkup::looksLikeHtml($body) ? $body : '';
    }

    /**
     * Older unapproved versions of the standard India letter stay on file, but only the latest one is the working copy.
     */
    public function keepOneIndiaWorkingCopy(int $companyId): void
    {
        if (! Schema::hasTable('offer_letter_templates') || ! Schema::hasTable('offer_letter_template_versions')) {
            return;
        }

        DB::transaction(function () use ($companyId): void {
            $rows = DB::table('offer_letter_templates')
                ->where('tenant_id', $companyId)
                ->where('country', 'india')
                ->where('status', '!=', 'archived')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();
            $standard = $rows->first(fn ($row) => $row->name === self::INDIA_TEMPLATE);

            if ($standard === null) {
                return;
            }

            DB::table('offer_letter_templates')
                ->where('tenant_id', $companyId)
                ->where('country', 'india')
                ->where('id', '!=', $standard->id)
                ->whereNotIn('status', ['active', 'archived'])
                ->update(['status' => 'archived', 'updated_at' => now()]);

            $versions = DB::table('offer_letter_template_versions')
                ->where('template_id', $standard->id)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->get();
            $latest = $versions->first();

            if ($latest === null) {
                return;
            }

            foreach ($versions as $version) {
                if ((int) $version->id === (int) $latest->id || ! in_array($version->status, ['draft', 'submitted'], true) || $version->approved_at !== null) {
                    continue;
                }

                DB::table('offer_letter_template_versions')->where('id', $version->id)->update([
                    'status' => 'archived',
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function archiveObsoleteIndiaTemplates(int $companyId): void
    {
        if (! Schema::hasTable('offer_letter_templates')) {
            return;
        }

        DB::transaction(function () use ($companyId): void {
            $rows = DB::table('offer_letter_templates')
                ->where('tenant_id', $companyId)
                ->where('country', 'india')
                ->where('status', '!=', 'archived')
                ->orderByDesc('id')
                ->get();
            $standard = $rows->first(fn ($row) => $row->name === self::INDIA_TEMPLATE);
            $standardId = $standard === null ? null : $standard->id;

            if ($standardId === null) {
                $legacy = $rows->first(fn ($row) => $row->status !== 'active' && $row->name === 'India appointment letter (review draft)')
                    ?? $rows->first(fn ($row) => $row->status !== 'active' && $row->name === 'India appointment letter');

                if ($legacy !== null) {
                    DB::table('offer_letter_templates')->where('id', $legacy->id)->update([
                        'name' => self::INDIA_TEMPLATE,
                        'updated_at' => now(),
                    ]);
                    $standardId = $legacy->id;
                }
            }

            if ($standardId === null) {
                return;
            }

            DB::table('offer_letter_templates')
                ->where('tenant_id', $companyId)
                ->where('country', 'india')
                ->where('id', '!=', $standardId)
                ->where('status', '!=', 'active')
                ->update(['status' => 'archived', 'updated_at' => now()]);
        });
    }

    public function neutralizeDraftGreetings(): int
    {
        if (! Schema::hasTable('offer_letters') || ! Schema::hasColumn('offer_letters', 'preview_body')) {
            return 0;
        }

        $changed = 0;
        $rows = DB::table('offer_letters')
            ->whereIn('status', ['draft', 'generated'])
            ->whereNull('issued_body')
            ->where('preview_body', 'like', '%Dear Mr.%')
            ->get(['id', 'preview_body']);

        foreach ($rows as $row) {
            $body = str_replace(['<p>Dear Mr. ', 'Dear Mr. '], ['<p>Dear ', 'Dear '], (string) $row->preview_body);
            DB::table('offer_letters')->where('id', $row->id)->update([
                'preview_body' => $body,
                'updated_at' => now(),
            ]);
            $changed++;
        }

        return $changed;
    }

    public function letterheadConfirmed(int $companyId, string $country): bool
    {
        if (! Schema::hasTable('company_legal_profiles') || ! Schema::hasColumn('company_legal_profiles', 'letterhead_confirmed_id')) {
            return false;
        }

        $profile = $this->profileQuery($companyId, $this->normalizeCountry($country))->first();
        $activeId = $this->activeLetterheadId($companyId, $this->normalizeCountry($country));

        return $profile !== null
            && $activeId !== null
            && (int) ($profile->letterhead_confirmed_id ?? 0) === $activeId
            && $profile->letterhead_confirmed_by !== null
            && $profile->letterhead_confirmed_at !== null;
    }

    public function letterheadConfirmationPending(int $companyId, string $country): bool
    {
        return $this->activeLetterheadId($companyId, $this->normalizeCountry($country)) !== null
            && ! $this->letterheadConfirmed($companyId, $country);
    }

    public function assertLetterheadConfirmed(int $companyId, string $country): void
    {
        if (! $this->letterheadConfirmationPending($companyId, $country)) {
            return;
        }

        throw ValidationException::withMessages([
            'letter' => 'A company admin must confirm that the active letterhead belongs to the legal company before a PDF can be generated.',
        ]);
    }

    public function confirmLetterhead(User $actor, string $country): void
    {
        $this->guard($actor);
        abort_unless($actor->applicationRole() === 'company_admin', 403);
        $country = $this->normalizeCountry($country);
        $companyId = (int) $actor->companyId();
        $profile = $this->profileQuery($companyId, $country)->first();

        if ($profile === null || trim((string) $profile->legal_name) === '') {
            throw ValidationException::withMessages(['letterhead' => 'Save the legal company name before confirming the letterhead.']);
        }

        $activeId = $this->activeLetterheadId($companyId, $country);

        if ($activeId === null) {
            throw ValidationException::withMessages(['letterhead' => 'Upload the company letterhead before confirming it.']);
        }

        $mismatch = $this->letterheadIssueBlock($companyId, $country);

        if ($mismatch !== null) {
            throw ValidationException::withMessages(['letterhead' => $mismatch]);
        }

        DB::table('company_legal_profiles')->where('id', $profile->id)->update([
            'letterhead_confirmed_by' => $actor->id,
            'letterhead_confirmed_at' => now(),
            'letterhead_confirmed_id' => $activeId,
        ]);
        $this->legalEvent($companyId, $actor, 'letterhead_confirmed', null, [
            'country' => $country,
            'legal_name' => (string) $profile->legal_name,
            'letterhead_id' => $activeId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function letterheadIdentity(User $actor, string $country): array
    {
        $this->guard($actor);
        $country = $this->normalizeCountry($country);
        $companyId = (int) $actor->companyId();
        $profile = $this->profileQuery($companyId, $country)->first();
        $active = DB::table('offer_letterheads')
            ->where('tenant_id', $companyId)
            ->where('country', $country)
            ->where('status', 'active')
            ->first();
        $confirmed = $this->letterheadConfirmed($companyId, $country);
        $confirmer = $confirmed && $profile !== null
            ? DB::table('users')->where('id', $profile->letterhead_confirmed_by)->value('name')
            : null;
        $legalName = $profile === null ? '' : (string) $profile->legal_name;
        $printedName = $active === null ? '' : trim((string) ($active->printed_legal_name ?? ''));
        $printedAddress = $active === null ? '' : trim((string) ($active->printed_address ?? ''));
        $mismatch = $this->letterheadIssueBlock($companyId, $country);
        $brand = $active === null || $printedName === '' ? 'unrecorded' : ($mismatch === null ? 'matched' : 'mismatched');

        return [
            'legalName' => $legalName,
            'legalAddress' => $profile === null ? '' : (string) $profile->legal_address,
            'letterheadName' => $active === null ? '' : (string) $active->original_name,
            'letterheadVersion' => $active === null ? null : (int) $active->version,
            'pageSize' => $active === null ? '' : (string) ($active->page_size ?? ''),
            'previewUrl' => $active === null ? null : route('hrms.offer-letters.letterheads.preview', $active->id),
            'printedName' => $printedName,
            'printedAddress' => $printedAddress,
            'brand' => $brand,
            'confirmed' => $confirmed,
            'confirmedBy' => is_string($confirmer) ? $confirmer : '',
            'confirmedAt' => $confirmed && $profile !== null && $profile->letterhead_confirmed_at !== null
                ? (string) $profile->letterhead_confirmed_at
                : '',
            'canConfirm' => $actor->applicationRole() === 'company_admin' && $profile !== null && $active !== null && ! $confirmed && $brand !== 'mismatched',
            'warning' => $this->identityWarning($legalName, $brand, $printedName, $printedAddress, $profile === null ? '' : (string) $profile->legal_address),
        ];
    }

    public function letterheadIssueBlock(int $companyId, string $country): ?string
    {
        if (! Schema::hasTable('offer_letterheads') || ! Schema::hasColumn('offer_letterheads', 'printed_legal_name')) {
            return null;
        }

        $country = $this->normalizeCountry($country);
        $active = DB::table('offer_letterheads')
            ->where('tenant_id', $companyId)
            ->where('country', $country)
            ->where('status', 'active')
            ->first();

        if ($active === null || trim((string) ($active->printed_legal_name ?? '')) === '') {
            return null;
        }

        $profile = $this->profileQuery($companyId, $country)->first();
        $legalName = $profile === null ? '' : trim((string) $profile->legal_name);
        $legalAddress = $profile === null ? '' : trim((string) $profile->legal_address);
        $printedName = trim((string) $active->printed_legal_name);
        $printedAddress = trim((string) ($active->printed_address ?? ''));

        if ($this->brandMatches($legalName, $printedName, $legalAddress, $printedAddress)) {
            return null;
        }

        return $this->letterheadIssueBlockFromValues($legalName, $printedName, $legalAddress, $printedAddress);
    }

    public function assertLetterheadBrand(int $companyId, string $country): void
    {
        $block = $this->letterheadIssueBlock($companyId, $country);

        if ($block !== null) {
            throw ValidationException::withMessages(['letter' => $block]);
        }
    }

    public function selectLetterhead(User $actor, int $letterheadId): void
    {
        $this->guard($actor);
        $row = DB::table('offer_letterheads')->where('tenant_id', $actor->companyId())->where('id', $letterheadId)->first();
        abort_if($row === null, 404);

        DB::transaction(function () use ($actor, $row): void {
            DB::table('offer_letterheads')
                ->where('tenant_id', $row->tenant_id)
                ->where('country', $row->country)
                ->where('status', 'active')
                ->update(['status' => 'inactive', 'updated_at' => now()]);
            DB::table('offer_letterheads')->where('id', $row->id)->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
            $this->clearLetterheadConfirmation((int) $row->tenant_id, (string) $row->country);
            $this->legalEvent((int) $row->tenant_id, $actor, 'letterhead_selected', null, [
                'letterhead_id' => (int) $row->id,
                'version' => (int) $row->version,
                'name' => (string) $row->original_name,
            ]);
        });
    }

    public function saveLetterheadIdentity(User $actor, int $letterheadId, string $printedName, string $printedAddress): void
    {
        $this->guard($actor);
        abort_unless(Schema::hasColumn('offer_letterheads', 'printed_legal_name'), 404);
        $row = DB::table('offer_letterheads')->where('tenant_id', $actor->companyId())->where('id', $letterheadId)->first();
        abort_if($row === null, 404);
        $name = trim($printedName);
        $address = trim($printedAddress);

        if ($name === '') {
            throw ValidationException::withMessages(['printed_legal_name' => 'Enter the company name printed on this letterhead.']);
        }

        DB::table('offer_letterheads')->where('id', $row->id)->update([
            'printed_legal_name' => $name,
            'printed_address' => $address === '' ? null : $address,
            'updated_at' => now(),
        ]);
        $this->clearLetterheadConfirmation((int) $row->tenant_id, (string) $row->country);
        $this->legalEvent((int) $row->tenant_id, $actor, 'letterhead_identity', [
            'printed_legal_name' => (string) ($row->printed_legal_name ?? ''),
        ], [
            'letterhead_id' => (int) $row->id,
            'printed_legal_name' => $name,
        ]);
    }

    private function identityWarning(string $legalName, string $brand, string $printedName, string $printedAddress, string $legalAddress): string
    {
        if ($brand === 'mismatched') {
            return (string) $this->letterheadIssueBlockFromValues($legalName, $printedName, $legalAddress, $printedAddress);
        }

        if ($brand === 'matched') {
            return 'The recorded printed name matches the legal company. A company admin still has to confirm this file. It is not confirmed automatically.';
        }

        $warning = 'Compare the printed letterhead with the legal company name and address. Record the printed name before confirming. If the printed name is different, do not confirm.';

        if (str_contains(strtoupper($legalName), 'DATADEEP')) {
            $warning .= ' A printed brand such as VELTRIX and DATADEEP IT PVT LTD stay separate until the correct letterhead is selected.';
        }

        return $warning;
    }

    private function letterheadIssueBlockFromValues(string $legalName, string $printedName, string $legalAddress, string $printedAddress): string
    {
        $message = 'The active letterhead prints "'.$printedName.'" and does not match the legal company "'.(trim($legalName) === '' ? 'not saved' : $legalName).'". Final PDF issuance is blocked. Upload or select a letterhead for the legal company. This file is not confirmed.';

        if (trim($printedAddress) !== '' && ! $this->addressesMatch($legalAddress, $printedAddress)) {
            $message .= ' The printed address also differs from the registered address.';
        }

        return $message;
    }

    private function brandMatches(string $legalName, string $printedName, string $legalAddress, string $printedAddress): bool
    {
        if (! $this->namesMatch($legalName, $printedName)) {
            return false;
        }

        return trim($printedAddress) === '' || $this->addressesMatch($legalAddress, $printedAddress);
    }

    private function namesMatch(string $legalName, string $printedName): bool
    {
        $legal = $this->identityTokens($legalName);
        $printed = $this->identityTokens($printedName);

        return $legal !== [] && in_array($legal[0], $printed, true);
    }

    private function addressesMatch(string $legalAddress, string $printedAddress): bool
    {
        if (preg_match('/\d{2,}/', $legalAddress, $match) !== 1) {
            return true;
        }

        $digits = preg_replace('/\D/', '', $printedAddress) ?? '';

        return str_contains($digits, $match[0]);
    }

    /**
     * @return list<string>
     */
    private function identityTokens(string $value): array
    {
        $stop = ['pvt', 'ltd', 'limited', 'private', 'inc', 'incorporated', 'llc', 'company', 'the', 'and'];
        $clean = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $value));

        return array_values(array_filter(
            explode(' ', $clean),
            fn (string $part): bool => strlen($part) > 2 && ! in_array($part, $stop, true),
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function stationery(User $actor): array
    {
        $this->guard($actor);
        $companyId = (int) $actor->companyId();
        $countries = [];

        foreach (self::COUNTRIES as $country) {
            $countries[$country] = [
                'letterheads' => $this->letterheads($companyId, $country),
                'legal' => $this->legal($companyId, $country),
            ];
        }

        return $countries;
    }

    /**
     * @return array<string, string>
     */
    public function sample(int $companyId, ?string $country = null): array
    {
        $company = DB::table('tenants')->where('id', $companyId)->first();
        $legal = Schema::hasTable('company_legal_profiles')
            ? $this->profileQuery($companyId, $country === null ? null : $this->normalizeCountry($country))->first()
            : null;

        return [
            'employee_name' => 'Avery Sample',
            'employee_id' => 'EMP-001',
            'employee_email' => 'avery.sample@example.com',
            'company_name' => (string) ($company->name ?? 'Company'),
            'company_legal_name' => trim((string) ($legal->legal_name ?? '')) !== '' ? (string) $legal->legal_name : 'Sample Company',
            'company_address' => trim((string) ($legal->legal_address ?? '')) !== '' ? (string) $legal->legal_address : 'Sample registered office',
            'designation' => 'Sample role',
            'department' => 'Sample department',
            'reporting_manager' => 'Sample manager',
            'employment_type' => 'Full time',
            'salary' => '1000.00',
            'basic_salary' => '500.00',
            'hra' => '200.00',
            'special_allowance' => '300.00',
            'monthly_salary' => '25,000',
            'salary_in_words' => 'Rupees Twenty-Five Thousand Only.',
            'annual_ctc' => '3,00,000',
            'annual_ctc_in_words' => 'Rupees Three Lakh Only.',
            'joining_date' => '02 November 2026',
            'date_of_issue' => '01 November 2026',
            'notice_period' => 'three months',
            'probation_period' => 'three months',
            'work_location' => 'Head office',
            'work_mode' => 'Work From Office',
            'working_hours' => '6:30 PM to 3:30 AM (IST)',
            'signatory_name' => (string) ($legal->signatory_name ?? 'Authorized signatory'),
            'signatory_designation' => (string) ($legal->signatory_designation ?? 'HR'),
            'hr_name' => (string) ($legal->signatory_name ?? 'HR'),
            'hr_email' => (string) ($legal->hr_email ?? 'hr@example.com'),
            'salutation_line' => 'Dear Avery Sample,',
            'candidate_address' => 'Sample candidate address',
        ];
    }

    private function latestVersion(User $actor, int $templateId): object
    {
        $this->guard($actor);
        $template = DB::table('offer_letter_templates')->where('tenant_id', $actor->companyId())->where('id', $templateId)->first();
        abort_if($template === null, 404);
        $version = DB::table('offer_letter_template_versions')->where('template_id', $template->id)->orderByDesc('version')->first();
        abort_if($version === null, 404);

        return $version;
    }

    /**
     * @return array<string, null>
     */
    private function clearedLetterheadConfirmation(): array
    {
        if (! Schema::hasColumn('company_legal_profiles', 'letterhead_confirmed_by')) {
            return [];
        }

        return [
            'letterhead_confirmed_by' => null,
            'letterhead_confirmed_at' => null,
            'letterhead_confirmed_id' => null,
        ];
    }

    private function clearLetterheadConfirmation(int $companyId, string $country): void
    {
        if (! Schema::hasTable('company_legal_profiles') || ! Schema::hasColumn('company_legal_profiles', 'letterhead_confirmed_by')) {
            return;
        }

        $this->profileQuery($companyId, $this->normalizeCountry($country))->update($this->clearedLetterheadConfirmation());
    }

    private function activeLetterheadId(int $companyId, string $country): ?int
    {
        $id = DB::table('offer_letterheads')->where('tenant_id', $companyId)->where('country', $country)->where('status', 'active')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array{path: string, background: ?string, mime: string, name: string}
     */
    private function storePrivateFile(UploadedFile $file, string $directory): array
    {
        $path = $file->getRealPath();

        if ($path === false || $file->getSize() === false || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['letterhead' => 'Upload a PNG, JPG, or PDF letterhead of 5 MB or less.']);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

        if (! is_string($mime) || ! isset($allowed[$mime])) {
            throw ValidationException::withMessages(['letterhead' => 'Upload a PNG, JPG, or PDF letterhead.']);
        }

        if ($mime === 'application/pdf') {
            $header = file_get_contents($path, false, null, 0, 5);

            if ($header !== '%PDF-') {
                throw ValidationException::withMessages(['letterhead' => 'That file is not a PDF letterhead.']);
            }
        } elseif (@getimagesize($path) === false) {
            throw ValidationException::withMessages(['letterhead' => 'That image could not be read.']);
        }

        $name = Str::uuid()->toString().'.'.$allowed[$mime];
        $stored = $directory.'/'.$name;
        Storage::disk('local')->put($stored, (string) file_get_contents($path));
        $background = null;

        if ($mime !== 'application/pdf') {
            $jpeg = $directory.'/'.Str::uuid()->toString().'.jpg';

            if ($this->letterheadJpeg($path, $jpeg)) {
                $background = $jpeg;
            }
        }

        return [
            'path' => $stored,
            'background' => $background,
            'mime' => $mime,
            'name' => substr((string) $file->getClientOriginalName(), 0, 180),
        ];
    }

    private function plainBody(string $body): string
    {
        $trim = trim($body);

        if (str_starts_with($trim, '{')) {
            $decoded = json_decode($trim, true);

            if (is_array($decoded) && ($decoded['kind'] ?? '') === 'appointment' && is_array($decoded['blocks'] ?? null)) {
                $json = json_encode($this->sanitizeDocument($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if (! is_string($json) || $json === '' || strlen($json) > 60000) {
                    throw ValidationException::withMessages(['body' => 'The appointment letter is too long to store.']);
                }

                return $json;
            }
        }

        if (OfferLetterMarkup::looksLikeHtml($trim)) {
            $html = OfferLetterMarkup::sanitize($trim);
            $text = trim(strip_tags($html));

            if ($text === '' || strlen($html) > 60000) {
                throw ValidationException::withMessages(['body' => 'Enter the offer letter wording, up to 60,000 characters. Scripts and unsafe HTML are removed.']);
            }

            return $html;
        }

        $text = trim(strip_tags($body));

        if ($text === '' || strlen($text) > 60000) {
            throw ValidationException::withMessages(['body' => 'Enter the offer letter wording, up to 60,000 characters.']);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function storeSignature(User $actor, UploadedFile $file, ?string $country = null): void
    {
        $this->guard($actor);
        $companyId = (int) $actor->companyId();
        $profile = $this->requireProfile($companyId, $country);
        $stored = $this->storeSignatureFile($file, $companyId);
        $previous = (int) ($profile->signature_version ?? 0);
        $version = $previous + 1;
        DB::table('company_legal_profiles')->where('id', $profile->id)->update([
            'signature_path' => $stored,
            'signature_active' => true,
            'signature_version' => $version,
            'signature_uploaded_by' => $actor->id,
            'signature_uploaded_at' => now(),
            'updated_at' => now(),
        ]);
        $this->legalEvent($companyId, $actor, $previous > 0 ? 'signature_replaced' : 'signature_uploaded', [
            'signature_version' => $previous,
        ], [
            'signature_version' => $version,
            'signature_active' => true,
        ]);
    }

    public function removeSignature(User $actor, ?string $country = null): void
    {
        $this->guard($actor);
        $companyId = (int) $actor->companyId();
        $profile = $this->requireProfile($companyId, $country);
        DB::table('company_legal_profiles')->where('id', $profile->id)->update([
            'signature_path' => null,
            'signature_active' => false,
            'updated_at' => now(),
        ]);
        $this->legalEvent($companyId, $actor, 'signature_removed', [
            'signature_version' => (int) ($profile->signature_version ?? 0),
            'signature_active' => (bool) ($profile->signature_active ?? false),
        ], [
            'signature_version' => (int) ($profile->signature_version ?? 0),
            'signature_active' => false,
        ]);
    }

    public function setSignatureActive(User $actor, string $country, bool $active): void
    {
        $this->guard($actor);
        $this->assertCountry($country);
        $companyId = (int) $actor->companyId();
        $profile = $this->requireProfile($companyId, $country);
        $onFile = is_string($profile->signature_path ?? null) && $profile->signature_path !== '';

        if ($active && ! $onFile) {
            throw ValidationException::withMessages(['signature' => 'Upload a signature image before activating it.']);
        }

        DB::table('company_legal_profiles')->where('id', $profile->id)->update([
            'signature_active' => $active,
            'updated_at' => now(),
        ]);
        $this->legalEvent($companyId, $actor, $active ? 'signature_activated' : 'signature_deactivated', [
            'signature_version' => (int) ($profile->signature_version ?? 0),
            'signature_active' => (bool) ($profile->signature_active ?? false),
        ], [
            'signature_version' => (int) ($profile->signature_version ?? 0),
            'signature_active' => $active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveSignatureSettings(User $actor, array $input): void
    {
        $this->guard($actor);
        $companyId = (int) $actor->companyId();
        $profile = $this->requireProfile($companyId, $input['country'] ?? null);
        DB::table('company_legal_profiles')->where('id', $profile->id)->update([
            'signature_enabled' => (bool) $input['enabled'],
            'signature_width' => (int) $input['width'],
            'signature_height' => (int) $input['height'],
            'signature_align' => (string) $input['align'],
            'signature_gap_before' => (int) $input['gap_before'],
            'signature_gap_after' => (int) $input['gap_after'],
            'updated_at' => now(),
        ]);
        $this->legalEvent($companyId, $actor, 'signature_settings', null, [
            'signature_enabled' => (bool) $input['enabled'],
            'signature_width' => (int) $input['width'],
            'signature_height' => (int) $input['height'],
            'signature_align' => (string) $input['align'],
        ]);
    }

    public function signatureFile(User $actor, ?string $country = null): StreamedResponse
    {
        $this->guard($actor);
        $profile = $this->profileQuery((int) $actor->companyId(), $this->normalizeCountry($country ?? 'india'))->first();
        $path = $profile->signature_path ?? null;
        abort_if(! is_string($path) || $path === '' || str_contains($path, '..'), 404);
        abort_unless(str_starts_with($path, 'offer-signatures/'.(int) $actor->companyId().'/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'hr-signature', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function printLayout(int $companyId, string $country, ?int $letterheadId): array
    {
        $query = DB::table('offer_letterheads')->where('tenant_id', $companyId);

        if ($letterheadId !== null) {
            $query->where('id', $letterheadId);
        } else {
            $query->where('country', $country)->where('status', 'active');
        }

        $row = $query->first();
        $top = (int) ($row->margin_top ?? ($country === 'india' ? 110 : 72));
        $bottom = (int) ($row->margin_bottom ?? ($country === 'india' ? 70 : 56));

        if ($row !== null) {
            $top = max($top, (int) ($row->header_height ?? 0));
            $bottom = max($bottom, (int) ($row->footer_height ?? 0));
        }

        $margins = [
            'top' => $top,
            'right' => (int) ($row->margin_right ?? ($country === 'india' ? 54 : 48)),
            'bottom' => $bottom,
            'left' => (int) ($row->margin_left ?? ($country === 'india' ? 54 : 48)),
        ];
        $image = null;
        $pdf = null;
        $width = isset($row->page_width) ? (float) $row->page_width : null;
        $height = isset($row->page_height) ? (float) $row->page_height : null;

        if ($row !== null && is_string($row->source_path) && $row->mime === 'application/pdf' && Storage::disk('local')->exists($row->source_path)) {
            $absolute = Storage::disk('local')->path($row->source_path);
            $inspected = (new OfferLetterPdf)->inspectPdf($absolute);

            if ($inspected['usable']) {
                $pdf = $absolute;
                $width = $width > 0 ? $width : $inspected['width'];
                $height = $height > 0 ? $height : $inspected['height'];
            }
        }

        if ($pdf === null && $row !== null && is_string($row->background_path) && $row->background_path !== '' && Storage::disk('local')->exists($row->background_path)) {
            $image = Storage::disk('local')->path($row->background_path);
        }

        return [
            'image' => $image,
            'pdf' => $pdf,
            'margins' => $margins,
            'headerHeight' => (int) ($row?->header_height ?? 0),
            'footerHeight' => (int) ($row?->footer_height ?? 0),
            'pageWidth' => (float) $width > 0 ? $width : null,
            'pageHeight' => (float) $height > 0 ? $height : null,
            'letterheadId' => $row->id ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function signatureLayout(int $companyId, ?string $country = null): array
    {
        $empty = [
            'enabled' => false,
            'path' => null,
            'version' => null,
            'maxWidth' => 140,
            'maxHeight' => 48,
            'align' => 'left',
            'before' => 8,
            'after' => 6,
        ];

        if (! Schema::hasTable('company_legal_profiles')) {
            return $empty;
        }

        $row = $this->profileQuery($companyId, $country === null ? null : $this->normalizeCountry($country))->first();

        if ($row === null) {
            return $empty;
        }

        $enabled = (bool) ($row->signature_enabled ?? false) && (bool) ($row->signature_active ?? false);
        $stored = $row->signature_path ?? null;
        $absolute = null;

        if ($enabled && is_string($stored) && $stored !== '' && ! str_contains($stored, '..') && str_starts_with($stored, 'offer-signatures/'.$companyId.'/') && Storage::disk('local')->exists($stored)) {
            $absolute = Storage::disk('local')->path($stored);
        }

        return [
            'enabled' => (bool) ($row->signature_enabled ?? false),
            'active' => (bool) ($row->signature_active ?? false),
            'path' => $absolute,
            'version' => (int) ($row->signature_version ?? 0),
            'maxWidth' => (int) ($row->signature_width ?? 140),
            'maxHeight' => (int) ($row->signature_height ?? 48),
            'align' => in_array($row->signature_align ?? 'left', ['left', 'center', 'right'], true) ? $row->signature_align : 'left',
            'before' => (int) ($row->signature_gap_before ?? 8),
            'after' => (int) ($row->signature_gap_after ?? 6),
        ];
    }

    private function storeSignatureFile(UploadedFile $file, int $companyId): string
    {
        $path = $file->getRealPath();

        if ($path === false || $file->getSize() === false || $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['signature' => 'Upload a PNG or JPG signature of 2 MB or less.']);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

        if (! is_string($mime) || ! isset($allowed[$mime]) || @getimagesize($path) === false) {
            throw ValidationException::withMessages(['signature' => 'Upload a PNG or JPG signature. Other file types are not accepted.']);
        }

        $stored = 'offer-signatures/'.$companyId.'/'.Str::uuid()->toString().'.'.$allowed[$mime];
        Storage::disk('local')->put($stored, (string) file_get_contents($path));

        return $stored;
    }

    private function requireProfile(int $companyId, ?string $country = null): object
    {
        $profile = $this->profileQuery($companyId, $this->normalizeCountry($country ?? 'india'))->first();

        if ($profile === null) {
            throw ValidationException::withMessages(['signature' => 'Save the company legal details before uploading a signatory signature.']);
        }

        return $profile;
    }

    private function signatureReady(object $row): bool
    {
        return (bool) ($row->signature_active ?? false)
            && is_string($row->signature_path ?? null)
            && $row->signature_path !== '';
    }

    private function assertResolved(string $body): void
    {
        $missing = self::unresolvedTokens($body);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'letter' => self::unresolvedMessage($missing),
            ]);
        }
    }

    private function closingDocument(string $body): bool
    {
        return str_contains($body, '"type":"closing"')
            || str_contains($body, '"type": "closing"')
            || str_contains($body, 'data-signature="hr"');
    }

    private function pageName(float $width, float $height): string
    {
        if (abs($width - 612) < 3 && abs($height - 792) < 3) {
            return 'Letter';
        }

        if (abs($width - 595) < 4 && abs($height - 842) < 4) {
            return 'A4';
        }

        return $width > 0 ? 'Custom' : 'A4';
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function sanitizeDocument(array $document): array
    {
        array_walk_recursive($document, function (mixed &$value): void {
            if (is_string($value)) {
                $value = trim(strip_tags($value));
            }
        });

        return $document;
    }

    private function assertCountry(string $country): void
    {
        if (! in_array($country, self::COUNTRIES, true)) {
            throw ValidationException::withMessages(['country' => 'Choose USA or India.']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $updated
     */
    private function legalEvent(int $companyId, User $actor, string $action, ?array $previous, array $updated): void
    {
        DB::table('company_legal_profile_events')->insert([
            'tenant_id' => $companyId,
            'actor_id' => $actor->id,
            'action' => $action,
            'previous_values' => $previous === null ? null : json_encode($previous),
            'updated_values' => json_encode($updated),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legal(int $companyId, ?string $country = null): ?array
    {
        if (! Schema::hasTable('company_legal_profiles')) {
            return null;
        }

        $row = $this->profileQuery($companyId, $country === null ? null : $this->normalizeCountry($country))->first();

        if ($row === null) {
            return null;
        }

        $pixels = $this->signaturePixels($row);

        return [
            'legalName' => (string) $row->legal_name,
            'legalAddress' => (string) $row->legal_address,
            'registrationDetails' => (string) ($row->registration_details ?? ''),
            'signatoryName' => (string) $row->signatory_name,
            'signatoryDesignation' => (string) $row->signatory_designation,
            'hrEmail' => (string) $row->hr_email,
            'status' => (string) $row->status,
            'statusKey' => $this->legalStatusKey($row),
            'statusLabel' => $this->legalStatusLabel($this->legalStatusKey($row)),
            'verifiedAt' => $row->verified_at,
            'hasSignature' => $this->signatureReady($row),
            'signatureOnFile' => is_string($row->signature_path ?? null) && $row->signature_path !== '',
            'signatureEnabled' => (bool) ($row->signature_enabled ?? false),
            'signatureActive' => (bool) ($row->signature_active ?? false),
            'signatureVersion' => (int) ($row->signature_version ?? 0),
            'signatureWidth' => (int) ($row->signature_width ?? 140),
            'signatureHeight' => (int) ($row->signature_height ?? 48),
            'signatureAlign' => (string) ($row->signature_align ?? 'left'),
            'signatureGapBefore' => (int) ($row->signature_gap_before ?? 8),
            'signatureGapAfter' => (int) ($row->signature_gap_after ?? 6),
            'signatureImageWidth' => $pixels['width'],
            'signatureImageHeight' => $pixels['height'],
            'updatedBy' => isset($row->updated_by) ? (int) $row->updated_by : null,
            'savedByHead' => (string) ($row->country ?? 'india') === 'india' && $this->savedByHeadOfOperations((int) ($row->updated_by ?? 0)),
            'updatedAt' => isset($row->updated_at) ? (string) $row->updated_at : null,
        ];
    }

    /**
     * @return array{onFile: bool, enabled: bool, active: bool, imageWidth: int|null, imageHeight: int|null, boxWidth: int, boxHeight: int}
     */
    public function signatureDetails(int $companyId, ?string $country = null): array
    {
        $legal = $this->legal($companyId, $country);

        if ($legal === null) {
            return [
                'onFile' => false,
                'enabled' => false,
                'active' => false,
                'imageWidth' => null,
                'imageHeight' => null,
                'boxWidth' => 140,
                'boxHeight' => 48,
            ];
        }

        return [
            'onFile' => (bool) $legal['signatureOnFile'],
            'enabled' => (bool) $legal['signatureEnabled'],
            'active' => (bool) $legal['signatureActive'],
            'imageWidth' => $legal['signatureImageWidth'],
            'imageHeight' => $legal['signatureImageHeight'],
            'boxWidth' => (int) $legal['signatureWidth'],
            'boxHeight' => (int) $legal['signatureHeight'],
        ];
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function signaturePixels(object $row): array
    {
        $path = $row->signature_path ?? null;

        if (! is_string($path) || $path === '' || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
            return ['width' => null, 'height' => null];
        }

        $info = @getimagesize(Storage::disk('local')->path($path));

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return ['width' => null, 'height' => null];
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legalEvents(int $companyId): array
    {
        if (! Schema::hasTable('company_legal_profile_events')) {
            return [];
        }

        return DB::table('company_legal_profile_events')
            ->where('tenant_id', $companyId)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($event) => [
                'action' => (string) $event->action,
                'at' => (string) $event->created_at,
                'actorId' => (int) $event->actor_id,
            ])->all();
    }

    private function letterheadNote(string $mime, bool $ready, ?string $reason): ?string
    {
        if ($mime !== 'application/pdf' || $ready) {
            return null;
        }

        if ($reason === 'parser') {
            return 'This PDF uses compression the installed PDF library cannot import, so it is not placed behind the letter. Upload a PNG or JPG letterhead, or a one-page PDF saved without compressed object streams.';
        }

        return 'This PDF is not a single-page blank letterhead, so it is not placed behind the letter. Upload a clean one-page PDF, PNG, or JPG.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function letterheads(int $companyId, string $country): array
    {
        if (! Schema::hasTable('offer_letterheads')) {
            return [];
        }

        return DB::table('offer_letterheads')
            ->where('tenant_id', $companyId)
            ->where('country', $country)
            ->orderByDesc('version')
            ->get()
            ->map(function ($row) {
                $pdf = $row->mime === 'application/pdf' && is_string($row->source_path)
                    ? (new OfferLetterPdf)->inspectPdf(Storage::disk('local')->path($row->source_path))
                    : null;
                $imageReady = is_string($row->background_path) && $row->background_path !== '' && Storage::disk('local')->exists($row->background_path);
                $pdfReady = $pdf !== null && $pdf['usable'];
                $pixels = is_string($row->source_path) && Storage::disk('local')->exists($row->source_path)
                    ? @getimagesize(Storage::disk('local')->path($row->source_path))
                    : false;

                return [
                    'id' => (int) $row->id,
                    'imageWidth' => is_array($pixels) ? (int) $pixels[0] : null,
                    'imageHeight' => is_array($pixels) ? (int) $pixels[1] : null,
                    'version' => (int) $row->version,
                    'name' => (string) $row->original_name,
                    'mime' => (string) $row->mime,
                    'status' => (string) $row->status,
                    'backgroundReady' => $imageReady || $pdfReady,
                    'pageSize' => (string) $row->page_size,
                    'pageWidth' => isset($row->page_width) && $row->page_width !== null ? (float) $row->page_width : ($pdf['width'] ?? null),
                    'pageHeight' => isset($row->page_height) && $row->page_height !== null ? (float) $row->page_height : ($pdf['height'] ?? null),
                    'headerHeight' => isset($row->header_height) ? (int) $row->header_height : 0,
                    'footerHeight' => isset($row->footer_height) ? (int) $row->footer_height : 0,
                    'marginTop' => (int) $row->margin_top,
                    'marginRight' => (int) $row->margin_right,
                    'marginBottom' => (int) $row->margin_bottom,
                    'marginLeft' => (int) $row->margin_left,
                    'printedName' => trim((string) ($row->printed_legal_name ?? '')),
                    'printedAddress' => trim((string) ($row->printed_address ?? '')),
                    'note' => $this->letterheadNote($row->mime, $pdfReady, is_array($pdf) ? ($pdf['reason'] ?? null) : null),
                    'uploadedAt' => isset($row->created_at) ? (string) $row->created_at : null,
                ];
            })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(int $companyId, string $country): array
    {
        if (! Schema::hasTable('offer_letter_templates')) {
            return [];
        }

        $rows = DB::table('offer_letter_templates')
            ->where('tenant_id', $companyId)
            ->where('country', $country)
            ->orderByDesc('id')
            ->get();

        $rows = $rows->filter(fn ($template) => (string) $template->status !== 'archived')->values();

        return $rows->map(function ($template) {
            $version = DB::table('offer_letter_template_versions')->where('template_id', $template->id)->orderByDesc('version')->first();

            return [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'status' => (string) ($version->status ?? $template->status),
                'version' => (int) ($version->version ?? $template->current_version),
                'body' => (string) ($version->body ?? ''),
                'approvedAt' => $version->approved_at ?? null,
                'createdBy' => $version?->created_by === null ? null : (int) $version->created_by,
                'submittedBy' => $version?->submitted_by === null ? null : (int) $version->submitted_by,
                'submittedByHead' => $this->savedByHeadOfOperations((int) ($version->submitted_by ?? 0))
                    || ($version?->submitted_by === null && $this->savedByHeadOfOperations((int) ($version->created_by ?? 0)) && (string) ($version->status ?? '') === 'submitted'),
                'updatedAt' => isset($version->updated_at) ? (string) $version->updated_at : null,
            ];
        })->all();
    }

    /**
     * Older India template copies kept for audit. Bodies stay in the version table and are not repeated here.
     *
     * @return list<array<string, mixed>>
     */
    private function templateHistory(int $companyId, string $country): array
    {
        if (! Schema::hasTable('offer_letter_templates') || ! Schema::hasTable('offer_letter_template_versions')) {
            return [];
        }

        $templates = DB::table('offer_letter_templates')
            ->where('tenant_id', $companyId)
            ->where('country', $country)
            ->orderByDesc('id')
            ->get();
        $history = [];

        foreach ($templates as $template) {
            $versions = DB::table('offer_letter_template_versions')
                ->where('template_id', $template->id)
                ->orderByDesc('version')
                ->get(['id', 'version', 'status', 'updated_at']);

            foreach ($versions as $version) {
                $history[] = [
                    'templateId' => (int) $template->id,
                    'name' => (string) $template->name,
                    'templateStatus' => (string) $template->status,
                    'versionId' => (int) $version->id,
                    'version' => (int) $version->version,
                    'status' => (string) $version->status,
                    'updatedAt' => $version->updated_at === null ? null : (string) $version->updated_at,
                ];
            }
        }

        return $history;
    }

    /**
     * @return array<string, mixed>
     */
    private function legalSnapshot(object $profile): array
    {
        return [
            'legal_name' => $profile->legal_name,
            'legal_address' => $profile->legal_address,
            'registration_details' => $profile->registration_details,
            'signatory_name' => $profile->signatory_name,
            'signatory_designation' => $profile->signatory_designation,
            'hr_email' => $profile->hr_email,
            'status' => $profile->status,
        ];
    }

    private function legalStatusKey(object $row): string
    {
        if ((string) $row->status === 'verified' && $row->verified_by !== null && $row->verified_at !== null) {
            return 'verified';
        }

        foreach (['legal_name', 'legal_address', 'signatory_name', 'signatory_designation', 'hr_email'] as $field) {
            if (trim((string) ($row->{$field} ?? '')) === '') {
                return 'draft';
            }
        }

        if ((string) ($row->country ?? 'india') === 'india' && $this->savedByHeadOfOperations((int) ($row->updated_by ?? 0))) {
            return 'verified';
        }

        return 'pending_verification';
    }

    private function headOfOperations(User $actor): bool
    {
        return $actor->applicationRole() === 'company_admin';
    }

    private function savedByHeadOfOperations(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $user = User::query()->find($userId);

        return $user instanceof User && $user->applicationRole() === 'company_admin';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function publishesImmediately(User $actor, string $country, array $input): bool
    {
        return $country === 'india'
            && $this->headOfOperations($actor)
            && ($input['publish'] ?? true) !== false;
    }

    private function publishHeadTemplate(User $actor, int $templateId): void
    {
        $version = DB::table('offer_letter_template_versions')->where('template_id', $templateId)->orderByDesc('version')->first();

        if ($version === null || (string) $version->country !== 'india') {
            return;
        }

        DB::transaction(function () use ($actor, $version, $templateId): void {
            DB::table('offer_letter_template_versions')
                ->where('tenant_id', $actor->companyId())
                ->where('country', 'india')
                ->where('status', 'active')
                ->where('id', '!=', $version->id)
                ->update(['status' => 'approved', 'updated_at' => now()]);
            DB::table('offer_letter_templates')
                ->where('tenant_id', $actor->companyId())
                ->where('country', 'india')
                ->where('status', 'active')
                ->where('id', '!=', $templateId)
                ->update(['status' => 'approved', 'updated_at' => now()]);
            DB::table('offer_letter_template_versions')->where('id', $version->id)->update([
                'status' => 'active',
                'approved_by' => $version->approved_by ?? $actor->id,
                'approved_at' => $version->approved_at ?? now(),
                'submitted_by' => null,
                'submitted_at' => null,
                'letterhead_id' => $version->letterhead_id ?: $this->activeLetterheadId((int) $actor->companyId(), 'india'),
                'updated_at' => now(),
            ]);
            DB::table('offer_letter_templates')->where('id', $templateId)->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
        });
    }

    private function legalStatusLabel(string $key): string
    {
        return match ($key) {
            'verified' => 'Verified',
            'pending_verification' => 'Pending Verification',
            default => 'Draft',
        };
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function letterheadJpeg(string $source, string $destination): bool
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return false;
        }

        $bytes = file_get_contents($source);

        if ($bytes === false) {
            return false;
        }

        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '256M');

        try {
            $image = @imagecreatefromstring($bytes);
            unset($bytes);

            if ($image === false) {
                return false;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, 1650 / max($width, $height, 1));

            if ($scale < 1) {
                $targetWidth = max(1, (int) round($width * $scale));
                $targetHeight = max(1, (int) round($height * $scale));
                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                imagedestroy($image);
                $image = $canvas;
            }

            ob_start();
            imagejpeg($image, null, 85);
            $jpeg = ob_get_clean();
            imagedestroy($image);

            if (! is_string($jpeg) || $jpeg === '') {
                return false;
            }

            Storage::disk('local')->put($destination, $jpeg);

            return true;
        } finally {
            if (is_string($limit) && $limit !== '') {
                ini_set('memory_limit', $limit);
            }
        }
    }

    /**
     * @return array{width: float, height: float}
     */
    private function imagePageSize(string $path): array
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return ['width' => 0.0, 'height' => 0.0];
        }

        $ratio = $info[0] / $info[1];

        if (abs($ratio - (612 / 792)) < 0.02) {
            return ['width' => 612.0, 'height' => 792.0];
        }

        if (abs($ratio - (595.28 / 841.89)) < 0.02) {
            return ['width' => 595.28, 'height' => 841.89];
        }

        return ['width' => round(792 * $ratio, 2), 'height' => 792.0];
    }

    private function profileQuery(int $companyId, ?string $country)
    {
        $query = DB::table('company_legal_profiles')->where('tenant_id', $companyId);

        if ($country !== null && Schema::hasColumn('company_legal_profiles', 'country')) {
            $query->where('country', $country);
        }

        return $query;
    }

    private function normalizeCountry(mixed $country): string
    {
        return in_array($country, self::COUNTRIES, true) ? $country : 'india';
    }

    private function guard(User $actor): void
    {
        abort_unless($actor->companyId() !== null && in_array($actor->applicationRole(), ['company_admin', 'hr'], true), 404);
    }
}
