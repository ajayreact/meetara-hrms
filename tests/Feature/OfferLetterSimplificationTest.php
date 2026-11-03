<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\OfferLetterCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfferLetterSimplificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02');
    }

    public function test_one_india_template_is_shown_and_archived_copies_stay_on_file(): void
    {
        $company = CompanyFixture::make('Acme People', 'one-india');
        $other = CompanyFixture::make('Other People', 'one-usa');
        $hr = $company->hr;

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'usa',
            'name' => 'USA offer letter',
            'body' => '<p>USA wording stays.</p>',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India appointment letter (review draft)',
            'body' => '<p>Clause 22. Acceptance of Appointment.</p>',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'Old India copy',
            'body' => '<p>Working wording.</p>',
        ])->assertRedirect();

        $standardId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'india')->orderBy('id')->value('id');
        $this->assertSame(2, DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'india')->count());
        $this->assertSame(1, DB::table('offer_letter_template_versions')->where('template_id', $standardId)->count());
        $historicalBody = '<p>Historical wording that must stay archived.</p>';
        $oldId = DB::table('offer_letter_templates')->insertGetId([
            'tenant_id' => $company->id,
            'country' => 'india',
            'name' => 'Old India copy',
            'status' => 'archived',
            'current_version' => 1,
            'created_by' => $hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offer_letter_template_versions')->insert([
            'tenant_id' => $company->id,
            'template_id' => $oldId,
            'version' => 1,
            'country' => 'india',
            'body' => $historicalBody,
            'status' => 'submitted',
            'created_by' => $hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $this->offer($company->reportEmployeeId, '2026-11-03', '2026-11-02'))->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        DB::table('offer_letters')->where('id', $letterId)->update(['template_version_id' => (int) DB::table('offer_letter_template_versions')->where('template_id', $oldId)->value('id')]);
        $before = (array) DB::table('offer_letters')->where('id', $letterId)->first();

        app(OfferLetterCatalog::class)->archiveObsoleteIndiaTemplates($company->id);

        $this->assertSame('India Appointment Letter', DB::table('offer_letter_templates')->where('id', $standardId)->value('name'));
        $this->assertSame('draft', DB::table('offer_letter_templates')->where('id', $standardId)->value('status'));
        $this->assertSame('archived', DB::table('offer_letter_templates')->where('id', $oldId)->value('status'));
        $this->assertSame($historicalBody, (string) DB::table('offer_letter_template_versions')->where('template_id', $oldId)->value('body'));
        $this->assertSame('draft', DB::table('offer_letter_templates')->where('country', 'usa')->value('status'));
        $this->assertSame($before, (array) DB::table('offer_letters')->where('id', $letterId)->first());
        $this->assertSame(0, DB::table('offer_letter_templates')->where('tenant_id', $other->id)->count());

        $templates = [];
        $this->actingAs($hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$templates) {
                $page->component('offer-letters/india-settings');
                $templates = $page->toArray()['props']['templates'];
            });
        $this->assertCount(1, $templates);
        $this->assertSame('India Appointment Letter', $templates[0]['name']);
        $this->assertSame($standardId, $templates[0]['id']);
        $this->actingAs($hr)->get(route('hrms.offer-letters.templates', ['country' => 'india', 'editor' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('offer-letters/india-editor')->has('templates', 1));

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India Appointment Letter',
            'body' => '<p>Updated working draft.</p>',
            'template_id' => $standardId,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'india')->where('status', '!=', 'archived')->count());
        $this->assertSame(1, DB::table('offer_letter_template_versions')->where('template_id', $standardId)->count());
        $this->assertSame('<p>Updated working draft.</p>', (string) DB::table('offer_letter_template_versions')->where('template_id', $standardId)->value('body'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'Old India copy',
            'body' => '<p>Should not revive.</p>',
            'template_id' => $oldId,
        ])->assertSessionHasErrors('template');
        $this->assertSame($historicalBody, (string) DB::table('offer_letter_template_versions')->where('template_id', $oldId)->value('body'));

        $usa = [];
        $this->actingAs($hr)->get(route('hrms.offer-letters.templates', ['country' => 'usa']))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$usa) {
                $page->component('offer-letters/templates');
                $usa = $page->toArray()['props']['templates'];
            });
        $this->assertSame('USA offer letter', $usa[0]['name']);
        $this->assertSame('<p>USA wording stays.</p>', $usa[0]['body']);
    }

    public function test_a_new_offer_defaults_the_issue_date_and_rejects_a_joining_date_that_is_not_later(): void
    {
        $company = CompanyFixture::make('Acme People', 'offer-dates');
        $hr = $company->hr;
        $employeeBefore = (array) DB::table('employees')->where('id', $company->reportEmployeeId)->first();

        $page = [];
        $this->actingAs($hr)->get(route('hrms.offer-letters.create'))->assertOk()
            ->assertInertia(function (AssertableInertia $pageAssert) use (&$page) {
                $page = $pageAssert->toArray()['props'];
            });
        $this->assertSame('2026-11-02', $page['today']);
        $this->assertSame('2026-11-03', $page['defaultJoining']);
        $employee = collect($page['employees'])->firstWhere('id', $company->reportEmployeeId);
        $this->assertSame('2026-01-15', $employee['joiningDate']);
        $this->assertNotSame($page['defaultJoining'], $employee['joiningDate']);

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $this->offer($company->reportEmployeeId, '2026-11-02', '2026-11-02'))
            ->assertSessionHasErrors('joining_date');
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $this->offer($company->reportEmployeeId, '2026-11-01', '2026-11-02'))
            ->assertSessionHasErrors('joining_date');
        $this->assertSame(0, DB::table('offer_letters')->count());
        $this->assertSame($employeeBefore, (array) DB::table('employees')->where('id', $company->reportEmployeeId)->first());

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $this->offer($company->reportEmployeeId, '2026-11-03', '2026-11-02'))
            ->assertRedirect();
        $letter = DB::table('offer_letters')->first();
        $this->assertSame('2026-11-03', substr((string) $letter->joining_date, 0, 10));
        $this->assertSame('2026-11-02', substr((string) $letter->date_of_issue, 0, 10));
        $this->assertSame('draft', $letter->status);

        $pending = DB::table('offer_letters')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->outsideEmployeeId,
            'employee_name' => 'Existing Person',
            'position_code' => 'opt_recruiter',
            'monthly_salary' => 1000,
            'annual_ctc' => 12000,
            'joining_date' => '2025-06-01',
            'date_of_issue' => '2026-11-01',
            'notice_months' => 1,
            'hr_name' => 'Priya Shah',
            'hr_signature' => 'Priya Shah',
            'status' => 'pending_approval',
            'created_by' => $hr->id,
            'submitted_by' => $company->lead->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stored = (array) DB::table('offer_letters')->where('id', $pending)->first();
        $this->actingAs($hr)->post(route('hrms.offer-letters.approve', $pending), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('joining_date');
        $this->assertSame($stored, (array) DB::table('offer_letters')->where('id', $pending)->first());
    }

    public function test_the_india_appointment_letter_has_one_working_copy_and_blocks_without_an_active_template(): void
    {
        $company = CompanyFixture::make('Acme People', 'india-lifecycle');
        $hr = $company->hr;
        $reviewer = $this->member($company, 'HR');
        $owner = $this->member($company, 'Owner');

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'First working name',
            'body' => '<p>First draft.</p>',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'Second working name',
            'body' => '<p>Second draft.</p>',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'Third working name',
            'body' => '<p>Shared draft.</p>',
        ])->assertRedirect();

        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'india')->where('name', 'Third working name')->value('id');
        $this->assertSame(3, DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'india')->count());
        $this->assertSame('Third working name', DB::table('offer_letter_templates')->where('id', $templateId)->value('name'));
        $this->assertSame(1, DB::table('offer_letter_template_versions')->where('template_id', $templateId)->count());
        $draft = DB::table('offer_letter_template_versions')->where('template_id', $templateId)->first();
        $this->assertSame('<p>Shared draft.</p>', $draft->body);
        $this->assertSame((int) $hr->id, (int) $draft->created_by);
        $this->assertSame('draft', $draft->status);

        $this->actingAs($reviewer)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->assertSame(1, DB::table('offer_letter_template_versions')->where('template_id', $templateId)->count());
        $submitted = DB::table('offer_letter_template_versions')->where('template_id', $templateId)->first();
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame((int) $hr->id, (int) $submitted->created_by);
        $this->assertSame((int) $reviewer->id, (int) $submitted->submitted_by);
        $this->assertSame('<p>Shared draft.</p>', $submitted->body);

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('template');
        $this->actingAs($reviewer)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('template');
        $this->assertNull(DB::table('offer_letter_template_versions')->where('id', $draft->id)->value('approved_by'));

        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->assertSame('approved', DB::table('offer_letter_template_versions')->where('id', $draft->id)->value('status'));
        $this->assertSame((int) $owner->id, (int) DB::table('offer_letter_template_versions')->where('id', $draft->id)->value('approved_by'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertForbidden();
        $this->actingAs($reviewer)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertForbidden();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertRedirect();
        $this->assertSame('active', DB::table('offer_letter_template_versions')->where('id', $draft->id)->value('status'));
        $this->assertSame(1, DB::table('offer_letter_template_versions')->where('tenant_id', $company->id)->where('country', 'india')->where('status', 'active')->count());

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'Next cycle',
            'body' => '<p>Next draft.</p>',
            'template_id' => $templateId,
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertRedirect();

        $previous = DB::table('offer_letter_template_versions')->where('id', $draft->id)->first();
        $current = DB::table('offer_letter_template_versions')->where('template_id', $templateId)->orderByDesc('version')->first();
        $this->assertSame('approved', $previous->status);
        $this->assertSame('<p>Shared draft.</p>', $previous->body);
        $this->assertSame('active', $current->status);
        $this->assertSame('<p>Next draft.</p>', $current->body);
        $this->assertNotSame((int) $previous->id, (int) $current->id);
        $this->assertSame(1, DB::table('offer_letter_template_versions')->where('tenant_id', $company->id)->where('country', 'india')->where('status', 'active')->count());

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), $this->offer($company->reportEmployeeId, '2026-11-03', '2026-11-02'))->assertRedirect();
        $letter = DB::table('offer_letters')->where('tenant_id', $company->id)->orderByDesc('id')->first();
        $this->assertSame((int) $current->id, (int) $letter->template_version_id);

        $bare = CompanyFixture::make('Bare People', 'india-blocked');
        $this->actingAs($bare->hr)->post(route('hrms.offer-letters.store'), $this->offer($bare->reportEmployeeId, '2026-11-03', '2026-11-02'))->assertRedirect();
        $blockedId = (int) DB::table('offer_letters')->where('tenant_id', $bare->id)->value('id');
        $this->actingAs($bare->hr)->post(route('hrms.offer-letters.appointment-preview', $blockedId))
            ->assertSessionHasErrors(['letter' => OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED]);
        $this->assertNull(DB::table('offer_letters')->where('id', $blockedId)->value('preview_body'));
        $this->assertNull(DB::table('offer_letters')->where('id', $blockedId)->value('template_version_id'));
        $this->assertSame('draft', DB::table('offer_letters')->where('id', $blockedId)->value('status'));
        $this->actingAs($bare->hr)->get(route('hrms.offer-letters.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('indiaTemplateActive', false));
        $this->actingAs($hr)->get(route('hrms.offer-letters.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('indiaTemplateActive', true));

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'usa',
            'name' => 'USA first letter',
            'body' => '<p>USA first.</p>',
        ])->assertRedirect();
        $usaId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'usa')->value('id');
        $this->actingAs($reviewer)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'usa',
            'name' => 'USA second letter',
            'body' => '<p>USA second.</p>',
        ])->assertRedirect();
        $this->assertSame(2, DB::table('offer_letter_templates')->where('tenant_id', $company->id)->where('country', 'usa')->count());
        $this->assertSame('<p>USA first.</p>', (string) DB::table('offer_letter_template_versions')->where('template_id', $usaId)->value('body'));

        $repair = CompanyFixture::make('Repair People', 'india-repair');
        $repairTemplate = DB::table('offer_letter_templates')->insertGetId([
            'tenant_id' => $repair->id,
            'country' => 'india',
            'name' => OfferLetterCatalog::INDIA_TEMPLATE,
            'status' => 'submitted',
            'current_version' => 2,
            'created_by' => $repair->hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $older = DB::table('offer_letter_template_versions')->insertGetId([
            'tenant_id' => $repair->id,
            'template_id' => $repairTemplate,
            'version' => 1,
            'country' => 'india',
            'body' => '<p>Older submitted copy.</p>',
            'status' => 'submitted',
            'created_by' => $repair->hr->id,
            'submitted_by' => $repair->hr->id,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $latest = DB::table('offer_letter_template_versions')->insertGetId([
            'tenant_id' => $repair->id,
            'template_id' => $repairTemplate,
            'version' => 2,
            'country' => 'india',
            'body' => '<p>Current submitted copy.</p>',
            'status' => 'submitted',
            'created_by' => $reviewer->id,
            'submitted_by' => $reviewer->id,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $historicalId = DB::table('offer_letter_templates')->insertGetId([
            'tenant_id' => $repair->id,
            'country' => 'india',
            'name' => 'India Offer Letter',
            'status' => 'archived',
            'current_version' => 1,
            'created_by' => $repair->hr->id,
            'created_at' => '2026-11-01 23:16:52',
            'updated_at' => '2026-11-01 23:16:52',
        ]);
        app(OfferLetterCatalog::class)->keepOneIndiaWorkingCopy($repair->id);
        $this->assertSame('2026-11-01 23:16:52', DB::table('offer_letter_templates')->where('id', $historicalId)->value('updated_at'));
        $this->assertSame('archived', DB::table('offer_letter_template_versions')->where('id', $older)->value('status'));
        $this->assertSame('<p>Older submitted copy.</p>', DB::table('offer_letter_template_versions')->where('id', $older)->value('body'));
        $this->assertSame('submitted', DB::table('offer_letter_template_versions')->where('id', $latest)->value('status'));
        $this->assertSame('<p>Current submitted copy.</p>', DB::table('offer_letter_template_versions')->where('id', $latest)->value('body'));
        $this->assertNull(DB::table('offer_letter_template_versions')->where('id', $latest)->value('approved_by'));
    }

    /**
     * @return array<string, int|string>
     */
    private function offer(int $employeeId, string $joining, string $issued): array
    {
        return [
            'country' => 'india',
            'position_code' => 'opt_recruiter',
            'employee_id' => $employeeId,
            'monthly_salary' => '45000',
            'basic_salary' => '20000',
            'hra' => '8000',
            'special_allowance' => '5000',
            'joining_date' => $joining,
            'date_of_issue' => $issued,
            'notice_unit' => 'months',
            'notice_months' => 1,
            'hr_name' => 'Priya Shah',
            'hr_signature' => 'Priya Shah',
        ];
    }

    private function member(CompanyFixture $company, string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
