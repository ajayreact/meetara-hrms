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
                ->where('summary.myTotal', 0)
                ->where('summary.teamTotal', 1)
                ->where('section', 'pending')
                ->where('destinations.0.id', $company->leadEmployeeId)
                ->where('destinations.0.name', 'Me (Team Lead)')
                ->where('destinations.0.self', true)
                ->where('destinations.1.id', $company->reportEmployeeId)
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

        foreach ([$company->outsideEmployeeId, $inactiveId, $otherReportId, $other->reportEmployeeId] as $employeeId) {
            $this->actingAs($company->lead)->post('/my-leads/distribute', [
                'ids' => [$ids[0]],
                'employee_id' => $employeeId,
            ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'Choose an active employee who reports to you.'));
        }

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => [$ids[0]],
            'employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'Select at least one lead that is not yet distributed.'));

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
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.retained', 1)
                ->where('summary.distributed', 0)
                ->where('summary.pending', 1)
                ->where('summary.myTotal', 1)
                ->where('summary.teamTotal', 2)
                ->where('leads.0.id', $leadId));
        $this->actingAs($company->employee)->post('/my-leads/recall', ['ids' => [$leadId]])->assertForbidden();
    }

    public function test_team_lead_can_assign_pool_leads_to_herself_or_an_employee(): void
    {
        $company = CompanyFixture::make('Assign Self', 'assignself');
        $mine = $this->lead($company, $company->leadEmployeeId, ['name' => 'Keep For Me']);
        $theirs = $this->lead($company, $company->leadEmployeeId, ['name' => 'Give To Report']);
        $left = $this->lead($company, $company->leadEmployeeId, ['name' => 'Still In Pool']);

        $this->actingAs($company->lead)->post('/my-leads/distribute/review', [
            'ids' => [$mine],
            'employee_id' => $company->leadEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('leads/team-confirm')
            ->where('action', 'retain')
            ->where('count', 1));

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => [$mine],
            'employee_id' => $company->leadEmployeeId,
        ])->assertRedirect(route('hrms.my-leads', ['section' => 'retained']))
            ->assertSessionHas('status', 'Retained 1 lead.');

        $this->assertSame($company->leadEmployeeId, (int) DB::table('leads')->where('id', $mine)->value('assignee_employee_id'));
        $this->assertSame(1, DB::table('lead_assignments')->where('lead_id', $mine)->where('note', 'Retained by the Team Lead')->count());

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => [$theirs],
            'employee_id' => $company->reportEmployeeId,
        ])->assertRedirect(route('hrms.my-leads', ['section' => 'distributed']))
            ->assertSessionHas('status', 'Distributed 1 lead to assignself-REP Person.');

        $this->assertSame($company->reportEmployeeId, (int) DB::table('leads')->where('id', $theirs)->value('assignee_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $left)->value('assignee_employee_id'));

        $this->actingAs($company->lead)->get('/my-leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.myTotal', 1)
                ->where('summary.distributed', 1)
                ->where('summary.pending', 1)
                ->where('summary.teamTotal', 3)
                ->where('destinations', fn ($rows): bool => collect($rows)->first()['name'] === 'Me (Team Lead)'
                    && collect($rows)->contains(fn ($row): bool => (int) $row['id'] === $company->reportEmployeeId && $row['self'] === false)));

        $this->actingAs($company->lead)->get('/my-leads?section=total')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'total')->where('matching', 3));

        $this->actingAs($company->lead)->post("/my-leads/{$left}/follow-ups", [
            'pipeline_status' => 'contacted',
            'follow_up_on' => '2026-11-20',
            'remarks' => 'Status only',
        ])->assertRedirect("/my-leads/{$left}");
        $this->assertNull(DB::table('leads')->where('id', $left)->value('assignee_employee_id'));
    }

    public function test_team_lead_allocation_counts_use_the_priya_split_and_ignore_other_teams(): void
    {
        $company = CompanyFixture::make('Priya Split', 'priyasplit');
        $other = CompanyFixture::make('Other Split', 'othersplit');
        $secondLeadId = $this->employee($company, 'Manager', 'priyasplit-TWO', 'active', null);
        $pool = $this->lead($company, null, ['name' => 'Company Pool Lead']);
        $otherTeam = $this->lead($company, $secondLeadId, ['name' => 'Other Team Lead']);
        $foreign = $this->lead($other, $other->leadEmployeeId, ['name' => 'Other Company Lead']);
        $pendingIds = [];
        $retainedIds = [];
        $distributedIds = [];

        foreach (range(1, 30) as $number) {
            $pendingIds[] = $this->lead($company, $company->leadEmployeeId, ['name' => sprintf('Pending %02d', $number)]);
        }

        foreach (range(1, 30) as $number) {
            $retainedIds[] = $this->lead($company, $company->leadEmployeeId, [
                'name' => sprintf('Retained %02d', $number),
                'assignee_employee_id' => $company->leadEmployeeId,
            ]);
        }

        foreach (range(1, 40) as $number) {
            $distributedIds[] = $this->lead($company, $company->leadEmployeeId, [
                'name' => sprintf('Distributed %02d', $number),
                'assignee_employee_id' => $company->reportEmployeeId,
            ]);
        }

        $this->actingAs($company->lead)->get('/my-leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/team')
                ->where('summary.myTotal', 30)
                ->where('summary.distributed', 40)
                ->where('summary.pending', 30)
                ->where('summary.retained', 30)
                ->where('summary.teamTotal', 100)
                ->where('matching', 30)
                ->where('leads', fn ($rows): bool => ! collect($rows)->contains('id', $pool)
                    && ! collect($rows)->contains('id', $otherTeam)
                    && ! collect($rows)->contains('id', $foreign)));

        $this->actingAs($company->lead)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/team-lead')
                ->where('leadBoard.myTotal', 30)
                ->where('leadBoard.distributed', 40)
                ->where('leadBoard.pending', 30)
                ->where('leadBoard.teamTotal', 100));

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => array_slice($pendingIds, 0, 5),
            'employee_id' => $company->leadEmployeeId,
        ])->assertRedirect(route('hrms.my-leads', ['section' => 'retained']));
        $this->actingAs($company->lead)->get('/my-leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.myTotal', 35)
                ->where('summary.distributed', 40)
                ->where('summary.pending', 25)
                ->where('summary.teamTotal', 100));

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => array_slice($pendingIds, 5, 10),
            'employee_id' => $company->reportEmployeeId,
        ])->assertRedirect(route('hrms.my-leads', ['section' => 'distributed']));
        $this->actingAs($company->lead)->get('/my-leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.myTotal', 35)
                ->where('summary.distributed', 50)
                ->where('summary.pending', 15)
                ->where('summary.teamTotal', 100));

        $pendingFollowUp = $pendingIds[15];
        $this->actingAs($company->lead)->post("/my-leads/{$pendingFollowUp}/follow-ups", [
            'pipeline_status' => 'contacted',
            'follow_up_on' => '2026-11-20',
            'remarks' => 'Followed up without retaining',
        ])->assertRedirect("/my-leads/{$pendingFollowUp}")->assertSessionHas('status', 'Follow-up saved.');
        $this->assertNull(DB::table('leads')->where('id', $pendingFollowUp)->value('assignee_employee_id'));
        $this->assertSame('contacted', DB::table('leads')->where('id', $pendingFollowUp)->value('pipeline_status'));

        $this->actingAs($company->lead)->post("/my-leads/{$distributedIds[0]}/follow-ups", [
            'pipeline_status' => 'interested',
            'follow_up_on' => '2026-11-21',
            'remarks' => 'Checked employee lead',
        ])->assertRedirect("/my-leads/{$distributedIds[0]}");
        $this->assertSame($company->reportEmployeeId, (int) DB::table('leads')->where('id', $distributedIds[0])->value('assignee_employee_id'));

        $this->assertNull(DB::table('leads')->where('id', $pool)->value('team_lead_employee_id'));
        $this->assertSame($secondLeadId, (int) DB::table('leads')->where('id', $otherTeam)->value('team_lead_employee_id'));
        $this->assertSame($other->leadEmployeeId, (int) DB::table('leads')->where('id', $foreign)->value('team_lead_employee_id'));
        $this->assertCount(30, $retainedIds);
        $this->actingAs($company->employee)->get('/my-leads')->assertForbidden();
    }

    public function test_team_lead_dashboard_separates_my_leads_from_team_totals(): void
    {
        $company = CompanyFixture::make('Lead Board', 'leadboard');
        $pending = $this->lead($company, $company->leadEmployeeId, ['name' => 'Still Pending']);
        $retained = $this->lead($company, $company->leadEmployeeId, [
            'name' => 'Kept By Lead',
            'assignee_employee_id' => $company->leadEmployeeId,
            'pipeline_status' => 'contacted',
        ]);
        $distributed = $this->lead($company, $company->leadEmployeeId, [
            'name' => 'With Employee',
            'assignee_employee_id' => $company->reportEmployeeId,
            'pipeline_status' => 'qualified',
        ]);

        $this->actingAs($company->lead)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/team-lead')
                ->where('leadBoard.myTotal', 1)
                ->where('leadBoard.teamTotal', 3)
                ->where('leadBoard.pending', 1)
                ->where('leadBoard.distributed', 1)
                ->where('leadBoard.myLeads.0.id', $retained)
                ->where('leadBoard.myLeads.0.canFollowUp', true)
                ->where('leadBoard.myPipeline', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['value'] === 'contacted' && (int) $row['count'] === 1))
                ->where('leadBoard.employees', function ($rows) use ($company): bool {
                    $report = collect($rows)->first(fn ($row): bool => (int) $row['id'] === $company->reportEmployeeId);

                    return $report !== null && (int) $report['total'] === 1;
                }));

        $this->actingAs($company->lead)->get('/my-leads?section=retained')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.myTotal', 1)
                ->where('summary.teamTotal', 3)
                ->where('summary.pending', 1)
                ->where('summary.distributed', 1)
                ->where('leads.0.id', $retained)
                ->where('leads.0.canFollowUp', true)
                ->where('myPipeline', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['value'] === 'contacted' && (int) $row['count'] === 1)));

        $this->actingAs($company->lead)->post("/my-leads/{$distributed}/follow-ups", [
            'pipeline_status' => 'contacted',
            'follow_up_on' => '2026-11-20',
            'remarks' => 'Checked in without taking ownership',
        ])->assertRedirect("/my-leads/{$distributed}")->assertSessionHas('status', 'Follow-up saved.');

        $this->assertSame($company->reportEmployeeId, (int) DB::table('leads')->where('id', $distributed)->value('assignee_employee_id'));
        $this->assertNull(DB::table('leads')->where('id', $pending)->value('assignee_employee_id'));
        $this->assertSame($company->leadEmployeeId, (int) DB::table('leads')->where('id', $retained)->value('assignee_employee_id'));
        $this->actingAs($company->employee)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/employee')
            ->where('leadBoard', null));
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
