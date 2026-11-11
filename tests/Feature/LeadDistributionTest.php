<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\Hrms\LeadDistribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeadDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_team_lead_can_retain_a_lead_and_open_its_history(): void
    {
        $company = CompanyFixture::make('Retain Lead', 'retainlead');
        $owner = $this->roleUser($company, 'Owner');
        $leadId = $this->lead($company, $company->leadEmployeeId, ['name' => 'Single Candidate']);
        $activity = new LeadActivity;
        $activity->forceFill([
            'tenant_id' => $company->id,
            'lead_id' => $leadId,
            'actor_id' => $company->lead->id,
            'type' => 'follow_up',
            'pipeline_status' => 'contacted',
            'body' => 'Called the candidate',
        ])->save();
        $name = 'retainlead-LEAD Person';

        $this->actingAs($company->lead)->get('/my-leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/team')
                ->where('summary.pending', 1)
                ->where('summary.retained', 0)
                ->where('summary.distributed', 0)
                ->where('section', 'pending')
                ->where('reports', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => (int) $row['id'] === $company->reportEmployeeId && $row['name'] === 'retainlead-REP Person')));

        $this->actingAs($company->lead)->post('/my-leads/retain/review', ['ids' => [$leadId]])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('leads/team-confirm')->where('action', 'retain')->where('count', 1));
        $this->assertSame(0, DB::table('lead_assignments')->count());

        $this->actingAs($company->lead)->post('/my-leads/retain', ['ids' => [$leadId]])
            ->assertRedirect(route('hrms.my-leads', ['section' => 'retained']))
            ->assertSessionHas('status', 'Retained 1 lead.');

        $lead = DB::table('leads')->where('id', $leadId)->first();
        $this->assertSame($company->leadEmployeeId, (int) $lead->team_lead_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $lead->assignee_employee_id);
        $this->assertSame('new', $lead->pipeline_status);
        $record = DB::table('lead_assignments')->where('lead_id', $leadId)->first();
        $this->assertSame($company->id, (int) $record->tenant_id);
        $this->assertSame($company->leadEmployeeId, (int) $record->from_team_lead_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $record->to_team_lead_employee_id);
        $this->assertNull($record->from_assignee_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $record->to_assignee_employee_id);
        $this->assertSame($company->lead->id, (int) $record->assigned_by);
        $this->assertSame('Retained by the Team Lead', $record->note);

        $this->actingAs($company->lead)->post('/my-leads/retain', ['ids' => [$leadId]])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('leads/team')->where('error', 'These leads are already retained.'));
        $this->assertSame(1, DB::table('lead_assignments')->where('lead_id', $leadId)->count());
        $this->assertSame(1, DB::table('lead_activities')->where('lead_id', $leadId)->count());

        $this->actingAs($company->lead)->get("/my-leads/{$leadId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/show')
                ->where('canManage', true)
                ->where('canAssign', false)
                ->where('placement.section', 'retained')
                ->where('placement.assigneeName', $name)
                ->where('assignments.0.note', 'Retained by the Team Lead')
                ->where('assignments.0.toAssignee', $name)
                ->where('followUps.0.body', 'Called the candidate'));

        $this->actingAs($company->hr)->get('/leads')->assertOk();
        $this->actingAs($company->hr)->get("/leads/{$leadId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', false)
                ->where('placement.section', 'retained')
                ->where('assignments.0.note', 'Retained by the Team Lead')
                ->where('lead.unassigned', false));
        $this->actingAs($company->hr)->get('/leads?q=Single')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('leads.0.heldBy', 'Retained by '.$name));
        $this->actingAs($owner)->get('/leads')->assertOk();
        $this->actingAs($company->lead)->get('/leads')->assertForbidden();
        $this->actingAs($company->hr)->get('/my-leads')->assertForbidden();
        $this->actingAs($owner)->post('/my-leads/retain', ['ids' => [$leadId]])->assertForbidden();
    }

    public function test_bulk_distribution_is_limited_to_active_direct_reports(): void
    {
        $company = CompanyFixture::make('Distribute Bulk', 'distbulk');
        $other = CompanyFixture::make('Other Distribute', 'distother');
        $inactiveId = $this->employee($company, 'Employee', 'distbulk-OFF', 'inactive', $company->leadEmployeeId);
        $otherTeamId = $this->employee($company, 'Manager', 'distbulk-TWO', 'active', null);
        $otherReportId = $this->employee($company, 'Employee', 'distbulk-ELSE', 'active', $otherTeamId);
        $employees = $this->employeeSnapshot();
        $ids = [];

        foreach (range(1, 30) as $number) {
            $ids[] = $this->lead($company, $company->leadEmployeeId, [
                'name' => sprintf('Bulk %02d', $number),
                'pipeline_status' => $number <= 2 ? 'contacted' : 'new',
            ]);
        }

        $foreign = $this->lead($other, $other->leadEmployeeId, ['name' => 'Foreign Bulk']);
        $activities = DB::table('lead_activities')->count();

        $this->actingAs($company->lead)->post('/my-leads/distribute/review', [
            'ids' => $ids,
            'employee_id' => $company->reportEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('leads/team-confirm')
            ->where('action', 'distribute')
            ->where('count', 30)
            ->where('employee.name', 'distbulk-REP Person'));
        $this->assertSame(0, DB::table('lead_assignments')->where('tenant_id', $company->id)->count());

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => array_merge($ids, [$foreign]),
            'employee_id' => $company->reportEmployeeId,
        ])->assertRedirect(route('hrms.my-leads', ['section' => 'distributed']))
            ->assertSessionHas('status', 'Distributed 30 leads to distbulk-REP Person.');

        $this->assertSame(30, DB::table('leads')->where('tenant_id', $company->id)->where('team_lead_employee_id', $company->leadEmployeeId)->where('assignee_employee_id', $company->reportEmployeeId)->count());
        $this->assertSame(30, DB::table('lead_assignments')->where('tenant_id', $company->id)->where('note', 'Distributed to a direct report')->whereNull('from_assignee_employee_id')->where('to_assignee_employee_id', $company->reportEmployeeId)->count());
        $this->assertNull(DB::table('leads')->where('id', $foreign)->value('assignee_employee_id'));
        $this->assertSame($other->leadEmployeeId, (int) DB::table('leads')->where('id', $foreign)->value('team_lead_employee_id'));
        $this->assertSame($activities, DB::table('lead_activities')->count());

        $this->actingAs($company->lead)->get('/my-leads?section=distributed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.distributed', 30)
                ->where('summary.pending', 0)
                ->where('employees', function ($rows) use ($company): bool {
                    $report = collect($rows)->first(fn ($row): bool => (int) $row['id'] === $company->reportEmployeeId);

                    return $report !== null
                        && (int) $report['total'] === 30
                        && collect($report['statuses'])->contains(fn ($status): bool => $status['label'] === 'Contacted' && (int) $status['count'] === 2)
                        && collect($report['statuses'])->contains(fn ($status): bool => $status['label'] === 'New' && (int) $status['count'] === 28);
                })
                ->where('reports', fn ($rows): bool => collect($rows)->pluck('id')->map(fn ($id): int => (int) $id)->all() === [$company->reportEmployeeId]));

        foreach ([$company->leadEmployeeId, $company->outsideEmployeeId, $inactiveId, $otherReportId, $other->reportEmployeeId] as $employeeId) {
            $this->actingAs($company->lead)->post('/my-leads/distribute', [
                'ids' => [$ids[0]],
                'employee_id' => $employeeId,
            ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'Choose an active employee who reports to you.'));
        }

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => $ids,
            'employee_id' => $company->reportEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'Select at least one lead that is not yet distributed.'));
        $this->assertSame(30, DB::table('lead_assignments')->where('tenant_id', $company->id)->count());
        $this->assertSame($employees, $this->employeeSnapshot());
    }

    public function test_a_team_lead_can_take_an_employee_lead_back(): void
    {
        $company = CompanyFixture::make('Recall Lead', 'recalllead');
        $leadId = $this->lead($company, $company->leadEmployeeId, ['name' => 'Shared Candidate']);
        $kept = $this->lead($company, $company->leadEmployeeId, ['name' => 'Still Pending']);

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => [$leadId],
            'employee_id' => $company->reportEmployeeId,
        ])->assertRedirect();

        $this->actingAs($company->lead)->post('/my-leads/recall/review', ['ids' => [$leadId, $kept]])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('leads/team-confirm')->where('action', 'recall')->where('count', 1)->where('skipped', 1));

        $this->actingAs($company->lead)->post('/my-leads/recall', ['ids' => [$leadId]])
            ->assertRedirect(route('hrms.my-leads', ['section' => 'retained']))
            ->assertSessionHas('status', 'Took 1 lead back.');

        $lead = DB::table('leads')->where('id', $leadId)->first();
        $this->assertSame($company->leadEmployeeId, (int) $lead->team_lead_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $lead->assignee_employee_id);
        $this->assertNull(DB::table('leads')->where('id', $kept)->value('assignee_employee_id'));
        $record = DB::table('lead_assignments')->where('lead_id', $leadId)->where('note', 'Taken back by the Team Lead')->first();
        $this->assertSame($company->reportEmployeeId, (int) $record->from_assignee_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $record->to_assignee_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $record->to_team_lead_employee_id);
        $this->assertSame(2, DB::table('lead_assignments')->where('lead_id', $leadId)->count());

        $this->actingAs($company->lead)->get('/my-leads?section=retained')
            ->assertInertia(fn (Assert $page) => $page->where('summary.retained', 1)->where('summary.distributed', 0)->where('leads.0.id', $leadId));
        $this->actingAs($company->employee)->post('/my-leads/recall', ['ids' => [$leadId]])->assertForbidden();
    }

    public function test_distribution_cannot_cross_teams_or_companies(): void
    {
        $company = CompanyFixture::make('Distribute Tenant', 'disttenant');
        $other = CompanyFixture::make('Other Team Tenant', 'disttenantother');
        $secondId = $this->employee($company, 'Manager', 'disttenant-TWO', 'active', null);
        $own = $this->lead($company, $company->leadEmployeeId, ['name' => 'Own Pending']);
        $theirs = $this->lead($company, $secondId, ['name' => 'Other Team']);
        $foreign = $this->lead($other, $other->leadEmployeeId, ['name' => 'Other Company']);
        $secondUser = User::query()->findOrFail((int) DB::table('employees')->where('id', $secondId)->value('user_id'));

        $this->actingAs($company->lead)->get('/my-leads?selection=matching')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedIds', fn ($ids): bool => collect($ids)->map(fn ($id): int => (int) $id)->all() === [$own]));
        $this->actingAs($company->lead)->get("/my-leads/{$theirs}")->assertNotFound();
        $this->actingAs($company->lead)->get("/my-leads/{$foreign}")->assertNotFound();
        $this->actingAs($secondUser)->get('/my-leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.pending', 1)->where('leads.0.name', 'Other Team'));

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => [$theirs, $foreign],
            'employee_id' => $company->reportEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'Select at least one lead that is not yet distributed.'));
        $this->assertNull(DB::table('leads')->where('id', $theirs)->value('assignee_employee_id'));
        $this->assertSame($secondId, (int) DB::table('leads')->where('id', $theirs)->value('team_lead_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $foreign)->value('assignee_employee_id'));
        $this->assertSame(0, DB::table('lead_assignments')->count());

        foreach (range(1, 26) as $number) {
            $this->lead($company, $company->leadEmployeeId, ['name' => sprintf('Page %02d', $number)]);
        }

        $this->actingAs($company->lead)->get('/my-leads?section=pending&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('matching', 27)
                ->where('pages', 2)
                ->where('leads', fn ($rows): bool => count($rows) === 2));
        $this->actingAs($company->lead)->get('/my-leads?section=pending')
            ->assertInertia(fn (Assert $page) => $page
                ->where('page', 1)
                ->where('leads', fn ($rows): bool => count($rows) === LeadDistribution::PAGE_SIZE && collect($rows)->contains('name', 'Page 26') && ! collect($rows)->contains('name', 'Page 01')));
    }

    public function test_employees_and_accounts_cannot_distribute_leads(): void
    {
        $company = CompanyFixture::make('Distribute Denied', 'distdenied');
        $leadId = $this->lead($company, $company->leadEmployeeId, ['name' => 'Protected']);
        $managerId = $this->employee($company, 'Company Manager', 'distdenied-CM', 'active', null);
        $managerLead = $this->lead($company, $managerId, ['name' => 'Manager Lead']);
        $manager = User::query()->findOrFail((int) DB::table('employees')->where('id', $managerId)->value('user_id'));
        $payload = ['ids' => [$leadId], 'employee_id' => $company->reportEmployeeId];

        foreach ([$company->employee, $this->roleUser($company, 'Accounts'), $company->hr] as $user) {
            $this->actingAs($user)->get('/my-leads')->assertForbidden();
            $this->actingAs($user)->get("/my-leads/{$leadId}")->assertForbidden();
            $this->actingAs($user)->post('/my-leads/retain/review', ['ids' => [$leadId]])->assertForbidden();
            $this->actingAs($user)->post('/my-leads/retain', ['ids' => [$leadId]])->assertForbidden();
            $this->actingAs($user)->post('/my-leads/distribute/review', $payload)->assertForbidden();
            $this->actingAs($user)->post('/my-leads/distribute', $payload)->assertForbidden();
            $this->actingAs($user)->post('/my-leads/recall', ['ids' => [$leadId]])->assertForbidden();
        }

        $this->actingAs($manager)->post('/my-leads/retain', ['ids' => [$managerLead]])->assertRedirect();
        $this->assertSame($managerId, (int) DB::table('leads')->where('id', $managerLead)->value('assignee_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $leadId)->value('assignee_employee_id'));
        $this->actingAs($company->hr)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canAssign', true));
    }

    private function lead(CompanyFixture $company, ?int $teamLeadId, array $overrides): int
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'tenant_id' => $company->id,
            'name' => 'Candidate',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $teamLeadId,
            'created_by' => $company->hr->id,
        ], $overrides))->save();

        return (int) $lead->id;
    }

    private function employee(CompanyFixture $company, string $role, string $code, string $status, ?int $managerId): int
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
        $designationId = DB::table('designations')->where('tenant_id', $company->id)->value('id');

        return DB::table('employees')->insertGetId([
            'tenant_id' => $company->id,
            'user_id' => $user->id,
            'employee_code' => $code,
            'first_name' => $code,
            'last_name' => 'Person',
            'email' => $code.'@example.test',
            'department_id' => $company->departmentId,
            'designation_id' => $designationId,
            'location_id' => $company->officeId,
            'manager_id' => $managerId,
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'holiday_calendar' => 'india',
            'date_of_joining' => '2026-01-15',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
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
