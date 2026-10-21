<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\Hrms\OfferLetters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfferLetterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
    }

    public function test_each_position_previews_with_a_three_month_notice_and_indian_salary(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');

        foreach (OfferLetters::POSITIONS as $code => $label) {
            $this->actingAs($company->hr)->post('/offer-letters', $this->payload($code, $company->reportEmployeeId))->assertRedirect();
            $letterId = (int) DB::table('offer_letters')->where('position_code', $code)->value('id');
            $this->assertSame(3, (int) DB::table('offer_letters')->where('id', $letterId)->value('notice_months'));
            $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/preview')->assertRedirect();
            $body = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
            $this->assertStringContainsString($label, $body);
            $this->assertStringContainsString('Notice period: 3 months', $body);
            $this->assertStringContainsString('₹45,000.00', $body);
            $this->assertStringContainsString('₹5,40,000.00', $body);
            $this->assertStringContainsString('DRAFT — NOT APPROVED FOR ISSUANCE', $body);
            $this->assertStringContainsString('[PLACEHOLDER: approved appointment-letter wording is not on file]', $body);
            $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        }

        Mail::assertNothingSent();
    }

    public function test_issued_letters_stay_unchanged_and_downloads_stay_inside_the_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Other Company', 'other-co');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();
        $notices = DB::table('notifications')->count();
        $onboardingId = (int) DB::table('onboardings')->where('employee_id', $employee->id)->value('id');
        DB::table('onboarding_tasks')->insert([
            'tenant_id' => $company->id,
            'onboarding_id' => $onboardingId,
            'title' => 'Issue laptop and access',
            'assignee_role' => 'hr',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->get('/offer-letters')->assertForbidden();
        $this->actingAs($company->lead)->get('/offer-letters')->assertForbidden();
        $this->actingAs($company->hr)->post('/offer-letters', [
            ...$this->payload('opt_recruiter', $employee->id),
            'tenant_id' => $other->id,
            'annual_ctc' => '1.00',
        ])->assertSessionHasErrors(['tenant_id', 'annual_ctc']);

        $this->actingAs($company->hr)->post('/offer-letters', $this->payload('opt_recruiter', $employee->id))->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('employee_id', $employee->id)->value('id');
        $this->assertSame(trim($employee->first_name.' '.$employee->last_name), DB::table('offer_letters')->where('id', $letterId)->value('employee_name'));

        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/preview')->assertRedirect();
        $issued = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->actingAs($other->hr)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertNotFound();
        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/issue', [
            'confirm' => 'ISSUE',
            'template_approved' => true,
        ])->assertSessionHasErrors('letter');
        $this->assertSame('generated', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('file_path'));
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('code', 'offer_issued')->where('tenant_id', $company->id)->value('status'));
        $this->assertSame(0, DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'generated_offer')->count());
        $this->assertSame(0, DB::table('employee_record_events')->where('action', 'offer_issued')->count());
        $this->assertSame($notices, DB::table('notifications')->count());

        $path = 'employee-documents/'.$company->id.'/'.$employee->id.'/'.$letterId.'-v1.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nfixture");
        DB::table('offer_letters')->where('id', $letterId)->update([
            'status' => 'issued',
            'issued_body' => $issued,
            'issued_at' => now(),
            'issued_by' => $company->hr->id,
            'file_path' => $path,
        ]);
        DB::table('employee_documents')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $employee->id,
            'category' => 'generated_offer',
            'title' => 'Generated offer letter',
            'file_path' => $path,
            'original_name' => 'offer-letter-v1.pdf',
            'review_status' => 'verified',
            'uploaded_by' => $company->hr->id,
            'reviewed_by' => $company->hr->id,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('issued', $row->status);
        $this->assertSame($issued, $row->issued_body);
        $this->assertStringStartsWith('employee-documents/'.$company->id.'/'.$employee->id.'/', $row->file_path);
        $this->assertStringEndsWith('private', config('filesystems.disks.local.root'));
        Storage::disk('local')->assertExists($row->file_path);
        Storage::disk('public')->assertMissing($row->file_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($row->file_path));
        foreach (['signed_offer', 'pan', 'aadhaar', 'bank_proof', 'resume'] as $code) {
            $this->assertSame('pending', DB::table('onboarding_tasks')->where('code', $code)->where('tenant_id', $company->id)->value('status'));
        }
        $this->assertSame(1, DB::table('onboarding_tasks')->where('code', 'offer_issued')->where('tenant_id', $company->id)->count());
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('code', 'offer_issued')->where('tenant_id', $company->id)->value('status'));
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('title', 'Issue laptop and access')->value('status'));
        $this->assertNull(DB::table('onboarding_tasks')->where('title', 'Issue laptop and access')->value('code'));

        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/preview')->assertSessionHasErrors('letter');
        $this->assertSame($issued, DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->actingAs($company->employee)->get('/offer-letters/'.$letterId.'/pdf')->assertNotFound();
        $this->actingAs($other->hr)->get('/offer-letters/'.$letterId.'/pdf')->assertNotFound();
        $this->actingAs($company->hr)->get('/offer-letters/'.$letterId.'/pdf')->assertOk();
        $documentId = (int) DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'generated_offer')->value('id');
        $this->actingAs($company->employee)->get('/employees/'.$employee->id.'/documents/'.$documentId)->assertOk();

        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/revise', ['confirm' => 'REVISE'])->assertRedirect();
        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/decision', ['status' => 'accepted', 'confirm' => 'ACCEPT'])->assertRedirect();
        $this->assertSame('accepted', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertSame($issued, DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->assertSame(2, DB::table('offer_letters')->count());
        $this->assertSame('draft', DB::table('offer_letters')->where('version', 2)->value('status'));
        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->assertSame(1, DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'generated_offer')->count());
        $this->assertSame(1, DB::table('onboarding_tasks')->where('code', 'offer_issued')->where('tenant_id', $company->id)->count());
        Mail::assertNothingSent();
    }

    public function test_decline_keeps_the_issued_text_and_rejects_another_company_employee(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Other Company', 'other-co');
        $employee = Employee::query()->findOrFail($company->outsideEmployeeId);
        $owner = $this->owner($company);
        $accounts = $this->accountsUser($company);

        $this->actingAs($company->hr)->get('/offer-letters')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('employees', fn ($rows) => collect($rows)->pluck('id')->contains($employee->id) && ! collect($rows)->pluck('id')->contains($other->reportEmployeeId)));

        $this->actingAs($company->hr)->post('/offer-letters', $this->payload('bench_sales_recruiter', $other->reportEmployeeId))
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, DB::table('offer_letters')->count());

        foreach ([$company->employee, $company->lead, $accounts] as $actor) {
            $this->actingAs($actor)->get('/offer-letters')->assertForbidden();
            $this->actingAs($actor)->post('/offer-letters', $this->payload('bench_sales_recruiter', $employee->id))->assertForbidden();
        }

        $this->actingAs($owner)->post('/offer-letters', $this->payload('bench_sales_recruiter', $employee->id))->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('employee_id', $employee->id)->value('id');
        $this->actingAs($owner)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->assertSame(0, DB::table('onboardings')->where('employee_id', $employee->id)->count());
        $this->assertSame(0, DB::table('employee_documents')->where('employee_id', $employee->id)->count());
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));

        $this->actingAs($owner)->post('/offer-letters/'.$letterId.'/preview')->assertRedirect();
        $issued = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->actingAs($company->lead)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertNotFound();
        $this->actingAs($accounts)->post('/offer-letters/'.$letterId.'/decision', ['status' => 'declined', 'confirm' => 'DECLINE'])->assertNotFound();
        $this->actingAs($owner)->post('/offer-letters/'.$letterId.'/issue', [
            'confirm' => 'ISSUE',
            'address_verified' => '1',
        ])->assertSessionHasErrors('letter');
        $this->assertSame('generated', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->assertSame(0, DB::table('employee_documents')->where('employee_id', $employee->id)->count());
        $this->assertSame(0, DB::table('onboardings')->where('employee_id', $employee->id)->count());

        DB::table('offer_letters')->where('id', $letterId)->update([
            'status' => 'issued',
            'issued_body' => $issued,
            'issued_at' => now(),
            'issued_by' => $owner->id,
            'file_path' => 'offer-letters/'.$company->id.'/'.$letterId.'-v1.pdf',
        ]);
        Storage::disk('local')->put('offer-letters/'.$company->id.'/'.$letterId.'-v1.pdf', "%PDF-1.4\nfixture");
        $documents = DB::table('employee_documents')->where('employee_id', $employee->id)->count();
        $tasks = DB::table('onboarding_tasks')->where('tenant_id', $company->id)->count();
        $notices = DB::table('notifications')->count();
        $this->actingAs($owner)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->assertSame($documents, DB::table('employee_documents')->where('employee_id', $employee->id)->count());
        $this->assertSame($tasks, DB::table('onboarding_tasks')->where('tenant_id', $company->id)->count());
        $this->assertSame($notices, DB::table('notifications')->count());
        $this->assertSame($issued, DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));

        $this->actingAs($owner)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();
        $this->assertSame(7, DB::table('onboarding_tasks')->where('tenant_id', $company->id)->whereNotNull('code')->count());
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('code', 'offer_issued')->value('status'));

        $this->actingAs($company->employee)->post('/offer-letters/'.$letterId.'/decision', ['status' => 'declined', 'confirm' => 'DECLINE'])->assertNotFound();
        $this->actingAs($owner)->post('/offer-letters/'.$letterId.'/decision', ['status' => 'declined', 'confirm' => 'DECLINE'])->assertRedirect();
        $this->assertSame('declined', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertSame($issued, DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('code', 'offer_issued')->value('status'));
        foreach (['signed_offer', 'pan', 'aadhaar', 'bank_proof', 'resume'] as $code) {
            $this->assertSame('pending', DB::table('onboarding_tasks')->where('code', $code)->value('status'));
        }
        $this->actingAs($accounts)->get('/offer-letters/'.$letterId.'/pdf')->assertNotFound();
        $this->actingAs($owner)->get('/offer-letters/'.$letterId.'/pdf')->assertOk();
        Mail::assertNothingSent();
    }

    public function test_unapproved_templates_cannot_be_issued(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        DB::table('tenants')->where('id', $company->id)->update([
            'address' => 'A typed address is not verification',
        ]);
        $readiness = app(\App\Services\Hrms\OfferLetterReadiness::class);
        $this->assertFalse($readiness->ready());
        $this->assertSame(['template_approval', 'company_legal_details'], array_column($readiness->issues(), 'key'));
        $this->assertSame(['not_configured'], array_values(array_unique(array_column($readiness->issues(), 'state'))));

        $this->actingAs($company->hr)->get('/offer-letters')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('issuance.ready', false)
            ->where('issuance.canIssue', false)
            ->where('issuance.status', 'Issuance blocked — template approval required')
            ->where('issuance.draftNote', 'A draft preview is not an approved offer letter.'));

        $this->actingAs($company->hr)->post('/offer-letters', [
            ...$this->payload('opt_recruiter', $employee->id),
            'template_approved' => '1',
        ])->assertSessionHasErrors('template_approved');
        $this->assertSame(0, DB::table('offer_letters')->count());

        $this->actingAs($company->hr)->post('/offer-letters', $this->payload('opt_recruiter', $employee->id))->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->value('id');
        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/preview')->assertRedirect();
        $preview = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->assertStringStartsWith('DRAFT — NOT APPROVED FOR ISSUANCE', $preview);
        $this->assertStringNotContainsString('A typed address is not verification', $preview);

        try {
            app(OfferLetters::class)->issue($company->hr, $letterId);
            $this->fail('A direct issuance call should stay blocked.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('Template approval is not on file', $exception->errors()['letter'][0]);
            $this->assertStringNotContainsString('45000', json_encode($exception->errors()));
            $this->assertStringNotContainsString((string) $employee->first_name, json_encode($exception->errors()));
        }

        $row = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('generated', $row->status);
        $this->assertNull($row->issued_body);
        $this->assertNull($row->file_path);
        $this->assertSame(0, DB::table('employee_documents')->count());
        $this->assertSame(0, DB::table('onboardings')->where('employee_id', $employee->id)->count());
        $this->assertSame(0, DB::table('employee_record_events')->where('action', 'offer_issued')->count());

        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->assertSame('generated', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertSame(0, DB::table('employee_documents')->count());
    }

    public function test_a_disk_failure_during_issue_does_not_leave_an_issued_letter(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $this->actingAs($company->hr)->post('/offer-letters', $this->payload('opt_team_lead', $employee->id))->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->value('id');
        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/preview')->assertRedirect();
        $this->assertStringContainsString('DRAFT — NOT APPROVED FOR ISSUANCE', (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body'));

        $this->actingAs($company->hr)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $row = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('generated', $row->status);
        $this->assertNull($row->issued_body);
        $this->assertNull($row->file_path);
        $this->assertSame(0, DB::table('onboardings')->where('employee_id', $employee->id)->count());
        $this->assertSame(0, DB::table('employee_documents')->count());
        $this->assertSame(0, DB::table('onboarding_tasks')->count());
        Storage::disk('local')->assertDirectoryEmpty('employee-documents');
    }

    /**
     * @return array<string, int|string>
     */
    private function payload(string $position, int $employeeId): array
    {
        return [
            'position_code' => $position,
            'employee_id' => $employeeId,
            'monthly_salary' => '45000',
            'joining_date' => '2026-04-01',
            'hr_name' => 'Meera Nair',
            'hr_signature' => 'Meera Nair',
        ];
    }

    private function owner(CompanyFixture $company): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id'),
            'model_type' => \App\Models\User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }

    private function accountsUser(CompanyFixture $company): \App\Models\User
    {
        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = \App\Models\User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => \App\Models\User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
