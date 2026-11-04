<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\IndiaAppointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class IndiaOfferFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_status_stays_pending_until_a_different_person_verifies_it(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-legal-status');
        $hr = $company->hr;
        $owner = $this->owner($company);

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), $this->legalPayload())->assertRedirect();
        $this->assertSame('draft', DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('status'));
        $this->assertNull(DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('verified_by'));

        $this->actingAs($owner)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('legal.statusKey', 'pending_verification')
                ->where('legal.statusLabel', 'Pending Verification')
                ->where('canVerifyLegal', true));

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY', 'country' => 'india'])
            ->assertSessionHasErrors('legal');
        $this->assertNull(DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('verified_at'));

        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY', 'country' => 'india'])
            ->assertRedirect();
        $this->assertSame('verified', DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('status'));
        $this->assertSame($owner->id, (int) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('verified_by'));
    }

    public function test_pending_approvals_are_shown_to_another_authorized_user_without_changing_approved_records(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-pending-approvals');
        $hr = $company->hr;
        $owner = $this->owner($company);
        $keptAt = '2026-01-15 10:00:00';

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), $this->legalPayload())->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India Appointment Letter',
            'body' => IndiaAppointment::html(),
        ])->assertRedirect();
        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('name', 'India Appointment Letter')->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();

        $keptId = DB::table('offer_letter_templates')->insertGetId([
            'tenant_id' => $company->id,
            'country' => 'india',
            'name' => 'Approved appointment letter',
            'status' => 'approved',
            'current_version' => 1,
            'created_by' => $owner->id,
            'created_at' => $keptAt,
            'updated_at' => $keptAt,
        ]);
        DB::table('offer_letter_template_versions')->insert([
            'tenant_id' => $company->id,
            'template_id' => $keptId,
            'version' => 1,
            'country' => 'india',
            'body' => '<p>Approved wording</p>',
            'status' => 'approved',
            'created_by' => $owner->id,
            'approved_by' => $owner->id,
            'approved_at' => $keptAt,
            'created_at' => $keptAt,
            'updated_at' => $keptAt,
        ]);

        $this->actingAs($owner)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('offer-letters/india-settings')
                ->where('offerApprovals.legal.own', false)
                ->where('offerApprovals.legal.legalName', 'Acme People Private Limited')
                ->where('offerApprovals.templates.0.id', $templateId)
                ->where('offerApprovals.templates.0.name', 'India Appointment Letter')
                ->where('offerApprovals.templates.0.own', false)
                ->where('offerApprovals.templates', fn ($rows): bool => count($rows) === 1));
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('dashboard/company-admin')
            ->where('offerApprovals.legal.legalName', 'Acme People Private Limited')
            ->where('offerApprovals.templates.0.id', $templateId)
            ->where('offerApprovals.templates.0.own', false));
        $this->actingAs($owner)->get('/company/settings')->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/company')
            ->where('offerApprovals.legal.own', false)
            ->where('offerApprovals.templates.0.id', $templateId));

        $this->actingAs($hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('offerApprovals.legal.own', true)
                ->where('offerApprovals.templates.0.own', true));
        $this->actingAs($hr)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('dashboard/hr')
            ->where('offerApprovals.legal.own', true)
            ->where('offerApprovals.templates.0.own', true));
        $this->actingAs($company->employee)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('offerApprovals', null));

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY', 'country' => 'india'])
            ->assertSessionHasErrors('legal');
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('template');
        $this->assertSame('approved', DB::table('offer_letter_templates')->where('id', $keptId)->value('status'));
        $this->assertSame($owner->id, (int) DB::table('offer_letter_template_versions')->where('template_id', $keptId)->value('approved_by'));
        $this->assertSame($keptAt, (string) DB::table('offer_letter_template_versions')->where('template_id', $keptId)->value('approved_at'));

        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY', 'country' => 'india'])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->assertSame('verified', DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('status'));
        $this->assertSame($owner->id, (int) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('verified_by'));
        $this->assertSame('approved', DB::table('offer_letter_templates')->where('id', $templateId)->value('status'));
        $this->assertSame('approved', DB::table('offer_letter_templates')->where('id', $keptId)->value('status'));
        $this->assertSame('<p>Approved wording</p>', DB::table('offer_letter_template_versions')->where('template_id', $keptId)->value('body'));
        $this->assertSame($owner->id, (int) DB::table('offer_letter_template_versions')->where('template_id', $keptId)->value('approved_by'));
        $this->assertSame($keptAt, (string) DB::table('offer_letter_template_versions')->where('template_id', $keptId)->value('approved_at'));

        $this->actingAs($owner)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('offerApprovals.legal', null)
                ->where('offerApprovals.templates', []));
    }

    public function test_a_mismatched_letterhead_blocks_confirmation_and_pdf_issue_and_older_files_stay_selectable(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-letterhead-match');
        $hr = $company->hr;
        $owner = $this->owner($company);
        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), $this->legalPayload())->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.approval'), ['required' => 0])->assertRedirect();

        $matched = $this->letterhead($company->id, $owner->id, 1, 'inactive', 'Acme People Private Limited', 'Registered office, Hyderabad');
        $mismatched = $this->letterhead($company->id, $owner->id, 2, 'active', 'VELTRIX DIGITAL SOLUTIONS INC.', 'Suite-1101, 11th Floor, B-Block, Asian Suncity, Kondapur, HYD');

        $this->actingAs($owner)->post(route('hrms.offer-letters.letterhead-confirmation'), [
            'country' => 'india',
            'confirm' => 'CONFIRM LETTERHEAD',
        ])->assertSessionHasErrors('letterhead');
        $this->assertNull(DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('letterhead_confirmed_by'));

        $letterId = $this->openOffer($company->id, $hr->id, $company->reportEmployeeId, 'generated', '2026-11-02', '2026-11-01');
        DB::table('company_legal_profiles')->where('tenant_id', $company->id)->update([
            'letterhead_confirmed_by' => $owner->id,
            'letterhead_confirmed_at' => now(),
            'letterhead_confirmed_id' => $mismatched,
        ]);
        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])
            ->assertSessionHasErrors('letter');
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('file_path'));
        $this->assertNotSame('issued', DB::table('offer_letters')->where('id', $letterId)->value('status'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.select', $matched))->assertRedirect();
        $this->assertSame('active', DB::table('offer_letterheads')->where('id', $matched)->value('status'));
        $this->assertSame('inactive', DB::table('offer_letterheads')->where('id', $mismatched)->value('status'));
        $this->assertSame('sample-match.png', DB::table('offer_letterheads')->where('id', $matched)->value('original_name'));
        $this->assertNull(DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('letterhead_confirmed_by'));

        $this->actingAs($owner)->post(route('hrms.offer-letters.letterhead-confirmation'), [
            'country' => 'india',
            'confirm' => 'CONFIRM LETTERHEAD',
        ])->assertRedirect();
        $this->assertSame($matched, (int) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('letterhead_confirmed_id'));

        $this->actingAs($this->owner(CompanyFixture::make('Other People', 'other-letterhead')))
            ->post(route('hrms.offer-letters.letterheads.select', $matched))
            ->assertNotFound();
    }

    public function test_the_india_template_is_not_duplicated_or_activated_by_its_creator(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-template-gate');
        $hr = $company->hr;
        $owner = $this->owner($company);
        $body = IndiaAppointment::html();

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'A second India copy',
            'body' => $body,
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'Yet another copy',
            'body' => $body,
        ])->assertRedirect();

        $this->assertSame(2, DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'india')->where('status', '!=', 'archived')->count());
        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('name', 'Yet another copy')->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('template');
        $this->assertSame('submitted', DB::table('offer_letter_templates')->where('id', $templateId)->value('status'));
        $this->assertSame(0, DB::table('offer_letter_template_versions')->where('tenant_id', $company->id)->where('status', 'active')->count());

        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->assertSame('approved', DB::table('offer_letter_templates')->where('id', $templateId)->value('status'));
        $this->assertSame(0, DB::table('offer_letter_template_versions')->where('tenant_id', $company->id)->where('status', 'active')->count());
    }

    public function test_offer_correction_fixes_the_joining_date_without_touching_salary_or_creating_a_pdf(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-offer-correct');
        $hr = $company->hr;
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['date_of_joining' => '2025-06-01']);
        DB::table('salary_structures')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'basic' => '20000.00',
            'hra' => '1200.00',
            'special_allowance' => '7000.00',
            'effective_from' => '2026-10-01',
            'currency' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $letterId = $this->openOffer($company->id, $hr->id, $company->reportEmployeeId, 'pending_approval', '2025-06-01', '2026-11-01', [
            'basic_salary' => '16000.00',
            'hra' => '4000.00',
            'special_allowance' => '10000.00',
            'monthly_salary' => '30000.00',
            'annual_ctc' => '360000.00',
            'preview_body' => '<p>Historical preview</p>',
            'submitted_by' => $hr->id,
            'submitted_at' => now(),
        ]);
        $issuedId = $this->openOffer($company->id, $hr->id, $company->reportEmployeeId, 'issued', '2026-12-01', '2026-11-01', [
            'file_path' => 'offer-letters/'.$company->id.'/kept.pdf',
            'issued_body' => '<p>Issued</p>',
            'issued_at' => now(),
        ]);

        $this->actingAs($company->employee)->post(route('hrms.offer-letters.correct', $letterId), [
            'joining_date' => '2026-12-01',
            'date_of_issue' => '2026-11-01',
            'reason' => 'Joining date was earlier than the issue date.',
        ])->assertNotFound();

        $this->actingAs($hr)->post(route('hrms.offer-letters.correct', $issuedId), [
            'joining_date' => '2026-12-15',
            'date_of_issue' => '2026-11-01',
            'reason' => 'This issued letter must stay unchanged.',
        ])->assertSessionHasErrors('letter');
        $this->assertSame('issued', DB::table('offer_letters')->where('id', $issuedId)->value('status'));
        $this->assertSame('offer-letters/'.$company->id.'/kept.pdf', DB::table('offer_letters')->where('id', $issuedId)->value('file_path'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.correct', $letterId), [
            'joining_date' => '2026-11-01',
            'date_of_issue' => '2026-11-01',
            'reason' => 'Same day is still not later than the issue date.',
        ])->assertSessionHasErrors('joining_date');

        $this->actingAs($hr)->post(route('hrms.offer-letters.correct', $letterId), [
            'joining_date' => '2026-12-01',
            'date_of_issue' => '2026-11-01',
            'reason' => 'Joining date was earlier than the issue date.',
            'apply_salary' => 1,
        ])->assertRedirect();

        $letter = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('draft', $letter->status);
        $this->assertSame('2026-12-01', substr((string) $letter->joining_date, 0, 10));
        $this->assertSame('2026-11-01', substr((string) $letter->date_of_issue, 0, 10));
        $this->assertNull($letter->preview_body);
        $this->assertNull($letter->file_path);
        $this->assertNull($letter->submitted_at);
        $this->assertEquals(20000, (float) $letter->basic_salary);
        $this->assertEquals(28200, (float) $letter->monthly_salary);
        $this->assertSame('2025-06-01', substr((string) DB::table('employees')->where('id', $company->reportEmployeeId)->value('date_of_joining'), 0, 10));
        $this->assertEquals(20000, (float) DB::table('salary_structures')->where('employee_id', $company->reportEmployeeId)->value('basic'));
        $this->assertSame(1, DB::table('offer_letter_corrections')->where('offer_letter_id', $letterId)->count());
        $this->assertSame(2, DB::table('offer_letters')->where('employee_id', $company->reportEmployeeId)->count());
        Storage::disk('local')->assertMissing('offer-letters/'.$company->id.'/'.$letterId.'-v1.pdf');

        $this->actingAs($this->owner(CompanyFixture::make('Other People', 'other-correct')))
            ->post(route('hrms.offer-letters.correct', $letterId), [
                'joining_date' => '2026-12-02',
                'date_of_issue' => '2026-11-01',
                'reason' => 'Another company must not change this offer.',
            ])->assertNotFound();
    }

    public function test_a_sample_pdf_contains_the_india_letter_without_issuing_an_employee_offer(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-sample-pdf');
        $hr = $company->hr;
        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), $this->legalPayload())->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => UploadedFile::fake()->image('sign.png', 80, 30),
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.settings'), [
            'country' => 'india',
            'enabled' => 1,
            'width' => 140,
            'height' => 48,
            'align' => 'left',
            'gap_before' => 8,
            'gap_after' => 6,
        ])->assertRedirect();

        $before = DB::table('offer_letters')->count();
        $response = $this->actingAs($hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'india',
            'body' => IndiaAppointment::html(),
        ]);
        $response->assertOk();
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('APPOINTMENT LETTER', $text);
        $this->assertStringContainsString('Acme People Private Limited', $text);
        $this->assertStringContainsString('Registered office, Hyderabad', $text);
        $this->assertStringContainsString('Avery Sample', $text);
        $this->assertStringContainsString('Sample role', $text);
        $this->assertStringContainsString('Rupees Twenty-Five Thousand Only.', $text);
        $this->assertStringContainsString('02 November 2026', $text);
        $this->assertStringContainsString('Acceptance and Commencement of Employment', $text);
        $this->assertStringContainsString('Acceptance of Appointment', $text);
        $this->assertStringContainsString('EMPLOYEE ACCEPTANCE', $text);
        $regards = strpos($pdf, '(regards');
        $name = strpos($pdf, '(Priya');
        $acceptance = strpos($pdf, '(EMPLOYEE');
        $this->assertNotFalse($regards);
        $this->assertNotFalse($name);
        $this->assertNotFalse($acceptance);
        $this->assertTrue($regards < $name);
        $this->assertTrue($name < $acceptance);
        $this->assertNotFalse(strpos($pdf, ' Do', $regards));
        $this->assertSame($before, DB::table('offer_letters')->count());

        $letterId = $this->openOffer($company->id, $hr->id, $company->reportEmployeeId, 'generated', '2026-12-01', '2026-11-01', [
            'preview_body' => '<p>Joining {{joining_date}}</p>',
        ]);
        $this->actingAs($this->owner($company))->post(route('hrms.offer-letters.approval'), ['required' => 0])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])
            ->assertSessionHasErrors('letter');
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('file_path'));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function openOffer(int $companyId, int $actorId, int $employeeId, string $status, string $joining, ?string $issue, array $extra = []): int
    {
        return (int) DB::table('offer_letters')->insertGetId([
            'tenant_id' => $companyId,
            'employee_id' => $employeeId,
            'position_code' => 'opt_recruiter',
            'employee_name' => 'Sample Person',
            'monthly_salary' => '30000.00',
            'annual_ctc' => '360000.00',
            'joining_date' => $joining,
            'date_of_issue' => $issue,
            'notice_months' => 2,
            'hr_name' => 'Priya Shah',
            'hr_signature' => 'Priya Shah',
            'status' => $status,
            'version' => 1,
            'country' => 'india',
            'preview_body' => '<p>Ready preview</p>',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
            ...$extra,
        ]);
    }

    private function letterhead(int $companyId, int $actorId, int $version, string $status, string $printedName, string $printedAddress): int
    {
        return (int) DB::table('offer_letterheads')->insertGetId([
            'tenant_id' => $companyId,
            'country' => 'india',
            'version' => $version,
            'source_path' => 'offer-letterheads/'.$companyId.'/v'.$version.'.png',
            'mime' => 'image/png',
            'original_name' => $version === 1 ? 'sample-match.png' : 'sample-mismatch.png',
            'status' => $status,
            'page_size' => 'A4',
            'margin_top' => 110,
            'margin_right' => 54,
            'margin_bottom' => 70,
            'margin_left' => 54,
            'printed_legal_name' => $printedName,
            'printed_address' => $printedAddress,
            'uploaded_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function legalPayload(): array
    {
        return [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ];
    }

    private function pdfText(string $pdf): string
    {
        preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/', $pdf, $matches);

        return implode('', array_map(fn (string $part): string => stripcslashes($part), $matches[1]));
    }

    private function owner(CompanyFixture $company): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
