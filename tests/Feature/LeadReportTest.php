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

class LeadReportTest extends TestCase
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

    public function test_company_reports_aggregate_the_tenant_and_export_only_those_leads(): void
    {
        $company = CompanyFixture::make('Lead Reports', 'leadreport');
        $other = CompanyFixture::make('Other Lead Reports', 'leadreportother');
        $owner = $this->roleUser($company, 'Owner');
        $secondLead = $this->roleUser($company, 'Manager');
        $secondReport = $this->roleUser($company, 'Employee');
        $secondLeadId = $this->staff($company, $secondLead, 'leadreport-B', null);
        $secondReportId = $this->staff($company, $secondReport, 'leadreport-BREP', $secondLeadId);
        $employees = $this->employeeSnapshot();

        $this->lead($company, [
            'name' => 'Pool Person',
            'state' => 'TX',
            'university' => 'Austin',
            'graduation_term' => 'MAY(2026)',
            'designations' => 'Analyst',
            'follow_up_on' => '2026-11-10',
            'team_lead_employee_id' => null,
            'assignee_employee_id' => null,
        ]);
        $this->lead($company, [
            'name' => 'Waiting Person',
            'state' => 'TX',
            'university' => 'Austin',
            'graduation_term' => 'MAY(2026)',
            'designations' => 'Analyst',
            'pipeline_status' => 'contacted',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => null,
        ]);
        $this->lead($company, [
            'name' => 'Retained Person',
            'state' => 'CA',
            'university' => 'Berkeley',
            'graduation_term' => 'DEC(2026)',
            'designations' => 'Manager',
            'pipeline_status' => 'interested',
            'follow_up_on' => '2026-11-11',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->leadEmployeeId,
        ]);
        $converted = $this->lead($company, [
            'name' => 'Converted Person',
            'state' => 'CA',
            'university' => 'Berkeley',
            'graduation_term' => 'DEC(2026)',
            'designations' => 'Analyst',
            'pipeline_status' => 'converted',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->reportEmployeeId,
        ]);
        $this->lead($company, [
            'name' => 'Second Team Person',
            'state' => 'NY',
            'university' => 'NYU',
            'graduation_term' => 'MAY(2027)',
            'designations' => 'Recruiter',
            'team_lead_employee_id' => $secondLeadId,
            'assignee_employee_id' => $secondReportId,
        ]);
        $foreign = $this->lead($other, [
            'name' => 'Secret Report',
            'state' => 'ZZ',
            'university' => 'Secret U',
            'graduation_term' => 'MAY(2026)',
            'designations' => 'Analyst',
            'team_lead_employee_id' => $other->leadEmployeeId,
            'assignee_employee_id' => $other->reportEmployeeId,
        ]);
        $this->activity($company, $converted, $company->hr->id, 'contacted', '2026-11-11 04:00:00');
        $this->activity($other, $foreign, $other->hr->id, 'contacted', '2026-11-11 04:00:00');

        $this->actingAs($company->hr)->get('/leads/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/reports')
                ->where('summary.total', 5)
                ->where('summary.unassigned', 1)
                ->where('summary.assignedToTeamLeads', 4)
                ->where('summary.assignedToEmployees', 2)
                ->where('summary.overdue', 1)
                ->where('summary.dueToday', 1)
                ->where('summary.activities', 1)
                ->where('pipeline', fn ($rows): bool => $this->statusCount($rows, 'new') === 2
                    && $this->statusCount($rows, 'contacted') === 1
                    && $this->statusCount($rows, 'interested') === 1
                    && $this->statusCount($rows, 'converted') === 1
                    && $this->statusCount($rows, 'follow_up') === 0)
                ->where('teamLeads', function ($rows) use ($company): bool {
                    $lead = collect($rows)->firstWhere('name', 'leadreport-LEAD Person');
                    $otherLead = collect($rows)->firstWhere('name', 'leadreport-B Person');

                    return count($rows) === 2
                        && $lead !== null
                        && (int) $lead['total'] === 3
                        && (int) $lead['retained'] === 1
                        && (int) $lead['distributed'] === 1
                        && (int) $lead['undistributed'] === 1
                        && (int) $lead['total'] === (int) $lead['retained'] + (int) $lead['distributed'] + (int) $lead['undistributed']
                        && (int) $lead['converted'] === 1
                        && (int) $lead['conversionRate'] === 33
                        && (int) $lead['dueToday'] === 1
                        && (int) $lead['overdue'] === 0
                        && (int) $lead['activities'] === 1
                        && $otherLead !== null
                        && (int) $otherLead['total'] === 1
                        && (int) $otherLead['distributed'] === 1
                        && (int) $otherLead['activities'] === 0
                        && (int) $lead['id'] === $company->leadEmployeeId;
                })
                ->where('employees', function ($rows): bool {
                    $report = collect($rows)->firstWhere('name', 'leadreport-REP Person');

                    return $report !== null
                        && (int) $report['leads'] === 1
                        && (int) $report['converted'] === 1
                        && (int) $report['activities'] === 1
                        && $report['teamLead'] === 'leadreport-LEAD Person'
                        && collect($rows)->firstWhere('name', 'leadreportother-REP Person') === null;
                })
                ->where('states', fn ($rows): bool => $this->bucket($rows, 'TX') === 2 && $this->bucket($rows, 'CA') === 2 && $this->bucket($rows, 'NY') === 1 && $this->bucket($rows, 'ZZ') === null)
                ->where('universities', fn ($rows): bool => $this->bucket($rows, 'Austin') === 2 && $this->bucket($rows, 'Berkeley') === 2 && $this->bucket($rows, 'Secret U') === null)
                ->where('graduationTerms', fn ($rows): bool => $this->bucket($rows, 'MAY(2026)') === 2 && $this->bucket($rows, 'DEC(2026)') === 2 && $this->bucket($rows, 'MAY(2027)') === 1)
                ->where('designations', fn ($rows): bool => $this->bucket($rows, 'Analyst') === 3 && $this->bucket($rows, 'Manager') === 1 && $this->bucket($rows, 'Recruiter') === 1));

        $this->actingAs($owner)->get('/leads/reports')->assertOk();
        $csv = $this->actingAs($company->hr)->get('/leads/reports/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();
        $this->assertStringContainsString('Pipeline status', $csv);
        $this->assertStringContainsString('Pool Person', $csv);
        $this->assertStringContainsString('Converted Person', $csv);
        $this->assertStringContainsString('leadreport-REP Person', $csv);
        $this->assertStringNotContainsString('Secret Report', $csv);
        $this->assertSame($employees, $this->employeeSnapshot());
    }

    public function test_date_filters_limit_added_leads_without_hiding_activity_in_the_range(): void
    {
        $company = CompanyFixture::make('Report Dates', 'reportdates');
        $old = $this->lead($company, [
            'name' => 'Old Person',
            'created_at' => '2026-10-15 06:00:00',
            'follow_up_on' => '2026-11-10',
            'team_lead_employee_id' => null,
            'assignee_employee_id' => null,
        ]);
        $this->lead($company, [
            'name' => 'New Person',
            'created_at' => '2026-11-05 06:00:00',
            'follow_up_on' => '2026-11-11',
            'pipeline_status' => 'contacted',
            'team_lead_employee_id' => null,
            'assignee_employee_id' => null,
        ]);
        $this->activity($company, $old, $company->hr->id, 'contacted', '2026-11-05 06:00:00');

        $this->actingAs($company->hr)->get('/leads/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 2)->where('summary.overdue', 1)->where('summary.dueToday', 1)->where('summary.activities', 1));

        $this->actingAs($company->hr)->get('/leads/reports?from=2026-11-30&to=2026-11-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-11-01')
                ->where('filters.to', '2026-11-30')
                ->where('summary.total', 1)
                ->where('summary.overdue', 0)
                ->where('summary.dueToday', 1)
                ->where('summary.activities', 1));

        $csv = $this->actingAs($company->hr)->get('/leads/reports/export?from=2026-11-01&to=2026-11-30')->assertOk()->streamedContent();
        $this->assertStringContainsString('New Person', $csv);
        $this->assertStringNotContainsString('Old Person', $csv);
        $this->actingAs($company->hr)->get('/leads/reports?from=2026-13-01')->assertSessionHasErrors('from');
    }

    public function test_a_team_lead_report_stays_inside_that_team(): void
    {
        $company = CompanyFixture::make('Team Report', 'teamreport');
        $other = CompanyFixture::make('Other Team Report', 'teamreportother');
        $idle = $this->roleUser($company, 'Employee');
        $this->staff($company, $idle, 'teamreport-IDLE', $company->leadEmployeeId);
        $secondLead = $this->roleUser($company, 'Manager');
        $secondReport = $this->roleUser($company, 'Employee');
        $secondLeadId = $this->staff($company, $secondLead, 'teamreport-B', null);
        $secondReportId = $this->staff($company, $secondReport, 'teamreport-BREP', $secondLeadId);

        $this->lead($company, [
            'name' => 'Waiting Person',
            'created_at' => '2026-10-15 06:00:00',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => null,
        ]);
        $this->lead($company, [
            'name' => 'Retained Person',
            'pipeline_status' => 'interested',
            'follow_up_on' => '2026-11-10',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->leadEmployeeId,
        ]);
        $distributed = $this->lead($company, [
            'name' => 'Converted Person',
            'pipeline_status' => 'converted',
            'follow_up_on' => '2026-11-11',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->reportEmployeeId,
        ]);
        $otherTeam = $this->lead($company, [
            'name' => 'Other Team Person',
            'team_lead_employee_id' => $secondLeadId,
            'assignee_employee_id' => $secondReportId,
            'follow_up_on' => '2026-11-10',
        ]);
        $foreign = $this->lead($other, [
            'name' => 'Foreign Team Person',
            'team_lead_employee_id' => $other->leadEmployeeId,
            'assignee_employee_id' => $other->reportEmployeeId,
        ]);
        $this->activity($company, $distributed, $company->employee->id, 'converted', '2026-11-11 04:00:00');
        $this->activity($company, $otherTeam, $secondLead->id, 'contacted', '2026-11-11 04:00:00');
        $this->activity($other, $foreign, $other->hr->id, 'contacted', '2026-11-11 04:00:00');

        $this->actingAs($company->lead)->get('/my-leads/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/team-report')
                ->where('summary.total', 3)
                ->where('summary.retained', 1)
                ->where('summary.distributed', 1)
                ->where('summary.undistributed', 1)
                ->where('summary.converted', 1)
                ->where('summary.overdue', 1)
                ->where('summary.dueToday', 1)
                ->where('summary.activities', 1)
                ->where('employees', function ($rows): bool {
                    $report = collect($rows)->firstWhere('name', 'teamreport-REP Person');
                    $idle = collect($rows)->firstWhere('name', 'teamreport-IDLE Person');

                    return count($rows) === 2
                        && $report !== null
                        && (int) $report['leads'] === 1
                        && (int) $report['converted'] === 1
                        && (int) $report['conversionRate'] === 100
                        && (int) $report['dueToday'] === 1
                        && (int) $report['activities'] === 1
                        && $report['statuses'] === [['label' => 'Converted', 'count' => 1]]
                        && $idle !== null
                        && (int) $idle['leads'] === 0
                        && (int) $idle['activities'] === 0
                        && collect($rows)->firstWhere('name', 'teamreport-BREP Person') === null
                        && collect($rows)->firstWhere('name', 'teamreport-OUT Person') === null;
                }));

        $this->actingAs($company->lead)->get('/my-leads/reports?from=2026-11-01&to=2026-11-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 2)
                ->where('summary.undistributed', 0)
                ->where('summary.distributed', 1)
                ->where('summary.activities', 1)
                ->where('employees', fn ($rows): bool => collect($rows)->firstWhere('name', 'teamreport-BREP Person') === null));

        $this->actingAs($company->hr)->get('/my-leads/reports')->assertForbidden();
        $this->actingAs($company->employee)->get('/my-leads/reports')->assertForbidden();
        $this->actingAs($company->lead)->get('/leads/reports')->assertForbidden();
        $this->actingAs($company->lead)->get('/leads/reports/export')->assertForbidden();
    }

    public function test_an_employee_report_counts_only_personal_leads_and_activity(): void
    {
        $company = CompanyFixture::make('My Report', 'myreport');
        $this->lead($company, [
            'name' => 'Converted Mine',
            'pipeline_status' => 'converted',
            'follow_up_on' => '2026-11-11',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->reportEmployeeId,
        ]);
        $pending = $this->lead($company, [
            'name' => 'Overdue Mine',
            'follow_up_on' => '2026-11-10',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->reportEmployeeId,
        ]);
        $this->lead($company, [
            'name' => 'Upcoming Mine',
            'pipeline_status' => 'contacted',
            'follow_up_on' => '2026-11-12',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->reportEmployeeId,
        ]);
        $this->lead($company, [
            'name' => 'Hidden Coworker',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->outsideEmployeeId,
            'follow_up_on' => '2026-11-10',
        ]);
        $this->activity($company, $pending, $company->employee->id, 'contacted', '2026-11-11 04:00:00');
        $this->activity($company, $pending, $company->hr->id, 'interested', '2026-11-11 06:00:00');

        $this->actingAs($company->employee)->get('/leads/mine/report')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/my-report')
                ->where('summary.total', 3)
                ->where('summary.converted', 1)
                ->where('summary.pending', 2)
                ->where('summary.dueToday', 1)
                ->where('summary.overdue', 1)
                ->where('summary.upcoming', 1)
                ->where('summary.activities', 1)
                ->where('summary.latestActivity', '2026-11-11 09:30')
                ->where('pipeline', fn ($rows): bool => $this->statusCount($rows, 'new') === 1 && $this->statusCount($rows, 'converted') === 1 && $this->statusCount($rows, 'contacted') === 1)
                ->where('activityByStatus', [['label' => 'Contacted', 'count' => 1]]));

        $this->actingAs($company->hr)->get('/leads/mine/report')->assertForbidden();
        $this->actingAs($company->lead)->get('/leads/mine/report')->assertForbidden();
        $this->actingAs($company->employee)->get('/leads/reports')->assertForbidden();
        $this->actingAs($company->employee)->get('/leads/reports/export')->assertForbidden();
    }

    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        $company = CompanyFixture::make('Report Formula', 'reportformula');
        $this->lead($company, [
            'name' => '=HYPERLINK("http://evil.test","Open")',
            'email' => '+1 555 0100',
            'phone' => '-1',
            'university' => '@SUM(1+1)',
            'team_lead_employee_id' => null,
            'assignee_employee_id' => null,
        ]);

        $csv = $this->actingAs($company->hr)->get('/leads/reports/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1 555 0100", $csv);
        $this->assertStringContainsString("'-1", $csv);
        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|[\r\n])[=+\-@]/', $csv);
    }

    public function test_accounts_cannot_open_lead_reports(): void
    {
        $company = CompanyFixture::make('Report Access', 'reportaccess');
        $accounts = $this->roleUser($company, 'Accounts');

        foreach (['/leads/reports', '/leads/reports/export', '/my-leads/reports', '/leads/mine/report'] as $url) {
            $this->actingAs($accounts)->get($url)->assertForbidden();
        }
    }

    private function statusCount(mixed $rows, string $value): int
    {
        $row = collect($rows)->firstWhere('value', $value);

        return $row === null ? -1 : (int) $row['count'];
    }

    private function bucket(mixed $rows, string $label): ?int
    {
        $row = collect($rows)->firstWhere('label', $label);

        return $row === null ? null : (int) $row['total'];
    }

    private function lead(CompanyFixture $company, array $overrides): int
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'tenant_id' => $company->id,
            'name' => 'Candidate',
            'pipeline_status' => 'new',
            'created_by' => $company->hr->id,
        ], $overrides))->save();

        if (is_string($createdAt)) {
            DB::table('leads')->where('id', $lead->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return (int) $lead->id;
    }

    private function activity(CompanyFixture $company, int $leadId, int $actorId, string $status, string $at): void
    {
        DB::table('lead_activities')->insert([
            'tenant_id' => $company->id,
            'lead_id' => $leadId,
            'actor_id' => $actorId,
            'type' => 'follow_up',
            'pipeline_status' => $status,
            'follow_up_on' => '2026-11-12',
            'body' => 'Called',
            'created_at' => $at,
        ]);
    }

    private function staff(CompanyFixture $company, User $user, string $code, ?int $managerId): int
    {
        $designationId = (int) DB::table('designations')->where('tenant_id', $company->id)->value('id');

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
            'status' => 'active',
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
