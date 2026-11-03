<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class RoleAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_role_stays_inside_its_authorized_routes(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $owner = $this->roleUser($company, 'Owner');
        $accounts = $this->roleUser($company, 'Accounts');
        $platform = User::factory()->create();
        $platform->forceFill([
            'is_platform_admin' => true,
            'tenant_id' => $company->id,
        ])->save();

        foreach (['/payroll', '/payroll/runs', '/salary', '/payments', '/payslips'] as $uri) {
            $this->actingAs($platform)->get($uri)->assertForbidden();
        }
        $this->actingAs($platform)->get('/payslips/'.$company->payrollItemId)->assertNotFound();
        $this->actingAs($platform)->get('/employees/'.$company->reportEmployeeId)->assertNotFound();
        $this->actingAs($platform)->post('/payroll/runs/'.$company->payrollRunId.'/approve')->assertNotFound();
        $this->actingAs($platform)->get('/route-map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('route-map/index')
            ->where('map.canViewPreciseLocations', false)
            ->where('map.points', []));
        $this->actingAs($platform)->get('/company/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('payrollSettings', null));

        $this->actingAs($owner)->get('/payroll')->assertOk();
        $this->actingAs($owner)->get('/salary')->assertOk();
        $this->actingAs($owner)->get('/payments')->assertForbidden();
        $this->actingAs($owner)->post('/payroll/runs/'.$company->payrollRunId.'/calculate')->assertForbidden();
        $this->actingAs($owner)->post('/payroll/runs/'.$company->payrollRunId.'/process', [
            'note' => 'Process now',
            'confirm' => 'PROCESS',
        ])->assertForbidden();
        $this->actingAs($owner)->get('/employees')->assertOk()->assertSee($company->reportEmail)->assertDontSee($other->reportEmail);
        $this->actingAs($owner)->get('/company/settings')->assertInertia(fn (Assert $page) => $page->where('payrollSettings.statutory', 'Not configured'));
        $this->actingAs($owner)->get('/route-map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('route-map/index')->where('map.canViewPreciseLocations', true));

        $this->actingAs($company->hr)->get('/payroll')->assertOk();
        $this->actingAs($company->hr)->get('/payments')->assertForbidden();
        $this->actingAs($company->hr)->get('/salary')->assertOk();
        $this->actingAs($company->hr)->get('/employees/'.$other->reportEmployeeId)->assertNotFound();
        $this->actingAs($company->hr)->post('/company/access-levels/accounts')->assertForbidden();
        $this->actingAs($company->hr)->get('/payslips/'.$company->payrollItemId)->assertNotFound();
        $this->actingAs($company->hr)->get('/attendance/export')->assertOk();

        $this->actingAs($accounts)->get('/employees')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'))->assertDontSee($company->reportEmail);
        $this->actingAs($accounts)->post('/employees', [])->assertForbidden();
        $this->actingAs($accounts)->get('/employees/'.$company->reportEmployeeId)->assertNotFound();
        $this->actingAs($accounts)->get('/offices')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($accounts)->get('/attendance')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($accounts)->get('/attendance/export')->assertForbidden();
        $this->actingAs($accounts)->get('/payroll')->assertOk();
        $this->actingAs($accounts)->get('/payments')->assertOk();
        $this->actingAs($accounts)->post('/leave/'.$company->leaveId.'/approve')->assertNotFound();
        $this->actingAs($accounts)->post('/holidays', [])->assertForbidden();
        $this->actingAs($accounts)->get('/route-map')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));

        $this->actingAs($company->lead)->get('/salary')->assertForbidden();
        $this->actingAs($company->lead)->get('/payroll')->assertForbidden();
        $this->actingAs($company->lead)->get('/payslips')->assertForbidden();
        $this->actingAs($company->lead)->get('/payments')->assertForbidden();
        $this->actingAs($company->lead)->get('/employees/'.$company->outsideEmployeeId)->assertNotFound();
        $this->actingAs($company->lead)->get('/employees/'.$other->reportEmployeeId)->assertNotFound();
        $this->actingAs($company->lead)->put('/employees/'.$company->reportEmployeeId, [])->assertForbidden();
        $this->actingAs($company->lead)->get('/offices')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->lead)->get('/attendance/export')->assertForbidden();
        $this->actingAs($company->lead)->post('/attendance/'.$company->attendanceId.'/recover', ['reason' => 'Closed the open session'])->assertForbidden();
        $this->actingAs($company->lead)->get('/route-map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('route-map/index'));

        $this->actingAs($company->employee)->get('/employees')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->get('/employees/'.$company->outsideEmployeeId)->assertNotFound();
        $this->actingAs($company->employee)->get('/payroll')->assertForbidden();
        $this->actingAs($company->employee)->get('/salary')->assertForbidden();
        $this->actingAs($company->employee)->get('/payments')->assertForbidden();
        $this->actingAs($company->employee)->get('/attendance')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->get('/my-attendance')->assertOk();
        $this->actingAs($company->employee)->get('/route-map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('route-map/index')->where('map.canViewPreciseLocations', true));
        $this->actingAs($company->employee)->get('/companies')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->get('/audit')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
    }

    public function test_company_records_cannot_be_opened_from_another_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $accounts = $this->roleUser($company, 'Accounts');
        $outside = $this->employeeUser($company->outsideEmployeeId);

        $this->actingAs($other->hr)->get('/employees/'.$company->reportEmployeeId)->assertNotFound();
        $this->actingAs($other->hr)->post('/attendance/'.$company->attendanceId.'/recover', ['reason' => 'Closed the open session'])->assertNotFound();
        $this->actingAs($other->hr)->post('/leave/'.$company->leaveId.'/approve')->assertNotFound();
        $this->actingAs($other->hr)->get('/payroll/runs/'.$company->payrollRunId)->assertNotFound();
        $this->actingAs($other->hr)->get('/payslips/'.$company->payrollItemId)->assertNotFound();
        $this->actingAs($accounts)->post('/attendance/'.$other->attendanceId.'/recover', ['reason' => 'Closed the open session'])->assertNotFound();
        $this->actingAs($outside)->get('/employees/'.$company->reportEmployeeId)->assertNotFound();

        DB::table('payroll_events')->insert([
            'tenant_id' => $company->id,
            'payroll_run_id' => $company->payrollRunId,
            'user_id' => $company->hr->id,
            'action' => 'reviewed',
            'note' => 'SECRET-AMOUNT-88421',
            'created_at' => now(),
        ]);
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();

        $this->actingAs($platform)->get('/audit')
            ->assertOk()
            ->assertDontSee('SECRET-AMOUNT-88421')
            ->assertDontSee($company->reportEmail)
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($rows) => collect($rows)->contains(fn ($event) => $event['source'] === 'Payroll' && $event['action'] === 'reviewed')));
    }

    public function test_help_desk_resolution_is_audited_and_limited_to_the_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $owner = $this->roleUser($company, 'Owner');
        $accounts = $this->roleUser($company, 'Accounts');
        $outside = $this->employeeUser($company->outsideEmployeeId);
        $before = DB::table('tickets')->count();

        $this->actingAs($outside)->get('/help-desk')->assertInertia(fn (Assert $page) => $page->component('help-desk/index')->where('tickets', []));
        $this->from('/help-desk')->actingAs($company->employee)->post('/help-desk', [
            'category' => 'Facilities',
            'title' => 'Bad',
            'description' => 'Too short to save.',
            'priority' => 'low',
        ])->assertSessionHasErrors('title');
        $this->assertSame($before, DB::table('tickets')->count());

        $this->actingAs($company->employee)->post('/help-desk', [
            'category' => 'Facilities',
            'title' => 'Door badge',
            'description' => 'Unique badge phrase for the office door.',
            'priority' => 'high',
        ])->assertRedirect();
        $ticketId = (int) DB::table('tickets')->where('title', 'Door badge')->value('id');

        $this->from('/help-desk')->actingAs($company->employee)->post('/help-desk/'.$ticketId.'/comments', ['body' => 'x'])->assertSessionHasErrors('body');
        $this->actingAs($company->employee)->post('/help-desk/'.$ticketId.'/comments', ['body' => 'The badge still fails at the side door.'])->assertRedirect();
        $this->actingAs($company->employee)->post('/help-desk/'.$ticketId.'/status', ['status' => 'in_progress'])->assertForbidden();
        $this->actingAs($accounts)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $company->hr->id])->assertForbidden();
        $this->actingAs($other->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $other->hr->id])->assertNotFound();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $other->hr->id])->assertStatus(422);
        $this->assertNull(DB::table('tickets')->where('id', $ticketId)->value('assignee_id'));
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $company->hr->id])->assertRedirect();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $company->hr->id])->assertRedirect();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/comments', ['body' => 'Assigned to the office team.'])->assertRedirect();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/status', ['status' => 'in_progress'])->assertRedirect();
        $this->actingAs($company->employee)->post('/help-desk/'.$ticketId.'/status', ['status' => 'resolved'])->assertForbidden();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/status', ['status' => 'resolved'])->assertRedirect();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/status', ['status' => 'cancelled'])->assertStatus(422);

        $this->assertSame('resolved', DB::table('tickets')->where('id', $ticketId)->value('status'));
        $this->assertSame($company->id, (int) DB::table('tickets')->where('id', $ticketId)->value('tenant_id'));
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'opened')->count());
        $this->assertSame(2, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'commented')->count());
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'assigned')->count());
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'status_resolved')->count());

        $this->actingAs($company->hr)->get('/help-desk?ticket='.$ticketId)->assertInertia(fn (Assert $page) => $page
            ->where('selected.rawStatus', 'resolved')
            ->where('selected.history', fn ($rows) => collect($rows)->pluck('action')->contains('status resolved'))
            ->where('selected.comments', fn ($rows) => collect($rows)->pluck('body')->contains('Assigned to the office team.')));
        $this->actingAs($company->lead)->get('/help-desk?ticket='.$ticketId)->assertInertia(fn (Assert $page) => $page->where('selected', null));
        $this->actingAs($other->hr)->get('/help-desk?ticket='.$ticketId)->assertInertia(fn (Assert $page) => $page->where('selected', null));

        $opened = DB::table('notifications')->where('data->key', 'ticket-'.$ticketId.'-opened')->get();
        $this->assertCount(2, $opened);
        $this->assertEqualsCanonicalizing([$company->hr->id, $owner->id], $opened->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse($opened->contains(fn ($row) => str_contains((string) $row->data, 'Unique badge phrase')));
        $hrNotice = (string) $opened->firstWhere('notifiable_id', $company->hr->id)->id;
        $this->actingAs($company->employee)->post('/inbox/'.$hrNotice.'/read')->assertNotFound();
        $this->actingAs($company->hr)->post('/inbox/'.$hrNotice.'/read')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $hrNotice)->value('read_at'));
    }

    public function test_overtime_approval_does_not_calculate_pay(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $owner = $this->roleUser($company, 'Owner');
        $accounts = $this->roleUser($company, 'Accounts');
        $outside = $this->employeeUser($company->outsideEmployeeId);
        $items = DB::table('payroll_items')->count();

        $this->actingAs($company->employee)->post('/overtime', [
            'work_date' => '2026-11-04',
            'hours' => '2',
            'reason' => 'Covered the late release window.',
        ])->assertRedirect();
        $entryId = (int) DB::table('overtime_entries')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-11-04')->value('id');
        $this->actingAs($company->employee)->post('/overtime', [
            'work_date' => '2026-11-04',
            'hours' => '2',
            'reason' => 'Covered the late release window.',
        ])->assertStatus(422);
        $this->actingAs($accounts)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($other->hr)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($company->hr)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertRedirect();

        $this->assertSame('approved', DB::table('overtime_entries')->where('id', $entryId)->value('status'));
        $this->assertSame('pending', DB::table('overtime_entries')->where('id', $entryId)->value('payroll_status'));
        $this->assertSame(120, (int) DB::table('overtime_entries')->where('id', $entryId)->value('minutes'));
        $this->assertSame(1, DB::table('overtime_events')->where('overtime_entry_id', $entryId)->where('action', 'approved')->count());
        $this->assertSame($items, DB::table('payroll_items')->count());

        $this->actingAs($outside)->post('/overtime', [
            'work_date' => '2026-11-06',
            'hours' => '1',
            'reason' => 'Stayed for the handoff window.',
        ])->assertRedirect();
        $outsideId = (int) DB::table('overtime_entries')->where('employee_id', $company->outsideEmployeeId)->value('id');
        $this->actingAs($company->lead)->post('/overtime/'.$outsideId.'/decide', ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($owner)->post('/overtime/'.$outsideId.'/decide', ['decision' => 'rejected'])->assertRedirect();
        $this->assertSame('rejected', DB::table('overtime_entries')->where('id', $outsideId)->value('status'));
        $this->assertSame('pending', DB::table('overtime_entries')->where('id', $outsideId)->value('payroll_status'));
        $notice = (string) DB::table('notifications')->where('data->key', 'overtime-'.$entryId.'-approved')->value('data');
        $this->assertStringContainsString('Pay was not calculated', $notice);
        $this->assertStringNotContainsString('88.00', $notice);
    }

    public function test_missing_leave_balance_is_not_created(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $accounts = $this->roleUser($company, 'Accounts');
        DB::table('tenants')->where('id', $company->id)->update(['week_off' => json_encode(['saturday', 'sunday'])]);
        $typeId = DB::table('leave_types')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Casual Leave',
            'code' => 'CL-audit',
            'days_per_year' => 8,
            'is_paid' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('leave_policies')->insert([
            'tenant_id' => $company->id,
            'leave_type_id' => $typeId,
            'name' => 'Casual Leave yearly',
            'accrual' => 'yearly',
            'applicable_after_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $balances = DB::table('leave_balances')->count();
        $requests = DB::table('leave_requests')->count();

        $this->from('/leave')->actingAs($company->employee)->post('/leave', [
            'leave_type_id' => $typeId,
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-20',
            'reason' => 'Family event',
            'employee_id' => $company->outsideEmployeeId,
        ])->assertSessionHas('error', 'No leave balance is recorded for this year.');

        $this->assertSame($balances, DB::table('leave_balances')->count());
        $this->assertSame($requests, DB::table('leave_requests')->count());
        $this->actingAs($accounts)->post('/leave/'.$company->leaveId.'/reject', ['note' => 'Not this team'])->assertNotFound();
    }

    public function test_payroll_notices_omit_amounts_and_processing_stays_blocked(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->roleUser($company, 'Owner');
        $accounts = $this->roleUser($company, 'Accounts');

        $this->from('/payroll/runs/'.$company->payrollRunId)->actingAs($accounts)->post('/payroll/runs/'.$company->payrollRunId.'/process', [
            'note' => 'Process now',
        ])->assertSessionHasErrors('confirm');
        $this->actingAs($accounts)->post('/payroll/runs/'.$company->payrollRunId.'/process', [
            'note' => 'Process now',
            'confirm' => 'PROCESS',
        ])->assertSessionHas('error');
        $this->assertSame('draft', DB::table('payroll_runs')->where('id', $company->payrollRunId)->value('status'));
        $this->assertSame(0, DB::table('payroll_payments')->count());

        foreach (DB::table('employees')->where('tenant_id', $company->id)->pluck('id') as $employeeId) {
            DB::table('salary_structures')->insert([
                'tenant_id' => $company->id,
                'employee_id' => $employeeId,
                'basic' => '88.00',
                'hra' => '0.00',
                'special_allowance' => '0.00',
                'effective_from' => '2026-01-01',
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->actingAs($accounts)->post('/payroll/runs/'.$runId.'/calculate')->assertRedirect();
        $this->actingAs($accounts)->post('/payroll/runs/'.$runId.'/review', ['note' => 'Ready for approval'])->assertRedirect();
        $this->from('/payroll/runs/'.$runId)->actingAs($accounts)->post('/payroll/runs/'.$runId.'/review', ['note' => 'Ready for approval'])->assertSessionHas('error');

        $notice = DB::table('notifications')->where('notifiable_id', $owner->id)->where('data->key', 'payroll-'.$runId.'-awaiting-approval')->value('data');
        $this->assertIsString($notice);
        $this->assertSame(1, DB::table('notifications')->where('data->key', 'payroll-'.$runId.'-awaiting-approval')->count());
        $this->assertStringNotContainsString('88.00', (string) $notice);
        $this->assertStringContainsString('Amounts are not included', (string) $notice);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $company->employee->id)->where('data->key', 'payroll-'.$runId.'-awaiting-approval')->count());
        $this->actingAs($company->employee)->get('/inbox')->assertDontSee('88.00');

        $paymentId = DB::table('payroll_payments')->insertGetId([
            'tenant_id' => $company->id,
            'payroll_item_id' => $company->payrollItemId,
            'amount' => '100.00',
            'status' => 'pending',
            'recorded_by' => $accounts->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($company->hr)->post('/payments/'.$paymentId, [
            'paid_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'reference' => 'REF-AUDIT',
        ])->assertNotFound();
        $this->actingAs($accounts)->post('/payments/'.$paymentId, [
            'paid_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'reference' => 'REF-AUDIT',
        ])->assertRedirect()->assertSessionHas('status', 'Payment recorded. This is a record of confirmation, not a bank transfer.');

        Carbon::setTestNow();
    }

    public function test_web_requests_keep_csrf_protection(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/login', 'POST'));

        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(ValidateCsrfToken::class, app(Kernel::class)->getMiddlewareGroups()['web']);
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

    private function employeeUser(int $employeeId): User
    {
        return User::query()->findOrFail(DB::table('employees')->where('id', $employeeId)->value('user_id'));
    }
}
