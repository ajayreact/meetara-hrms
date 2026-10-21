<?php

namespace Tests\Feature;

use App\Models\PayrollEvent;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Hrms\PayrollRejected;
use App\Services\Hrms\PayrollService;
use App\Services\Hrms\PayrollStatutoryReadiness;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guests_and_unauthorized_roles_cannot_open_payroll(): void
    {
        foreach (['/payroll', '/salary', '/payments', '/payslips', '/payroll/runs'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }

        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');

        $this->actingAs($company->lead)->get('/payroll')->assertForbidden();
        $this->actingAs($company->lead)->get('/payslips')->assertForbidden();
        $this->actingAs($company->employee)->get('/salary')->assertForbidden();
        $this->actingAs($company->employee)->get('/payroll')->assertForbidden();
        $this->actingAs($company->hr)->get('/payments')->assertForbidden();
        $this->actingAs($company->hr)->get('/payroll')->assertForbidden();
        $this->actingAs($company->hr)->post('/payments/1', ['paid_on' => '2026-11-30', 'method' => 'cash', 'reference' => 'X'])->assertNotFound();
    }

    public function test_salary_history_is_appended_and_invalid_input_is_rejected(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $accounts = $this->accounts($company);

        $this->from('/salary')->actingAs($accounts)->post('/salary', [
            'employee_id' => $company->reportEmployeeId,
            'tenant_id' => 999,
            'basic' => '10.10',
            'hra' => '0.20',
            'special_allowance' => '0.00',
            'effective_from' => '2026-01-01',
            'currency' => 'inr',
        ])->assertRedirect('/salary');

        $this->from('/salary')->actingAs($accounts)->post('/salary', [
            'employee_id' => $company->reportEmployeeId,
            'basic' => '12.00',
            'hra' => '0.20',
            'special_allowance' => '0.00',
            'effective_from' => '2026-12-01',
            'currency' => 'INR',
        ])->assertRedirect('/salary');

        $rows = DB::table('salary_structures')->where('employee_id', $company->reportEmployeeId)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('10.10', \App\Support\Money::format(\App\Support\Money::cents((string) $rows[0]->basic)));
        $this->assertSame('INR', $rows[0]->currency);
        $this->assertSame($company->id, (int) $rows[0]->tenant_id);
        $this->assertSame(1, DB::table('salary_structure_events')->where('salary_structure_id', $rows[0]->id)->count());

        $this->from('/salary')->actingAs($accounts)->post('/salary', [
            'employee_id' => $company->outsideEmployeeId,
            'basic' => 'bad',
            'hra' => '0.00',
            'special_allowance' => '0.00',
            'effective_from' => '2026-01-01',
            'currency' => 'INR',
        ])->assertSessionHasErrors('basic');
        $this->assertSame(0, DB::table('salary_structures')->where('employee_id', $company->outsideEmployeeId)->count());
    }

    public function test_payroll_uses_saved_salary_and_does_not_change_attendance_or_leave(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $accounts = $this->accounts($company);
        $admin = $this->owner($company);
        $this->seedSalaries($company, '10.10', '0.20', '0.00');

        DB::table('leave_requests')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-10',
            'days' => 1,
            'status' => 'pending',
            'payroll_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance_logs')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'work_date' => '2026-11-03',
            'status' => 'present',
            'check_in' => '2026-11-03 09:30:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeAttendance = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->orderBy('id')->get();
        $beforeLeave = DB::table('leave_requests')->where('tenant_id', $company->id)->orderBy('id')->get();
        $runsBefore = DB::table('payroll_runs')->count();

        $this->actingAs($accounts)->get('/payroll')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('overview.processedNet', '0.00'));
        $this->assertSame($runsBefore, DB::table('payroll_runs')->count());

        $this->from('/payroll/runs')->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->from('/payroll/runs')->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertSessionHas('error');
        $this->actingAs($this->accounts($other))->get("/payroll/runs/{$runId}")->assertNotFound();

        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();
        $draftItem = (int) DB::table('payroll_items')->where('payroll_run_id', $runId)->value('id');
        $this->actingAs($company->employee)->get("/payslips/{$draftItem}/pdf")->assertNotFound();
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();
        $this->assertSame(3, DB::table('payroll_items')->where('payroll_run_id', $runId)->count());
        $this->assertSame('10.30', \App\Support\Money::format(\App\Support\Money::cents((string) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $company->reportEmployeeId)->value('net'))));
        $this->assertSame('0.00', \App\Support\Money::format(\App\Support\Money::cents((string) DB::table('payroll_items')->where('payroll_run_id', $runId)->value('lop_amount'))));
        $this->assertEquals($beforeAttendance->map->check_in, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->orderBy('id')->pluck('check_in'));
        $this->assertEquals($beforeLeave->pluck('status'), DB::table('leave_requests')->where('tenant_id', $company->id)->orderBy('id')->pluck('status'));
        $this->assertEquals($beforeLeave->pluck('payroll_status'), DB::table('leave_requests')->where('tenant_id', $company->id)->orderBy('id')->pluck('payroll_status'));

        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/review", ['note' => 'Ready for approval'])->assertRedirect();
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/approve")->assertForbidden();
        DB::table('payroll_runs')->where('id', $runId)->update(['calculated_by' => $admin->id]);
        $this->from("/payroll/runs/{$runId}")->actingAs($admin)->post("/payroll/runs/{$runId}/approve")->assertSessionHas('error');
        DB::table('payroll_runs')->where('id', $runId)->update(['calculated_by' => $accounts->id]);
        $this->from("/payroll/runs/{$runId}")->actingAs($admin)->post("/payroll/runs/{$runId}/approve")->assertSessionHas('error');
        $this->assertSame('under_review', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertNull(DB::table('payroll_runs')->where('id', $runId)->value('approved_by'));
        $this->assertSame(0, DB::table('payroll_payments')->count());

        $this->actingAs($admin)->post("/payroll/runs/{$runId}/process", ['note' => 'Process now', 'confirm' => 'PROCESS'])->assertForbidden();
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/process", ['note' => 'Process now', 'confirm' => 'PROCESS'])->assertSessionHas('error');
        $this->assertSame('under_review', DB::table('payroll_runs')->where('id', $runId)->value('status'));

        DB::table('payroll_runs')->where('id', $runId)->update([
            'status' => 'processed',
            'processed_by' => $accounts->id,
            'processed_at' => now(),
        ]);
        DB::table('payroll_items')->where('payroll_run_id', $runId)->update(['payroll_status' => 'processed']);
        foreach (DB::table('payroll_items')->where('payroll_run_id', $runId)->pluck('id') as $processedItemId) {
            DB::table('payroll_payments')->insert([
                'tenant_id' => $company->id,
                'payroll_item_id' => $processedItemId,
                'amount' => '10.30',
                'status' => 'pending',
                'recorded_by' => $accounts->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/process", ['note' => 'Process now', 'confirm' => 'PROCESS'])->assertRedirect();
        $this->assertSame(3, DB::table('payroll_payments')->whereIn('payroll_item_id', DB::table('payroll_items')->where('payroll_run_id', $runId)->pluck('id'))->count());
        $this->assertSame('processed', DB::table('payroll_runs')->where('id', $runId)->value('status'));

        $this->from("/payroll/runs/{$runId}")->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertSessionHas('error');
        $this->assertSame('10.30', \App\Support\Money::format(\App\Support\Money::cents((string) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $company->reportEmployeeId)->value('net'))));

        $itemId = (int) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $company->reportEmployeeId)->value('id');
        $otherItem = (int) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $company->outsideEmployeeId)->value('id');
        $outside = User::query()->findOrFail(DB::table('employees')->where('id', $company->outsideEmployeeId)->value('user_id'));

        $this->actingAs($company->employee)->get("/payslips/{$itemId}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($outside)->get("/payslips/{$itemId}")->assertNotFound();
        $this->actingAs($company->employee)->get("/payslips/{$otherItem}/pdf")->assertNotFound();
        $this->actingAs($other->hr)->get("/payslips/{$itemId}")->assertNotFound();

        $paymentId = (int) DB::table('payroll_payments')->where('payroll_item_id', $itemId)->value('id');
        $this->actingAs($accounts)->post("/payments/{$paymentId}", [
            'paid_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'reference' => 'REF-1',
        ])->assertRedirect();
        $this->from('/payments')->actingAs($accounts)->post("/payments/{$paymentId}", [
            'paid_on' => '2026-11-30',
            'method' => 'cash',
            'reference' => 'REF-2',
        ])->assertSessionHas('error');
        $this->assertSame('paid', DB::table('payroll_payments')->where('id', $paymentId)->value('status'));
        $this->assertSame('REF-1', DB::table('payroll_payments')->where('id', $paymentId)->value('reference'));
        $this->assertSame(1, DB::table('payroll_events')->where('payroll_run_id', $runId)->where('action', 'payment_recorded')->count());

        $this->actingAs($accounts)->post("/payments/{$paymentId}/reverse", ['note' => 'Recorded against the wrong period'])->assertRedirect();
        $this->assertSame(1, DB::table('payroll_events')->where('payroll_run_id', $runId)->where('action', 'payment_reversed')->count());
        $this->actingAs($company->hr)->post("/payroll/runs/{$runId}/correct", ['note' => 'Replace the calculation'])->assertNotFound();
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/correct", ['note' => 'Replace the calculation'])->assertRedirect();
        $this->assertSame('draft', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame(1, DB::table('payroll_events')->where('payroll_run_id', $runId)->where('action', 'correction_opened')->count());
    }

    public function test_missing_salary_blocks_approval_and_empty_payroll_is_honest(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $accounts = $this->accounts($company);
        DB::table('payroll_runs')->where('tenant_id', $company->id)->delete();
        DB::table('payroll_items')->where('tenant_id', $company->id)->delete();

        $this->actingAs($accounts)->get('/payroll')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('payroll/index')->where('overview.runs', 0));

        $this->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();
        $this->from("/payroll/runs/{$runId}")->actingAs($accounts)->post("/payroll/runs/{$runId}/review", ['note' => 'Trying to review'])->assertSessionHas('error');
        $this->assertSame('calculated', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame(0, DB::table('payroll_items')->where('payroll_run_id', $runId)->count());
    }

    public function test_accounts_access_is_enabled_and_assigned_only_by_an_explicit_action(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $admin = $this->owner($company);
        $employee = DB::table('employees')->where('id', $company->reportEmployeeId)->first();
        $assignmentsBefore = DB::table('model_has_roles')->count();

        $this->actingAs($company->hr)->post('/company/access-levels/accounts')->assertForbidden();
        $this->actingAs($admin)->post('/company/access-levels/accounts')->assertRedirect();
        $this->actingAs($admin)->post('/company/access-levels/accounts')->assertRedirect();

        $roleId = (int) DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Accounts')->value('id');
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', $roleId)->count());
        $this->assertSame($assignmentsBefore, DB::table('model_has_roles')->count());
        $this->assertSame(1, DB::table('role_assignment_events')->where('tenant_id', $company->id)->where('action', 'defined')->whereNull('subject_user_id')->count());

        $payload = [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'email' => $employee->email,
            'employee_code' => $employee->employee_code,
            'date_of_joining' => '2026-01-15',
            'holiday_calendar' => 'india',
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'role' => 'Accounts',
        ];

        $this->from('/employees/'.$employee->id)->actingAs($company->hr)->put('/employees/'.$employee->id, $payload)->assertSessionHasErrors('role');
        $this->actingAs($admin)->put('/employees/'.$employee->id, $payload)->assertRedirect();

        $assignedRole = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $company->employee->id)
            ->where('model_has_roles.tenant_id', $company->id)
            ->value('roles.name');
        $this->assertSame('Accounts', $assignedRole);
        $this->assertSame(1, DB::table('role_assignment_events')->where('subject_user_id', $company->employee->id)->where('action', 'assigned')->count());
    }

    public function test_unresolved_payroll_rules_are_flagged_instead_of_invented(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $accounts = $this->accounts($company);
        $admin = $this->owner($company);
        DB::table('employees')->where('id', $company->outsideEmployeeId)->update(['date_of_joining' => '2026-11-20']);
        $this->seedSalaries($company, '10.00', '2.00', '0.50');
        DB::table('salary_structures')->where('employee_id', $company->leadEmployeeId)->update(['effective_from' => '2026-12-01']);
        DB::table('leave_requests')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-04',
            'days' => 1,
            'status' => 'approved',
            'payroll_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendanceBefore = DB::table('attendance_logs')->where('id', $company->attendanceId)->first();

        $this->from('/salary')->actingAs($accounts)->post('/salary', [
            'employee_id' => $company->reportEmployeeId,
            'basic' => '1.00',
            'hra' => '0.00',
            'special_allowance' => '0.00',
            'effective_from' => 'not-a-date',
            'currency' => 'INR',
        ])->assertSessionHasErrors('effective_from');

        $this->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->actingAs($admin)->post("/payroll/runs/{$runId}/calculate")->assertForbidden();
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();

        $warnings = json_decode((string) DB::table('payroll_runs')->where('id', $runId)->value('warnings'), true);
        $messages = collect($warnings)->pluck('message')->implode(' ');
        $this->assertStringContainsString('Statutory deductions are not configured', $messages);
        $this->assertStringContainsString('Missing attendance was not treated as unpaid', $messages);
        $this->assertStringContainsString('not ready for live processing', $messages);
        $this->assertStringContainsString('Proration is not configured', $messages);
        $this->assertStringContainsString('no salary record effective', $messages);
        $this->assertSame(0, DB::table('payroll_items')->where('payroll_run_id', $runId)->whereIn('employee_id', [$company->outsideEmployeeId, $company->leadEmployeeId])->count());

        $item = DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $company->reportEmployeeId)->first();
        $this->assertSame('12.50', \App\Support\Money::format(\App\Support\Money::cents((string) $item->net)));
        $this->assertSame('0.00', \App\Support\Money::format(\App\Support\Money::cents((string) $item->lop_amount)));
        $this->assertGreaterThan(0, (float) $item->leave_days);
        $this->assertSame('approved', DB::table('leave_requests')->where('employee_id', $company->reportEmployeeId)->where('status', 'approved')->value('status'));
        $this->assertSame($attendanceBefore->check_in, DB::table('attendance_logs')->where('id', $company->attendanceId)->value('check_in'));
        $this->from("/payroll/runs/{$runId}")->actingAs($accounts)->post("/payroll/runs/{$runId}/review", ['note' => 'Cannot pass review'])->assertSessionHas('error');
    }

    public function test_a_failed_calculation_does_not_keep_partial_items(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $accounts = $this->accounts($company);
        $this->seedSalaries($company, '10.00', '0.00', '0.00');
        $calls = 0;
        $this->mock(\App\Services\Hrms\LeaveCalendar::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('workingDays')->andReturnUsing(function () use (&$calls) {
                $calls++;
                if ($calls > 1) {
                    throw new \RuntimeException('Calendar failed.');
                }

                return 20.0;
            });
        });

        $this->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertStatus(500);

        $this->assertSame('draft', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame(0, DB::table('payroll_items')->where('payroll_run_id', $runId)->count());
        $this->assertSame(0, DB::table('payroll_payments')->count());
    }

    public function test_payroll_settings_do_not_change_saved_salary_or_deductions(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $admin = $this->owner($company);
        $accounts = $this->accounts($company);
        $this->seedSalaries($company, '10.10', '0.20', '0.00');

        $this->actingAs($company->hr)->put('/company/settings/payroll', ['currency' => 'USD'])->assertForbidden();
        $this->actingAs($accounts)->put('/company/settings/payroll', ['currency' => 'USD'])->assertForbidden();
        $this->actingAs($company->lead)->put('/company/settings/payroll', ['currency' => 'USD'])->assertForbidden();
        $this->actingAs($company->employee)->get('/company/settings')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->lead)->get('/salary')->assertForbidden();
        $this->actingAs($company->employee)->get('/payslips')->assertOk()->assertInertia(fn (Assert $page) => $page->where('payslips', []));

        $this->from('/company/settings')->actingAs($admin)->put('/company/settings/payroll', [
            'currency' => 'usd',
            'statutory_rate' => '12',
        ])->assertSessionHasErrors('statutory_rate');
        $this->assertSame(0, DB::table('company_payroll_settings')->count());

        $this->actingAs($admin)->put('/company/settings/payroll', ['currency' => 'usd'])->assertRedirect();
        $this->assertSame('USD', DB::table('company_payroll_settings')->where('tenant_id', $company->id)->value('currency'));
        $this->assertSame('INR', DB::table('salary_structures')->where('tenant_id', $company->id)->value('currency'));
        $this->assertSame(1, DB::table('company_payroll_setting_events')->where('tenant_id', $company->id)->where('action', 'currency_saved')->count());

        $this->actingAs($this->owner($other))->get('/company/settings')->assertInertia(fn (Assert $page) => $page
            ->where('payrollSettings.currency', null)
            ->where('payrollSettings.statutory', 'Not configured')
            ->where('workspace.companyName', 'Deep Data Inc'));

        $this->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();
        $this->assertSame('0.00', \App\Support\Money::format(\App\Support\Money::cents((string) DB::table('payroll_items')->where('payroll_run_id', $runId)->value('pf_employee'))));

        $this->actingAs($accounts)->get('/payroll?year=2026&month=11')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('overview.period.found', true)
            ->where('overview.period.net', '30.90')
            ->where('overview.period.employees', 3)
            ->where('overview.processedNet', '0.00'));
        $this->actingAs($accounts)->get('/payroll?year=2026&month=9')->assertInertia(fn (Assert $page) => $page
            ->where('overview.period.found', false)
            ->where('overview.period.net', '0.00'));
        $this->actingAs($this->accounts($other))->get('/payroll?year=2026&month=11')->assertInertia(fn (Assert $page) => $page->where('overview.period.found', false));
    }

    private function seedSalaries(CompanyFixture $company, string $basic, string $hra, string $special): void
    {
        $ids = DB::table('employees')->where('tenant_id', $company->id)->where('status', 'active')->pluck('id');

        foreach ($ids as $id) {
            DB::table('salary_structures')->insert([
                'tenant_id' => $company->id,
                'employee_id' => $id,
                'basic' => $basic,
                'hra' => $hra,
                'special_allowance' => $special,
                'effective_from' => '2026-01-01',
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function accounts(CompanyFixture $company): User
    {
        return $this->roleUser($company, 'Accounts');
    }

    public function test_statutory_readiness_blocks_approval_and_processing(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $accounts = $this->accounts($company);
        $admin = $this->owner($company);
        $this->seedSalaries($company, '10.10', '0.20', '0.00');
        $readiness = app(PayrollStatutoryReadiness::class);

        $this->assertFalse($readiness->ready());
        $this->assertSame(
            ['provident_fund', 'employee_state_insurance', 'labour_welfare_fund', 'tax_deducted_at_source', 'professional_tax'],
            array_column($readiness->issues(), 'key')
        );
        $this->assertSame(['not_configured'], array_values(array_unique(array_column($readiness->issues(), 'state'))));

        $this->actingAs($admin)->put('/company/settings/payroll', ['currency' => 'INR'])->assertRedirect();
        $this->assertFalse($readiness->ready());
        $this->from('/company/settings')->actingAs($admin)->put('/company/settings/payroll', [
            'currency' => 'INR',
            'statutory_rate' => '0',
        ])->assertSessionHasErrors('statutory_rate');

        $this->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();
        $this->assertSame('calculated', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame('0.00', \App\Support\Money::format(\App\Support\Money::cents((string) DB::table('payroll_items')->where('payroll_run_id', $runId)->value('pf_employee'))));
        $this->actingAs($accounts)->get("/payroll/runs/{$runId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.statutory.ready', false)
                ->where('run.statutory.approvalBlocked', true)
                ->where('run.statutory.processingBlocked', true)
                ->where('run.blocked', true)
                ->where('run.deductionsConfigured', false));

        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/review", ['note' => 'Ready for approval'])->assertRedirect();
        $this->from("/payroll/runs/{$runId}")->actingAs($admin)->post("/payroll/runs/{$runId}/approve")
            ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'Provident Fund') && str_contains($error, 'not an exemption') && ! str_contains($error, '10.10'));
        $this->assertSame('under_review', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertNull(DB::table('payroll_runs')->where('id', $runId)->value('approved_by'));
        $this->assertSame(0, DB::table('payroll_payments')->count());

        $run = PayrollRun::query()->findOrFail($runId);
        try {
            app(PayrollService::class)->approve($admin, $run, null);
            $this->fail('A direct approval call should stay blocked.');
        } catch (PayrollRejected $exception) {
            $this->assertStringContainsString('Provident Fund', $exception->getMessage());
            $this->assertStringContainsString('Professional tax', $exception->getMessage());
            $this->assertStringNotContainsString('10.10', $exception->getMessage());
        }
        $this->assertSame('under_review', DB::table('payroll_runs')->where('id', $runId)->value('status'));

        DB::table('payroll_runs')->where('id', $runId)->update([
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'warnings' => json_encode([]),
        ]);
        $this->from("/payroll/runs/{$runId}")->actingAs($accounts)->post("/payroll/runs/{$runId}/process", [
            'note' => 'Process now',
            'confirm' => 'PROCESS',
        ])->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'Labour Welfare Fund') && ! str_contains($error, '10.20'));
        $this->assertSame('approved', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame(0, DB::table('payroll_payments')->count());

        try {
            app(PayrollService::class)->process($accounts, $run->fresh());
            $this->fail('A direct processing call should stay blocked.');
        } catch (PayrollRejected $exception) {
            $this->assertStringContainsString('Employee State Insurance', $exception->getMessage());
            $this->assertStringNotContainsString('10.20', $exception->getMessage());
        }
        $this->assertSame('approved', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame(0, DB::table('payroll_payments')->count());

        $this->actingAs($other->hr)->post("/payroll/runs/{$runId}/approve")->assertNotFound();
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/approve")->assertForbidden();
        $this->actingAs($admin)->post("/payroll/runs/{$runId}/process", [
            'note' => 'Process now',
            'confirm' => 'PROCESS',
        ])->assertForbidden();

        DB::table('payroll_runs')->where('id', $runId)->update([
            'status' => 'processed',
            'processed_by' => $accounts->id,
            'processed_at' => now(),
        ]);
        $itemId = (int) DB::table('payroll_items')->where('payroll_run_id', $runId)->value('id');
        DB::table('payroll_payments')->insert([
            'tenant_id' => $company->id,
            'payroll_item_id' => $itemId,
            'amount' => '1.00',
            'status' => 'pending',
            'recorded_by' => $accounts->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/process", [
            'note' => 'Process now',
            'confirm' => 'PROCESS',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('payroll_payments')->count());
        $this->assertSame('processed', DB::table('payroll_runs')->where('id', $runId)->value('status'));
    }

    public function test_a_failed_payment_audit_rolls_back_the_confirmation(): void
    {
        $company = CompanyFixture::make('Integrity Payroll', 'integrity-payroll');
        $runId = DB::table('payroll_runs')->insertGetId([
            'tenant_id' => $company->id,
            'year' => 2026,
            'month' => 3,
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('payroll_items')->insertGetId([
            'tenant_id' => $company->id,
            'payroll_run_id' => $runId,
            'employee_id' => $company->reportEmployeeId,
            'net' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payroll_payments')->insertGetId([
            'tenant_id' => $company->id,
            'payroll_item_id' => $itemId,
            'amount' => 10,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PayrollEvent::creating(fn () => throw new \RuntimeException('audit failed'));

        try {
            app(PayrollService::class)->markPaid($company->employee, PayrollPayment::query()->findOrFail($paymentId), [
                'paid_on' => '2026-03-30',
                'method' => 'cash',
                'reference' => 'SHOULD-ROLLBACK',
            ]);
            $this->fail('The audit failure should stop the confirmation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            PayrollEvent::flushEventListeners();
        }

        $this->assertSame('pending', DB::table('payroll_payments')->where('id', $paymentId)->value('status'));
        $this->assertNull(DB::table('payroll_payments')->where('id', $paymentId)->value('reference'));
        $this->assertSame(0, DB::table('payroll_events')->where('payroll_run_id', $runId)->count());
    }

    private function owner(CompanyFixture $company): User
    {
        return $this->roleUser($company, 'Owner');
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
}
