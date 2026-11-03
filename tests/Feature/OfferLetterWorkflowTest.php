<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\IndiaAppointment;
use App\Services\Hrms\OfferLetterCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfferLetterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_offer_is_edited_approved_and_issued_without_changing_the_template_or_an_issued_pdf(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-flow');
        $other = CompanyFixture::make('Other People', 'other-flow');
        $hr = $company->hr;
        $owner = $this->owner($company);
        $outsider = $this->owner($other);

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $this->indiaPayload($company->reportEmployeeId))->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        $letter = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('draft', $letter->status);
        $this->assertNull($letter->preview_body);
        $this->assertSame('acme-flow-REP Person', $letter->employee_name);
        $this->assertSame('acme-flow-department', $letter->department_name);
        $this->assertSame('acme-flow-role', $letter->designation_name);
        $this->assertSame('acme-flow-LEAD Person', $letter->reporting_manager_name);
        $this->assertSame('acme-flow-office', $letter->work_location);
        $this->assertSame('full_time', $letter->employment_type);
        $this->assertSame('office', $letter->work_mode);
        $this->assertSame('acme-flow-report@example.test', $letter->employee_email);
        $this->assertSame(30, (int) $letter->notice_days);
        $this->assertEquals(540000, (float) $letter->annual_ctc);

        $page = $this->actingAs($hr)->get(route('hrms.offer-letters', ['letter' => $letterId]))->assertOk()->inertiaPage();
        $body = (string) $page['props']['editing']['body'];
        $this->assertStringContainsString(OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED, $body);
        $this->assertContains(OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED, $page['props']['editing']['blocks']);
        $this->assertStringNotContainsString('APPOINTMENT LETTER', $body);
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('preview_body'));

        $preview = $this->actingAs($hr)->post(route('hrms.offer-letters.preview-document', $letterId), [
            'body' => '<p>Unsaved preview sentence.</p>',
        ]);
        $preview->assertOk();
        $this->assertStringStartsWith('%PDF', $preview->getContent());
        $this->assertSame('draft', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('preview_body'));
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('file_path'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('first.jpg', 80, 100),
            'margin_top' => 90,
        ])->assertRedirect();
        $firstLetterhead = (int) DB::table('offer_letterheads')->where('tenant_id', $company->id)->where('country', 'india')->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => UploadedFile::fake()->image('first-sign.png', 70, 24),
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.settings'), [
            'country' => 'india',
            'enabled' => 1,
            'width' => 120,
            'height' => 40,
            'align' => 'left',
            'gap_before' => 4,
            'gap_after' => 4,
        ])->assertRedirect();
        $firstVersion = $this->activateTemplate($hr, $owner, 'india', '<p>Master India template wording.</p>', 'India master');

        $custom = '<p>Custom offer sentence for this employee.</p><p>With warm regards,</p><p>For Acme People Private Limited</p><div data-signature="hr"></div><p>Priya Shah</p><h2>ACCEPTANCEBLOCK</h2>';
        $this->actingAs($hr)->post(route('hrms.offer-letters.content', $letterId), ['body' => $custom])->assertRedirect();
        $saved = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->assertStringContainsString('Custom offer sentence for this employee.', $saved);
        $this->assertSame('draft', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertSame($firstVersion, (int) DB::table('offer_letters')->where('id', $letterId)->value('template_version_id'));
        $this->assertStringNotContainsString('Custom offer sentence', (string) DB::table('offer_letter_template_versions')->where('id', $firstVersion)->value('body'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.preview', $letterId))->assertRedirect();
        $this->assertSame('generated', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertStringContainsString('Custom offer sentence for this employee.', (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body'));
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('file_path'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.submit', $letterId))->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])->assertSessionHasErrors('letter');
        $this->actingAs($owner)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])->assertRedirect();
        $approved = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('approved', $approved->status);
        $this->assertNotNull($approved->submitted_at);
        $this->assertNotNull($approved->approved_at);
        $this->assertNotSame((int) $approved->created_by, (int) $approved->approved_by);

        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY', 'country' => 'india'])->assertRedirect();

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('second.jpg', 60, 80),
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.letterhead-confirmation'), [
            'country' => 'india',
            'confirm' => 'CONFIRM LETTERHEAD',
        ])->assertRedirect();
        $secondVersion = $this->activateTemplate($hr, $owner, 'india', '<p>Later master template that must not replace this offer.</p>', 'India later');
        $this->assertNotSame($firstVersion, $secondVersion);

        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertRedirect();
        $issued = DB::table('offer_letters')->where('id', $letterId)->first();
        $stored = Storage::disk('local')->get($issued->file_path);
        $this->assertSame('issued', $issued->status);
        $this->assertSame($firstVersion, (int) $issued->template_version_id);
        $this->assertStringStartsWith('OL-'.$company->id.'-', (string) $issued->reference_code);
        $this->assertStringContainsString('Custom offer sentence for this employee.', (string) $issued->issued_body);
        $this->assertStringContainsString('Custom offer sentence for this employee.', $this->pdfText($stored));
        $this->assertSignatureSitsInTheClosing($stored);
        $frozen = json_decode((string) $issued->issued_values, true);
        $this->assertSame($firstLetterhead, (int) $frozen['letterhead_id']);
        $this->assertSame(1, (int) $frozen['signature_version']);
        $this->assertSame('acme-flow-REP Person', $frozen['employee_name']);
        $this->assertEquals(45000, (float) $frozen['monthly_salary']);

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('third.jpg', 50, 70),
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => UploadedFile::fake()->image('second-sign.png', 90, 40),
        ])->assertRedirect();

        $this->assertSame($stored, Storage::disk('local')->get($issued->file_path));
        $this->assertSame($stored, $this->actingAs($hr)->get(route('hrms.offer-letters.pdf', $letterId))->streamedContent());
        $this->assertSame($stored, $this->actingAs($hr)->post(route('hrms.offer-letters.preview-document', $letterId), [
            'body' => '<p>This later preview must not replace the issued PDF.</p>',
        ])->getContent());
        $this->actingAs($outsider)->get(route('hrms.offer-letters.pdf', $letterId))->assertNotFound();
        $this->actingAs($outsider)->post(route('hrms.offer-letters.content', $letterId), ['body' => '<p>Outside edit.</p>'])->assertNotFound();
        $this->actingAs($outsider)->get(route('hrms.offer-letters', ['letter' => $letterId]))->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_india_offer_requires_working_hours_and_rejects_an_unresolved_placeholder(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-hours');
        $hr = $company->hr;
        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();

        $missing = $this->indiaPayload($company->reportEmployeeId);
        unset($missing['working_hours']);
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $missing)->assertRedirect();
        $openId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        $blocked = $this->actingAs($hr)->get(route('hrms.offer-letters', ['letter' => $openId]))->assertOk()->inertiaPage();
        $this->assertStringContainsString(OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED, (string) $blocked['props']['editing']['body']);
        $this->assertNull(DB::table('offer_letters')->where('id', $openId)->value('preview_body'));

        $owner = $this->owner($company);
        $this->activateTemplate($hr, $owner, 'india', '<p>Hours {{working_hours}} for {{employee_name}}.</p>', 'Hours required');
        $open = $this->actingAs($hr)->get(route('hrms.offer-letters', ['letter' => $openId]))->assertOk()->inertiaPage();
        $this->assertContains('working_hours', $open['props']['editing']['unresolved']);
        $this->assertStringContainsString('{{working_hours}}', (string) $open['props']['editing']['body']);
        $this->actingAs($hr)->post(route('hrms.offer-letters.preview-document', $openId), [
            'body' => (string) $open['props']['editing']['body'],
        ])->assertSessionHasErrors('letter');
        $this->actingAs($hr)->post(route('hrms.offer-letters.working-hours', $openId), [
            'working_hours' => '9:30 AM to 6:30 PM',
        ])->assertRedirect();
        $filled = $this->actingAs($hr)->get(route('hrms.offer-letters', ['letter' => $openId]))->assertOk()->inertiaPage();
        $this->assertNotContains('working_hours', $filled['props']['editing']['unresolved']);
        $this->assertStringContainsString('9:30 AM to 6:30 PM', (string) $filled['props']['editing']['body']);
        $this->assertStringNotContainsString('{{working_hours}}', (string) $filled['props']['editing']['body']);

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $missing)
            ->assertSessionHasErrors('working_hours');

        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Night',
            'type' => 'fixed',
            'start_time' => '18:30:00',
            'end_time' => '03:30:00',
            'grace_minutes' => 10,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['shift_id' => $shiftId]);
        $page = $this->actingAs($hr)->get(route('hrms.offer-letters'))->assertOk()->inertiaPage();
        $employee = collect($page['props']['employees'])->firstWhere('id', $company->reportEmployeeId);
        $this->assertSame('6:30 PM to 3:30 AM', $employee['workingHours']);
        $this->assertSame('6:30 PM to 3:30 AM', collect($page['props']['shifts'])->firstWhere('name', 'Night')['hours']);

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $this->indiaPayload($company->reportEmployeeId))->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->orderByDesc('id')->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.preview-document', $letterId), [
            'body' => '<p>Your normal working hours will be {{working_hours}}.</p>',
        ])->assertSessionHasErrors('letter');
        $this->actingAs($hr)->post(route('hrms.offer-letters.working-hours', $letterId), [
            'working_hours' => '9:30 AM to 6:30 PM',
        ])->assertRedirect();
        $this->assertSame('9:30 AM to 6:30 PM', DB::table('offer_letters')->where('id', $letterId)->value('working_hours'));
    }

    public function test_an_india_appointment_can_be_generated_directly_when_approval_is_off(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-direct');
        $hr = $company->hr;
        $owner = $this->owner($company);
        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();

        $this->actingAs($hr)->post(route('hrms.offer-letters.approval'), ['required' => 0])->assertForbidden();
        $this->actingAs($owner)->post(route('hrms.offer-letters.approval'), ['required' => 0])->assertRedirect();
        $this->assertFalse((bool) DB::table('tenants')->where('id', $company->id)->value('offer_approval_required'));

        $missing = $this->indiaPayload($company->reportEmployeeId);
        unset($missing['working_hours']);
        $missing['flow'] = 'appointment';
        $missing['designation'] = 'Engineering Manager';
        $missing['work_mode'] = 'hybrid';
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $missing)->assertSessionHasErrors('working_hours');

        $payload = $this->indiaPayload($company->reportEmployeeId);
        $payload['flow'] = 'appointment';
        $payload['designation'] = 'Engineering Manager';
        $payload['work_mode'] = 'hybrid';
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $payload)->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->orderByDesc('id')->value('id');
        $this->assertSame('draft', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->actingAs($hr)->post(route('hrms.offer-letters.appointment-preview', $letterId))
            ->assertSessionHasErrors(['letter' => OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED]);
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('preview_body'));
        $this->assertSame('draft', DB::table('offer_letters')->where('id', $letterId)->value('status'));

        $this->activateTemplate($hr, $owner, 'india', IndiaAppointment::html(), 'India Appointment Letter');
        $this->actingAs($hr)->post(route('hrms.offer-letters.appointment-preview', $letterId))->assertRedirect();
        $body = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->assertStringContainsString('Engineering Manager', $body);
        $this->assertStringContainsString('Rupees Forty-Five Thousand Only.', $body);
        $this->assertStringContainsString('Dear ', $body);
        $this->assertStringNotContainsString('Dear Mr.', $body);
        $this->assertStringNotContainsString('{{', $body);
        $this->assertStringNotContainsString('OPT', $body);
        $this->assertStringNotContainsString('U.S.', $body);

        $letterheadId = DB::table('offer_letterheads')->insertGetId([
            'tenant_id' => $company->id,
            'country' => 'india',
            'version' => 1,
            'source_path' => 'offer-letterheads/'.$company->id.'/sample.png',
            'mime' => 'image/png',
            'original_name' => 'sample-letterhead.png',
            'status' => 'active',
            'page_size' => 'Letter',
            'margin_top' => 110,
            'margin_right' => 54,
            'margin_bottom' => 72,
            'margin_left' => 54,
            'uploaded_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->assertSame('generated', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->actingAs($hr)->post(route('hrms.offer-letters.letterhead-confirmation'), [
            'country' => 'india',
            'confirm' => 'CONFIRM LETTERHEAD',
        ])->assertForbidden();
        $this->actingAs($owner)->post(route('hrms.offer-letters.letterhead-confirmation'), [
            'country' => 'india',
            'confirm' => 'CONFIRM LETTERHEAD',
        ])->assertRedirect();
        $this->assertSame($letterheadId, (int) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->where('country', 'india')->value('letterhead_confirmed_id'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertRedirect();
        $issued = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('issued', $issued->status);
        $this->assertSame((int) $hr->id, (int) $issued->issued_by);
        Storage::disk('local')->assertExists($issued->file_path);
        $this->assertSame('draft', DB::table('company_legal_profiles')->where('tenant_id', $company->id)->where('country', 'india')->value('status'));
        $this->assertSame(1, DB::table('offer_letter_template_versions')->where('tenant_id', $company->id)->where('status', 'active')->count());
        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();
        $this->assertNull(DB::table('company_legal_profiles')->where('tenant_id', $company->id)->where('country', 'india')->value('letterhead_confirmed_by'));
        $this->assertSame('issued', DB::table('offer_letters')->where('id', $letterId)->value('status'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), [
            'country' => 'usa',
            'position_code' => 'remote_opt_recruiter',
            'employee_id' => $company->reportEmployeeId,
            'monthly_salary' => '4000',
            'hourly_rate' => '25',
            'joining_date' => '2026-05-04',
            'hr_name' => 'Alex Morgan',
            'hr_signature' => 'Alex Morgan',
        ])->assertRedirect();
        $usaId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->where('country', 'usa')->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $usaId), ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->assertNotSame('issued', DB::table('offer_letters')->where('id', $usaId)->value('status'));
    }

    public function test_the_offer_salutation_is_stored_and_pending_greetings_are_left_unchanged(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-salutation');
        $hr = $company->hr;
        $owner = $this->owner($company);
        $this->activateTemplate($hr, $owner, 'india', IndiaAppointment::html(), 'India Appointment Letter');
        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();

        $chosen = $this->indiaPayload($company->reportEmployeeId);
        $chosen['flow'] = 'appointment';
        $chosen['designation'] = 'Software Engineer';
        $chosen['work_mode'] = 'office';
        $chosen['salutation'] = 'ms';
        $chosen['working_hours'] = '6:30 PM to 3:30 AM';
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $chosen)->assertRedirect();
        $chosenId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->orderByDesc('id')->value('id');
        $name = (string) DB::table('offer_letters')->where('id', $chosenId)->value('employee_name');
        $this->assertSame('ms', DB::table('offer_letters')->where('id', $chosenId)->value('salutation'));
        $this->actingAs($hr)->post(route('hrms.offer-letters.appointment-preview', $chosenId))->assertRedirect();
        $chosenBody = (string) DB::table('offer_letters')->where('id', $chosenId)->value('preview_body');
        $this->assertStringContainsString('Dear Ms. '.$name.',', $chosenBody);
        $this->assertStringContainsString('6:30 PM to 3:30 AM', $chosenBody);
        $this->assertStringNotContainsString('{{', $chosenBody);

        $neutral = $chosen;
        unset($neutral['salutation']);
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $neutral)->assertRedirect();
        $neutralId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->orderByDesc('id')->value('id');
        $this->assertNull(DB::table('offer_letters')->where('id', $neutralId)->value('salutation'));
        $this->actingAs($hr)->post(route('hrms.offer-letters.appointment-preview', $neutralId))->assertRedirect();
        $neutralBody = (string) DB::table('offer_letters')->where('id', $neutralId)->value('preview_body');
        $this->assertStringContainsString('Dear '.$name.',', $neutralBody);
        $this->assertStringNotContainsString('Dear Mr.', $neutralBody);
        $this->assertStringNotContainsString('Dear Ms.', $neutralBody);

        DB::table('offer_letters')->where('id', $chosenId)->update([
            'status' => 'draft',
            'preview_body' => '<p>Dear Mr. '.$name.',</p>',
            'issued_body' => null,
        ]);
        DB::table('offer_letters')->where('id', $neutralId)->update([
            'status' => 'pending_approval',
            'preview_body' => '<p>Dear Mr. '.$name.',</p>',
        ]);
        $this->assertSame(1, app(OfferLetterCatalog::class)->neutralizeDraftGreetings());
        $this->assertStringNotContainsString('Dear Mr.', (string) DB::table('offer_letters')->where('id', $chosenId)->value('preview_body'));
        $this->assertStringContainsString('Dear Mr.', (string) DB::table('offer_letters')->where('id', $neutralId)->value('preview_body'));
    }

    public function test_a_usa_offer_uses_the_usa_template_and_not_the_india_clauses(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-usa-flow');
        $hr = $company->hr;
        $owner = $this->owner($company);

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'usa',
            'legal_name' => 'Acme People LLC',
            'legal_address' => '1200 Market Street, Delaware',
            'signatory_name' => 'Alex Morgan',
            'signatory_designation' => 'HR Manager',
            'hr_email' => 'hr-usa@acme.test',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY', 'country' => 'usa'])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'usa',
            'letterhead' => UploadedFile::fake()->image('usa.jpg', 80, 100),
        ])->assertRedirect();
        $version = $this->activateTemplate($hr, $owner, 'usa', '<p>USA OFFER ONLY for {{employee_name}}.</p><p>Hourly {{salary}}. Annual {{annual_ctc}}.</p>', 'USA master');

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), [
            'country' => 'usa',
            'position_code' => 'remote_opt_recruiter',
            'employee_id' => $company->reportEmployeeId,
            'monthly_salary' => '4000',
            'hourly_rate' => '25',
            'joining_date' => '2026-05-04',
            'working_hours' => '40 hours a week',
            'work_mode' => 'remote',
            'probation_months' => 3,
            'hr_name' => 'Alex Morgan',
            'hr_signature' => 'Alex Morgan',
        ])->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->where('country', 'usa')->value('id');
        $letter = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertEquals(52000, (float) $letter->annual_ctc);
        $this->assertNull($letter->basic_salary);
        $this->assertSame(3, (int) $letter->probation_months);
        $this->assertSame($version, (int) $letter->template_version_id);

        $page = $this->actingAs($hr)->get(route('hrms.offer-letters', ['letter' => $letterId]))->assertOk()->inertiaPage();
        $body = (string) $page['props']['editing']['body'];
        $this->assertStringContainsString('USA OFFER ONLY', $body);
        $this->assertStringContainsString('acme-usa-flow-REP Person', $body);
        $this->assertStringContainsString('52000', $body);
        $this->assertStringNotContainsString('APPOINTMENT LETTER', $body);
        $this->assertStringNotContainsString('Confidentiality of This Offer', $body);
        $this->assertStringNotContainsString('{{employee_name}}', $body);

        $this->actingAs($hr)->post(route('hrms.offer-letters.content', $letterId), [
            'body' => '<p>USA custom sentence.</p><p>USA OFFER ONLY for acme-usa-flow-REP Person.</p>',
        ])->assertRedirect();
        $this->assertStringNotContainsString('USA custom sentence', (string) DB::table('offer_letter_template_versions')->where('id', $version)->value('body'));
        $this->actingAs($hr)->post(route('hrms.offer-letters.preview', $letterId))->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.submit', $letterId))->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])->assertSessionHasErrors('letter');
        $this->actingAs($owner)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertRedirect();

        $issued = DB::table('offer_letters')->where('id', $letterId)->first();
        $stored = Storage::disk('local')->get($issued->file_path);
        $this->assertSame('issued', $issued->status);
        $this->assertStringContainsString('USA custom sentence.', $this->pdfText($stored));
        $this->assertStringNotContainsString('APPOINTMENT LETTER', $this->pdfText($stored));
        $this->assertStringNotContainsString('Confidentiality of This Offer', (string) $issued->issued_body);
        Mail::assertNothingSent();
    }

    private function activateTemplate(User $hr, User $owner, string $country, string $body, string $name): int
    {
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => $country,
            'name' => $name,
            'body' => $body,
        ])->assertRedirect();
        $lookup = $name;
        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $hr->tenant_id)->where('country', $country)->where('name', $lookup)->orderByDesc('id')->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertRedirect();

        return (int) DB::table('offer_letter_template_versions')->where('template_id', $templateId)->orderByDesc('version')->value('id');
    }

    /**
     * @return array<string, int|string>
     */
    private function indiaPayload(int $employeeId): array
    {
        return [
            'country' => 'india',
            'position_code' => 'opt_recruiter',
            'employee_id' => $employeeId,
            'monthly_salary' => '45000',
            'basic_salary' => '20000',
            'hra' => '8000',
            'special_allowance' => '5000',
            'joining_date' => '2026-11-02',
            'date_of_issue' => '2026-11-01',
            'notice_unit' => 'days',
            'notice_days' => 30,
            'working_hours' => '9:30 AM to 6:30 PM',
            'probation_months' => 6,
            'hr_name' => 'Meera Nair',
            'hr_signature' => 'Meera Nair',
        ];
    }

    private function assertSignatureSitsInTheClosing(string $pdf): void
    {
        $regards = strpos($pdf, '(regards');
        $name = strpos($pdf, '(Priya');
        $acceptance = strpos($pdf, '(ACCEPTANCEBLOCK)');
        $image = $regards === false ? false : strpos($pdf, ' Do', $regards);

        $this->assertNotFalse($regards);
        $this->assertNotFalse($name);
        $this->assertNotFalse($acceptance);
        $this->assertNotFalse($image);
        $this->assertTrue($image < $name);
        $this->assertTrue($name < $acceptance);
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
