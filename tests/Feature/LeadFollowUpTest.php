<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeadFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_employee_sees_only_assigned_leads_and_records_follow_ups(): void
    {
        $company = CompanyFixture::make('Employee Leads', 'empleads');
        $other = CompanyFixture::make('Other Employee Leads', 'empleadsother');
        $company->employee->forceFill(['name' => 'Assigned Employee'])->save();
        $employees = $this->employeeSnapshot();
        $mine = $this->lead($company, $company->reportEmployeeId, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '(555) 010-2000',
            'linkedin_url' => 'https://www.linkedin.com/in/ada',
            'university' => 'State University',
            'graduation_term' => 'MAY(2026)',
            'experience' => '4',
        ]);
        $coworker = $this->lead($company, $company->outsideEmployeeId, ['name' => 'Hidden Coworker']);
        $foreign = $this->lead($other, $other->reportEmployeeId, ['name' => 'Foreign Candidate']);
        $this->historyRow($company, $mine);

        $this->actingAs($company->employee)->get('/leads/mine')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/mine')
                ->where('matching', 1)
                ->where('leads.0.name', 'Ada Lovelace')
                ->where('leads.0.email', 'ada@example.com')
                ->where('leads.0.phone', '(555) 010-2000')
                ->where('leads.0.linkedin', 'https://www.linkedin.com/in/ada')
                ->where('leads.0.university', 'State University')
                ->where('leads.0.graduationTerm', 'MAY(2026)')
                ->where('leads.0.experience', '4')
                ->where('leads', fn ($rows): bool => ! collect($rows)->contains('name', 'Hidden Coworker') && ! collect($rows)->contains('name', 'Foreign Candidate')));

        $this->actingAs($company->employee)->get("/leads/mine/{$coworker}")->assertNotFound();
        $this->actingAs($company->employee)->get("/leads/mine/{$foreign}")->assertNotFound();
        $this->actingAs($company->employee)->post("/leads/mine/{$coworker}/follow-ups", $this->payload())->assertNotFound();
        $this->actingAs($company->employee)->post("/my-leads/{$mine}/follow-ups", $this->payload())->assertForbidden();
        $this->actingAs($company->employee)->post("/leads/{$mine}/follow-ups", $this->payload())->assertForbidden();
        $this->actingAs($company->employee)->post('/my-leads/distribute', ['ids' => [$mine], 'employee_id' => $company->outsideEmployeeId])->assertForbidden();
        $this->assertSame(0, DB::table('lead_activities')->count());

        $this->actingAs($company->employee)->post("/leads/mine/{$mine}/follow-ups", $this->payload([
            'pipeline_status' => 'contacted',
            'follow_up_on' => '2026-11-12',
            'remarks' => 'Left a voicemail',
        ]))->assertRedirect("/leads/mine/{$mine}")->assertSessionHas('status', 'Follow-up saved.');
        $this->actingAs($company->employee)->post("/leads/mine/{$mine}/follow-ups", $this->payload([
            'pipeline_status' => 'interested',
            'follow_up_on' => '2026-11-18',
            'remarks' => 'Sent the brochure',
        ]))->assertRedirect("/leads/mine/{$mine}");

        $lead = DB::table('leads')->where('id', $mine)->first();
        $this->assertSame($company->leadEmployeeId, (int) $lead->team_lead_employee_id);
        $this->assertSame($company->reportEmployeeId, (int) $lead->assignee_employee_id);
        $this->assertSame('interested', $lead->pipeline_status);
        $this->assertSame('2026-11-18', substr((string) $lead->follow_up_on, 0, 10));
        $this->assertSame('Sent the brochure', $lead->remarks);
        $this->assertSame($company->employee->id, (int) $lead->updated_by);
        $this->assertSame(1, DB::table('lead_assignments')->count());
        $activities = DB::table('lead_activities')->orderBy('id')->get();
        $this->assertCount(2, $activities);
        $this->assertSame($company->employee->id, (int) $activities[1]->actor_id);
        $this->assertSame($company->id, (int) $activities[1]->tenant_id);
        $this->assertSame('interested', $activities[1]->pipeline_status);
        $this->assertSame('2026-11-18', substr((string) $activities[1]->follow_up_on, 0, 10));
        $this->assertSame('Sent the brochure', $activities[1]->body);
        $this->assertNotNull($activities[1]->created_at);

        $this->actingAs($company->employee)->get("/leads/mine/{$mine}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/show')
                ->where('canFollowUp', true)
                ->where('canManage', false)
                ->where('canAssign', false)
                ->where('followUps.0.body', 'Sent the brochure')
                ->where('followUps.0.statusLabel', 'Interested')
                ->where('followUps.0.followUpOn', '2026-11-18')
                ->where('followUps.0.actor', 'Assigned Employee')
                ->where('followUps.1.body', 'Left a voicemail')
                ->where('assignments', fn ($rows): bool => count($rows) === 1));
        $this->assertSame($employees, $this->employeeSnapshot());
    }

    public function test_follow_up_dates_are_split_into_overdue_today_and_upcoming(): void
    {
        $company = CompanyFixture::make('Follow Dates', 'followdates');
        $overdue = $this->lead($company, $company->reportEmployeeId, ['name' => 'Overdue Person', 'follow_up_on' => '2026-11-10']);
        $today = $this->lead($company, $company->reportEmployeeId, ['name' => 'Today Person', 'follow_up_on' => '2026-11-11']);
        $upcoming = $this->lead($company, $company->reportEmployeeId, ['name' => 'Upcoming Person', 'follow_up_on' => '2026-11-12']);

        $this->actingAs($company->employee)->get('/leads/mine')
            ->assertInertia(fn (Assert $page) => $page->where('counts.overdue', 1)->where('counts.today', 1)->where('counts.upcoming', 1)->where('matching', 3));
        $this->actingAs($company->employee)->get('/leads/mine?queue=overdue')
            ->assertInertia(fn (Assert $page) => $page->where('queue', 'overdue')->where('matching', 1)->where('leads.0.id', $overdue)->where('leads.0.timing', 'overdue'));
        $this->actingAs($company->employee)->get('/leads/mine?queue=today')
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.id', $today)->where('leads.0.timing', 'today'));
        $this->actingAs($company->employee)->get('/leads/mine?queue=upcoming')
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.id', $upcoming)->where('leads.0.timing', 'upcoming'));
    }

    public function test_a_team_lead_can_monitor_follow_ups_without_changing_ownership(): void
    {
        $company = CompanyFixture::make('Team Follow', 'teamfollow');
        $other = CompanyFixture::make('Other Team Follow', 'teamfollowother');
        $company->lead->forceFill(['name' => 'Team Lead Person'])->save();
        $distributed = $this->lead($company, $company->reportEmployeeId, ['name' => 'Distributed Person', 'follow_up_on' => '2026-11-10']);
        $retained = $this->lead($company, $company->leadEmployeeId, ['name' => 'Retained Person', 'follow_up_on' => '2026-11-12']);
        $pending = $this->lead($company, null, ['name' => 'Pending Person', 'team_lead_employee_id' => $company->leadEmployeeId]);
        $foreign = $this->lead($other, $other->reportEmployeeId, ['name' => 'Foreign Team Person', 'follow_up_on' => '2026-11-10']);
        $this->historyRow($company, $distributed);

        $this->actingAs($company->lead)->get('/my-leads?attention=overdue')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('attention.overdue', 1)
                ->where('attention.upcoming', 1)
                ->where('attention.leads.0.name', 'Distributed Person')
                ->where('employees', function ($rows) use ($company): bool {
                    $report = collect($rows)->first(fn ($row): bool => (int) $row['id'] === $company->reportEmployeeId);

                    return $report !== null && (int) $report['followUp']['overdue'] === 1;
                })
                ->where('recentFollowUps', []));

        $this->actingAs($company->lead)->post("/my-leads/{$pending}/follow-ups", $this->payload())
            ->assertRedirect("/my-leads/{$pending}")
            ->assertSessionHas('error', 'Follow-up starts after this lead is retained or distributed.');
        $this->assertNull(DB::table('leads')->where('id', $pending)->value('assignee_employee_id'));
        $this->assertSame(0, DB::table('lead_activities')->count());

        $this->actingAs($company->lead)->post("/my-leads/{$distributed}/follow-ups", $this->payload([
            'remarks' => 'Checked the employee note',
        ]))->assertRedirect("/my-leads/{$distributed}")->assertSessionHas('status', 'Follow-up saved.');
        $this->actingAs($company->lead)->post("/my-leads/{$retained}/follow-ups", $this->payload([
            'remarks' => 'Keeping this one',
        ]))->assertRedirect("/my-leads/{$retained}");

        $distributedLead = DB::table('leads')->where('id', $distributed)->first();
        $this->assertSame($company->leadEmployeeId, (int) $distributedLead->team_lead_employee_id);
        $this->assertSame($company->reportEmployeeId, (int) $distributedLead->assignee_employee_id);
        $retainedLead = DB::table('leads')->where('id', $retained)->first();
        $this->assertSame($company->leadEmployeeId, (int) $retainedLead->assignee_employee_id);
        $this->assertSame(1, DB::table('lead_assignments')->count());
        $this->assertSame($company->lead->id, (int) DB::table('lead_activities')->where('lead_id', $distributed)->value('actor_id'));

        $this->actingAs($company->lead)->get("/my-leads/reports/{$company->reportEmployeeId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/employee-history')
                ->where('employee.name', 'teamfollow-REP Person')
                ->where('leads.0.name', 'Distributed Person')
                ->where('followUps.0.body', 'Checked the employee note')
                ->where('followUps.0.actor', 'Team Lead Person'));
        $this->actingAs($company->lead)->get("/my-leads/reports/{$company->outsideEmployeeId}")->assertNotFound();
        $this->actingAs($company->lead)->post("/my-leads/{$foreign}/follow-ups", $this->payload())->assertNotFound();
        $this->actingAs($company->lead)->post("/leads/mine/{$distributed}/follow-ups", $this->payload())->assertForbidden();
        $this->actingAs($company->employee)->get("/my-leads/reports/{$company->reportEmployeeId}")->assertForbidden();
        $this->assertSame(0, DB::table('lead_activities')->where('lead_id', $foreign)->count());
    }

    public function test_head_of_operations_and_hr_manage_company_follow_ups_inside_the_tenant(): void
    {
        $company = CompanyFixture::make('Company Follow', 'companyfollow');
        $other = CompanyFixture::make('Other Company Follow', 'companyfollowother');
        $owner = $this->roleUser($company, 'Owner');
        $company->hr->forceFill(['name' => 'HR Person'])->save();
        $leadId = $this->lead($company, $company->reportEmployeeId, ['name' => 'Company Candidate', 'follow_up_on' => '2026-11-10']);
        $foreign = $this->lead($other, $other->reportEmployeeId, ['name' => 'Secret Follow', 'follow_up_on' => '2026-11-10']);

        $this->actingAs($company->hr)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canFollowUp', true));
        $this->actingAs($company->hr)->get('/leads/follow-ups?queue=overdue')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/follow-ups')
                ->where('counts.overdue', 1)
                ->where('leads.0.name', 'Company Candidate')
                ->where('leads', fn ($rows): bool => ! collect($rows)->contains('name', 'Secret Follow')));
        $this->actingAs($company->hr)->post("/leads/{$leadId}/follow-ups", $this->payload(['remarks' => 'HR called']))
            ->assertRedirect("/leads/{$leadId}")
            ->assertSessionHas('status', 'Follow-up saved.');
        $this->actingAs($owner)->post("/leads/{$leadId}/follow-ups", $this->payload(['remarks' => 'Owner reviewed']))->assertRedirect("/leads/{$leadId}");
        $this->actingAs($company->hr)->post("/leads/{$foreign}/follow-ups", $this->payload())->assertNotFound();

        $lead = DB::table('leads')->where('id', $leadId)->first();
        $this->assertSame($company->reportEmployeeId, (int) $lead->assignee_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $lead->team_lead_employee_id);
        $this->assertSame('Owner reviewed', $lead->remarks);
        $this->assertSame(0, DB::table('lead_activities')->where('lead_id', $foreign)->count());
        $this->assertSame($company->hr->id, (int) DB::table('lead_activities')->where('body', 'HR called')->value('actor_id'));

        $this->actingAs($company->hr)->get("/leads/{$leadId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canFollowUp', true)->where('followUps.0.body', 'Owner reviewed')->where('followUps.0.actor', $owner->name));

        foreach ([$company->lead, $company->employee, $this->roleUser($company, 'Accounts')] as $user) {
            $this->actingAs($user)->get('/leads/follow-ups')->assertForbidden();
            $this->actingAs($user)->post("/leads/{$leadId}/follow-ups", $this->payload())->assertForbidden();
        }

        $this->actingAs($company->employee)->get('/leads/mine')->assertOk();
        $this->actingAs($company->lead)->get('/leads/mine')->assertForbidden();
        $this->actingAs($this->roleUser($company, 'Accounts'))->get('/leads/mine')->assertForbidden();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'pipeline_status' => 'contacted',
            'follow_up_on' => '2026-11-12',
            'remarks' => 'Called the candidate',
        ], $overrides);
    }

    private function lead(CompanyFixture $company, ?int $assigneeId, array $overrides): int
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'tenant_id' => $company->id,
            'name' => 'Candidate',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $assigneeId,
            'created_by' => $company->hr->id,
        ], $overrides))->save();

        return (int) $lead->id;
    }

    private function historyRow(CompanyFixture $company, int $leadId): void
    {
        DB::table('lead_assignments')->insert([
            'tenant_id' => $company->id,
            'lead_id' => $leadId,
            'from_team_lead_employee_id' => null,
            'to_team_lead_employee_id' => $company->leadEmployeeId,
            'from_assignee_employee_id' => null,
            'to_assignee_employee_id' => $company->reportEmployeeId,
            'assigned_by' => $company->hr->id,
            'note' => 'Distributed to a direct report',
            'created_at' => now(),
        ]);
    }

    private function roleUser(CompanyFixture $company, string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => $company->id,
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employeeSnapshot(): array
    {
        return DB::table('employees')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }
}
