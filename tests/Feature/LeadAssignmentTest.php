<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_one_unassigned_lead_can_be_assigned_by_hr_or_head_of_operations(): void
    {
        $company = CompanyFixture::make('Assign One', 'assignone');
        $owner = $this->roleUser($company, 'Owner');
        $leadId = $this->lead($company, ['name' => 'Single Candidate', 'state' => 'TX']);
        $ownerLeadId = $this->lead($company, ['name' => 'Owner Candidate']);
        $employees = $this->employeeSnapshot();
        $name = 'assignone-LEAD Person';

        $this->actingAs($company->hr)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canAssign', true)
            ->where('filters.pool', 'all'));
        $this->actingAs($company->hr)->get("/leads/{$leadId}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('canAssign', true)->where('lead.unassigned', true));

        $this->actingAs($company->hr)->post('/leads/assignments/prepare', ['ids' => [$leadId]])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/assign')
                ->where('assignableCount', 1)
                ->where('heldCount', 0)
                ->where('teamLeads', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => (int) $row['id'] === $company->leadEmployeeId
                    && $row['name'] === $name
                    && $row['accessLevel'] === 'Team Lead'
                    && (int) $row['leadCount'] === 0)));

        $this->actingAs($company->hr)->post('/leads/assignments/review', [
            'ids' => [$leadId],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('leads/assign-confirm')
            ->where('count', 1)
            ->where('teamLead.name', $name)
            ->where('teamLead.leadCount', 0)
            ->where('reassignAvailable', false));
        $this->assertSame(0, DB::table('lead_assignments')->count());

        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => [$leadId],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertRedirect(route('hrms.leads', ['pool' => 'unassigned']))
            ->assertSessionHas('status', 'Assigned 1 lead to '.$name.'.');

        $lead = DB::table('leads')->where('id', $leadId)->first();
        $this->assertSame($company->leadEmployeeId, (int) $lead->team_lead_employee_id);
        $this->assertNull($lead->assignee_employee_id);
        $this->assertSame($company->hr->id, (int) $lead->updated_by);
        $history = DB::table('lead_assignments')->where('lead_id', $leadId)->get();
        $this->assertCount(1, $history);
        $this->assertSame($company->id, (int) $history[0]->tenant_id);
        $this->assertNull($history[0]->from_team_lead_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $history[0]->to_team_lead_employee_id);
        $this->assertNull($history[0]->from_assignee_employee_id);
        $this->assertNull($history[0]->to_assignee_employee_id);
        $this->assertSame($company->hr->id, (int) $history[0]->assigned_by);

        $this->actingAs($company->hr)->get('/leads?pool=unassigned')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.id', $ownerLeadId));

        $this->actingAs($owner)->post('/leads/assignments', [
            'ids' => [$ownerLeadId],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertRedirect(route('hrms.leads', ['pool' => 'unassigned']));
        $this->assertSame($company->leadEmployeeId, (int) DB::table('leads')->where('id', $ownerLeadId)->value('team_lead_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $ownerLeadId)->value('assignee_employee_id'));
        $this->assertSame($employees, $this->employeeSnapshot());
    }

    public function test_one_hundred_leads_can_be_assigned_in_one_action(): void
    {
        $company = CompanyFixture::make('Assign Bulk', 'assignbulk');
        $employees = $this->employeeSnapshot();
        $ids = [];

        foreach (range(1, 100) as $number) {
            $ids[] = $this->lead($company, [
                'name' => sprintf('Bulk %03d', $number),
                'state' => 'TX',
                'university' => 'State University',
            ]);
        }

        $this->actingAs($company->hr)->post('/leads/assignments/review', [
            'ids' => $ids,
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('leads/assign-confirm')
            ->where('count', 100)
            ->where('teamLead.id', $company->leadEmployeeId));
        $this->assertSame(0, DB::table('lead_assignments')->where('tenant_id', $company->id)->count());

        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => $ids,
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertRedirect(route('hrms.leads', ['pool' => 'unassigned']))
            ->assertSessionHas('status', 'Assigned 100 leads to assignbulk-LEAD Person.');

        $this->assertSame(100, DB::table('leads')->where('tenant_id', $company->id)->where('team_lead_employee_id', $company->leadEmployeeId)->whereNull('assignee_employee_id')->count());
        $this->assertSame(100, DB::table('lead_assignments')->where('tenant_id', $company->id)->count());
        $this->assertSame(0, DB::table('lead_assignments')->where('tenant_id', $company->id)->whereNotNull('to_assignee_employee_id')->count());
        $this->assertSame(0, Lead::query()->inCompany($company->id)->whereNull('team_lead_employee_id')->whereNull('assignee_employee_id')->count());

        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => $ids,
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('leads/assign-confirm')
            ->where('error', 'These leads are already assigned to this Team Lead.'));
        $this->assertSame(100, DB::table('lead_assignments')->where('tenant_id', $company->id)->count());

        $this->actingAs($company->hr)->get('/leads?pool=unassigned&state=TX')->assertOk()->assertInertia(fn (Assert $page) => $page->where('matching', 0)->where('summary.total', 100)->where('summary.unassigned', 0));
        $this->actingAs($company->hr)->get('/leads?q=Bulk%20001&university=State+University')->assertOk()->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.unassigned', false));
        $this->assertSame($employees, $this->employeeSnapshot());
    }

    public function test_only_active_manager_and_company_manager_access_levels_qualify(): void
    {
        $company = CompanyFixture::make('Eligible Leads', 'eligibleleads');
        $other = CompanyFixture::make('Other Eligible', 'eligibleother');
        $companyManagerId = $this->teamLeadEmployee($company, 'Company Manager', 'eligibleleads-CM', 'active');
        $inactiveId = $this->teamLeadEmployee($company, 'Manager', 'eligibleleads-OFF', 'inactive');
        $hrEmployeeId = $this->teamLeadEmployee($company, 'HR', 'eligibleleads-HR', 'active');
        $titleOnlyId = $this->teamLeadEmployee($company, 'Employee', 'eligibleleads-TITLE', 'active', 'Team Lead');
        $this->lead($company, ['name' => 'Counted', 'team_lead_employee_id' => $company->leadEmployeeId]);
        $this->lead($company, ['name' => 'Counted Two', 'team_lead_employee_id' => $company->leadEmployeeId]);

        $this->actingAs($company->hr)->post('/leads/assignments/prepare', ['ids' => [$this->lead($company, ['name' => 'Open'])]])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('teamLeads', function ($rows) use ($company, $companyManagerId, $inactiveId, $hrEmployeeId, $titleOnlyId, $other): bool {
                $listed = collect($rows);
                $ids = $listed->pluck('id')->map(fn ($id): int => (int) $id)->all();

                return $listed->contains(fn ($row): bool => (int) $row['id'] === $company->leadEmployeeId && (int) $row['leadCount'] === 2 && $row['accessLevel'] === 'Team Lead')
                    && $listed->contains(fn ($row): bool => (int) $row['id'] === $companyManagerId && $row['accessLevel'] === 'Company Manager' && (int) $row['leadCount'] === 0)
                    && ! in_array($inactiveId, $ids, true)
                    && ! in_array($hrEmployeeId, $ids, true)
                    && ! in_array($titleOnlyId, $ids, true)
                    && ! in_array($other->leadEmployeeId, $ids, true);
            }));
    }

    public function test_leads_already_allocated_are_not_duplicated_unless_reassigned(): void
    {
        $company = CompanyFixture::make('Assign Twice', 'assigntwice');
        $secondLeadId = $this->teamLeadEmployee($company, 'Manager', 'assigntwice-TWO', 'active');
        $same = $this->lead($company, ['name' => 'Already Here']);
        $held = $this->lead($company, [
            'name' => 'Held Elsewhere',
            'team_lead_employee_id' => $secondLeadId,
            'assignee_employee_id' => $company->reportEmployeeId,
        ]);

        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => [$same],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => [$same],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'These leads are already assigned to this Team Lead.'));
        $this->assertSame(1, DB::table('lead_assignments')->where('lead_id', $same)->count());

        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => [$held],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'These leads already belong to another Team Lead. Reassign them only from the confirmation screen.'));
        $this->assertSame($secondLeadId, (int) DB::table('leads')->where('id', $held)->value('team_lead_employee_id'));
        $this->assertSame($company->reportEmployeeId, (int) DB::table('leads')->where('id', $held)->value('assignee_employee_id'));
        $this->assertSame(0, DB::table('lead_assignments')->where('lead_id', $held)->count());

        $this->actingAs($company->hr)->post('/leads/assignments/review', [
            'ids' => [$held],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('count', 0)
            ->where('heldByOthers', 1)
            ->where('reassignAvailable', true));

        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => [$held],
            'team_lead_employee_id' => $company->leadEmployeeId,
            'reassign' => 1,
        ])->assertRedirect()->assertSessionHas('status', 'Reassigned 1 lead to assigntwice-LEAD Person.');

        $moved = DB::table('leads')->where('id', $held)->first();
        $this->assertSame($company->leadEmployeeId, (int) $moved->team_lead_employee_id);
        $this->assertNull($moved->assignee_employee_id);
        $record = DB::table('lead_assignments')->where('lead_id', $held)->first();
        $this->assertSame($secondLeadId, (int) $record->from_team_lead_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $record->to_team_lead_employee_id);
        $this->assertSame($company->reportEmployeeId, (int) $record->from_assignee_employee_id);
        $this->assertNull($record->to_assignee_employee_id);
        $this->assertSame(1, DB::table('lead_assignments')->where('lead_id', $held)->count());
    }

    public function test_assignment_cannot_cross_companies(): void
    {
        $company = CompanyFixture::make('Assign Tenant', 'assigntenant');
        $other = CompanyFixture::make('Other Assign Tenant', 'assignother');
        $own = $this->lead($company, ['name' => 'Own Candidate']);
        $foreign = $this->lead($other, ['name' => 'Foreign Candidate']);
        $employees = $this->employeeSnapshot();

        $this->actingAs($company->hr)->post('/leads/assignments/review', [
            'ids' => [$own],
            'team_lead_employee_id' => $other->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('leads/assign')
            ->where('error', 'Choose an active Team Lead in this company.'));
        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => [$own, $foreign],
            'team_lead_employee_id' => $other->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'Choose an active Team Lead in this company.'));
        $this->assertSame(0, DB::table('lead_assignments')->count());
        $this->assertNull(DB::table('leads')->where('id', $own)->value('team_lead_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $foreign)->value('team_lead_employee_id'));

        $this->actingAs($company->hr)->post('/leads/assignments', [
            'ids' => [$own, $foreign],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertRedirect();
        $this->assertSame($company->leadEmployeeId, (int) DB::table('leads')->where('id', $own)->value('team_lead_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $own)->value('assignee_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $foreign)->value('team_lead_employee_id'));
        $this->assertSame(1, DB::table('lead_assignments')->count());
        $this->assertSame($company->id, (int) DB::table('lead_assignments')->value('tenant_id'));
        $this->assertSame($employees, $this->employeeSnapshot());
    }

    public function test_team_leads_employees_and_accounts_cannot_assign(): void
    {
        $company = CompanyFixture::make('Assign Denied', 'assigndenied');
        $leadId = $this->lead($company, ['name' => 'Protected']);
        $payload = ['ids' => [$leadId], 'team_lead_employee_id' => $company->leadEmployeeId];

        foreach ([$company->lead, $company->employee, $this->roleUser($company, 'Accounts')] as $user) {
            $this->actingAs($user)->post('/leads/assignments/prepare', ['ids' => [$leadId]])->assertForbidden();
            $this->actingAs($user)->post('/leads/assignments/review', $payload)->assertForbidden();
            $this->actingAs($user)->post('/leads/assignments', $payload)->assertForbidden();
        }

        $this->assertSame(0, DB::table('lead_assignments')->count());
        $this->assertNull(DB::table('leads')->where('id', $leadId)->value('team_lead_employee_id'));
    }

    private function lead(CompanyFixture $company, array $overrides): int
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'tenant_id' => $company->id,
            'name' => 'Candidate',
            'pipeline_status' => 'new',
            'created_by' => $company->hr->id,
        ], $overrides))->save();

        return (int) $lead->id;
    }

    private function teamLeadEmployee(CompanyFixture $company, string $role, string $code, string $status, ?string $designation = null): int
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

        if ($designation !== null) {
            $designationId = DB::table('designations')->insertGetId([
                'tenant_id' => $company->id,
                'name' => $designation,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
            'manager_id' => null,
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
