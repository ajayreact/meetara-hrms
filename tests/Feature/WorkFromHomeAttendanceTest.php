<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class WorkFromHomeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_office_attendance_still_requires_the_approved_network(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('office', $log->work_mode);
        $this->assertSame('public_ip', $log->verified_as);
        $this->assertSame('203.0.113.10', $log->check_in_ip);
        $this->assertSame($company->reportEmployeeId, (int) $log->employee_id);

        DB::table('attendance_logs')->where('id', $log->id)->delete();
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->count());

        DB::table('employees')->where('id', $company->outsideEmployeeId)->update(['location_id' => null]);
        $outsideUserId = DB::table('employees')->where('id', $company->outsideEmployeeId)->value('user_id');
        $this->actingAs(User::query()->findOrFail($outsideUserId))
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
    }

    public function test_authorized_work_from_home_records_a_home_session_without_office_network(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('locations')->where('id', $company->officeId)->update([
            'gps_verification_enabled' => true,
            'latitude' => 17.458894,
            'longitude' => 78.363567,
            'verification_radius_meters' => 100,
        ]);
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['work_mode' => 'remote']);
        $beforeLocation = DB::table('employees')->where('id', $company->reportEmployeeId)->value('location_id');

        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn (Assert $page) => $page
            ->where('timing.workMode', 'work_from_home')
            ->where('timing.assignedModeLabel', 'Remote')
            ->where('timing.gpsRequired', false)
            ->where('timing.canClockIn', true)
        );

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in', ['verified' => true, 'latitude' => 1, 'longitude' => 1])
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('work_from_home', $log->work_mode);
        $this->assertSame('wfh_authorized', $log->verified_as);
        $this->assertSame('192.168.1.40', $log->check_in_ip);
        $this->assertSame($company->id, (int) $log->tenant_id);
        $this->assertSame($company->officeId, (int) $log->location_id);
        $this->assertNull($log->check_in_latitude);
        $this->assertSame($beforeLocation, DB::table('employees')->where('id', $company->reportEmployeeId)->value('location_id'));

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->assertSame(1, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->count());

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.41'])
            ->post('/attendance/clock-out')
            ->assertSessionHasNoErrors();
        $closed = DB::table('attendance_logs')->where('id', $log->id)->first();
        $this->assertNotNull($closed->check_out);
        $this->assertSame('192.168.1.41', $closed->check_out_ip);
        $this->assertSame('work_from_home', $closed->work_mode);

        $this->actingAs($company->employee)->post('/attendance/clock-out')->assertSessionHasErrors('attendance');

        $this->actingAs($company->lead)->get('/attendance')->assertOk()->assertSee('Work From Home');
        $this->actingAs($company->hr)->get('/attendance')->assertOk()->assertSee('Work From Home');
    }

    public function test_work_from_home_still_rejects_an_untrusted_forwarded_address(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['work_mode' => 'remote']);

        $this->actingAs($company->employee)
            ->withServerVariables([
                'REMOTE_ADDR' => '192.168.1.40',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            ])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors([
                'attendance' => 'Clock-in was blocked because a forwarded address arrived from an untrusted connection.',
            ]);
        $this->assertSame(0, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->count());
    }

    public function test_hybrid_home_attendance_requires_approval_and_office_days_keep_the_network_check(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['work_mode' => 'hybrid']);

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');

        $this->actingAs($company->employee)
            ->post('/my-attendance/wfh-requests', ['work_date' => '2026-10-19', 'reason' => 'Home installation day'])
            ->assertSessionHasNoErrors();
        $requestId = (int) DB::table('wfh_requests')->where('employee_id', $company->reportEmployeeId)->value('id');
        $this->assertSame('pending', DB::table('wfh_requests')->where('id', $requestId)->value('status'));

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->actingAs($company->employee)
            ->post('/attendance/wfh-requests/'.$requestId.'/review', ['decision' => 'approved'])
            ->assertForbidden();

        $this->actingAs($company->hr)
            ->post('/attendance/wfh-requests/'.$requestId.'/review', ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $this->assertSame($company->hr->id, (int) DB::table('wfh_requests')->where('id', $requestId)->value('reviewed_by'));

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $home = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('work_from_home', $home->work_mode);
        $this->assertSame('wfh_authorized', $home->verified_as);
        DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->delete();

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $office = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-21')->first();
        $this->assertSame('office', $office->work_mode);
        $this->assertSame('public_ip', $office->verified_as);

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-out')
            ->assertSessionHasErrors('attendance');
    }

    public function test_an_office_employee_cannot_authorize_work_from_home(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));

        $this->actingAs($company->employee)
            ->put('/employees/'.$company->reportEmployeeId, ['work_mode' => 'remote'])
            ->assertForbidden();
        $this->assertSame('office', DB::table('employees')->where('id', $company->reportEmployeeId)->value('work_mode'));

        $this->actingAs($company->employee)
            ->post('/my-attendance/wfh-requests', ['work_date' => '2026-10-19', 'reason' => 'Trying to skip the office network'])
            ->assertSessionHasErrors('attendance');

        DB::table('wfh_requests')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'work_date' => '2026-10-19',
            'reason' => 'Not authorized',
            'status' => 'approved',
            'reviewed_by' => $company->hr->id,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->count());

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in', ['work_mode' => 'wfh'])
            ->assertSessionHasErrors('attendance');
    }

    public function test_rejected_and_pending_requests_cannot_authorize_home_attendance(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['work_mode' => 'hybrid']);

        $this->actingAs($company->employee)
            ->post('/my-attendance/wfh-requests', ['work_date' => '2026-10-19', 'reason' => 'Home installation day'])
            ->assertSessionHasNoErrors();
        $this->actingAs($company->employee)
            ->post('/my-attendance/wfh-requests', ['work_date' => '2026-10-19', 'reason' => 'Home installation day'])
            ->assertSessionHasErrors('attendance');
        $this->actingAs($company->employee)
            ->post('/my-attendance/wfh-requests', ['work_date' => '2026-10-18', 'reason' => 'Yesterday at home'])
            ->assertSessionHasErrors('attendance');

        $requestId = (int) DB::table('wfh_requests')->where('employee_id', $company->reportEmployeeId)->value('id');
        $this->actingAs($company->lead)
            ->post('/attendance/wfh-requests/'.$requestId.'/review', ['decision' => 'approved'])
            ->assertForbidden();
        $this->actingAs($company->hr)
            ->post('/attendance/wfh-requests/'.$requestId.'/review', ['decision' => 'rejected'])
            ->assertSessionHasNoErrors();
        $rejected = DB::table('wfh_requests')->where('id', $requestId)->first();
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame($company->hr->id, (int) $rejected->reviewed_by);
        $this->assertNotNull($rejected->reviewed_at);

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->actingAs($company->employee)->get('/my-attendance')->assertOk()->assertSee('rejected');
    }

    public function test_home_attendance_keeps_holiday_weekly_off_shift_and_timezone_rules(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['work_mode' => 'remote']);
        DB::table('holidays')->insert([
            'tenant_id' => $company->id,
            'name' => 'Foundation Day',
            'date' => '2026-10-19',
            'calendar' => 'india',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'This date is the Foundation Day holiday. Clock-in is closed unless HR authorizes holiday work.']);

        DB::table('holidays')->where('tenant_id', $company->id)->where('date', '2026-10-19')->delete();
        Carbon::setTestNow('2026-10-18 09:00:00');
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'This date is a weekly off. Clock-in is closed unless HR authorizes weekly-off work.']);

        Carbon::setTestNow('2026-10-19 09:25:00');
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('work_from_home', $log->work_mode);
        $this->assertSame(15, (int) $log->late_minutes);
        $this->assertSame('198.51.100.8', $log->check_in_ip);

        DB::table('attendance_logs')->where('id', $log->id)->delete();
        DB::table('tenants')->where('id', $company->id)->update(['timezone' => 'Asia/Kolkata']);
        DB::table('locations')->where('id', $company->officeId)->update(['timezone' => 'Asia/Kolkata']);
        Carbon::setTestNow('2026-10-21 20:30:00');
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $next = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->orderByDesc('id')->first();
        $this->assertSame('2026-10-22', substr((string) $next->work_date, 0, 10));
        $this->assertSame('work_from_home', $next->work_mode);
        $this->assertStringContainsString('2026-10-22 02:00:00', (string) $next->check_in);
    }

    public function test_payroll_counts_each_wfh_day_once_and_leaves_salary_unchanged(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $accounts = User::factory()->create();
        $accounts->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $accounts->id,
            'tenant_id' => $company->id,
        ]);
        DB::table('salary_structures')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'basic' => '10.10',
            'hra' => '0.20',
            'special_allowance' => '0.00',
            'effective_from' => '2026-01-01',
            'currency' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['2026-11-03' => 'work_from_home', '2026-11-04' => 'wfh', '2026-11-05' => 'office', '2026-11-06' => 'remote'] as $date => $mode) {
            DB::table('attendance_logs')->insert([
                'tenant_id' => $company->id,
                'employee_id' => $company->reportEmployeeId,
                'work_date' => $date,
                'work_mode' => $mode,
                'status' => 'present',
                'check_in' => $date.' 09:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            DB::table('attendance_logs')->insert([
                'tenant_id' => $company->id,
                'employee_id' => $company->reportEmployeeId,
                'work_date' => '2026-11-03',
                'work_mode' => 'work_from_home',
                'status' => 'present',
                'check_in' => '2026-11-03 10:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A second attendance row for the same day was inserted.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-11-03')->count());
        }

        $this->actingAs($accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->actingAs($accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();
        $item = DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $company->reportEmployeeId)->first();
        $this->assertEquals(3.0, (float) $item->wfh_days);
        $this->assertEquals(4.0, (float) $item->present_days);
        $this->assertEquals(10.10, (float) $item->basic);
        $this->assertEquals(0.20, (float) $item->hra);
        $this->assertEquals(0.0, (float) $item->special_allowance);
        $this->assertEquals(0.0, (float) $item->lop_amount);
        $this->assertEquals(0.0, (float) $item->overtime_amount);
        $this->assertEquals(0.0, (float) $item->deductions);
    }

    public function test_attendance_visibility_and_cross_company_reviews_stay_restricted(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['work_mode' => 'hybrid']);
        DB::table('employees')->where('id', $company->outsideEmployeeId)->update(['work_mode' => 'hybrid']);
        $outsideUserId = (int) DB::table('employees')->where('id', $company->outsideEmployeeId)->value('user_id');
        $outside = User::query()->findOrFail($outsideUserId);

        $this->actingAs($company->employee)
            ->post('/my-attendance/wfh-requests', ['work_date' => '2026-10-21', 'reason' => 'Team home day'])
            ->assertSessionHasNoErrors();
        $this->actingAs($outside)
            ->post('/my-attendance/wfh-requests', ['work_date' => '2026-10-21', 'reason' => 'Other team home day'])
            ->assertSessionHasNoErrors();
        $requestId = (int) DB::table('wfh_requests')->where('employee_id', $company->reportEmployeeId)->value('id');

        $this->actingAs($company->employee)->get('/attendance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('hrms/forbidden')
        );
        $this->actingAs($company->employee)->get('/my-attendance')->assertOk()->assertSee('Team home day')->assertDontSee('Other team home day');
        $this->actingAs($company->lead)->get('/attendance')->assertOk()->assertSee('Team home day')->assertDontSee('Other team home day')->assertSee('veltrix-REP')->assertDontSee('veltrix-OUT');
        $this->actingAs($company->hr)->get('/attendance')->assertOk()->assertSee('Team home day')->assertSee('Other team home day');
        $this->actingAs($company->lead)
            ->post('/attendance/wfh-requests/'.$requestId.'/review', ['decision' => 'approved'])
            ->assertForbidden();
        $this->actingAs($other->hr)
            ->post('/attendance/wfh-requests/'.$requestId.'/review', ['decision' => 'approved'])
            ->assertSessionHasErrors('attendance');
        $this->assertSame('pending', DB::table('wfh_requests')->where('id', $requestId)->value('status'));
        $this->assertNull(DB::table('wfh_requests')->where('id', $requestId)->value('reviewed_by'));
    }

    public function test_a_legacy_wfh_record_stays_readable_and_is_not_rewritten(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('attendance_logs')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'location_id' => $company->officeId,
            'work_date' => '2026-10-19',
            'check_in' => '2026-10-19 09:05:00',
            'work_mode' => 'wfh',
            'verified_as' => 'wfh_authorized',
            'check_in_ip' => '198.51.100.20',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->get('/my-attendance')->assertOk()->assertSee('Work From Home')->assertSee('Authorized work from home');
        $this->actingAs($company->lead)->get('/attendance')->assertOk()->assertSee('Work From Home');
        $this->actingAs($company->hr)->get('/attendance')->assertOk()->assertSee('Work From Home');
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
            ->post('/attendance/clock-out')
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('wfh', $log->work_mode);
        $this->assertSame('wfh_authorized', $log->verified_as);
        $this->assertSame('198.51.100.20', $log->check_in_ip);
        $this->assertSame('198.51.100.21', $log->check_out_ip);
        $this->assertNotNull($log->check_out);
    }

    private function prepare(CompanyFixture $company): CompanyFixture
    {
        DB::table('locations')->where('id', $company->officeId)->update([
            'timezone' => 'UTC',
            'status' => 'active',
        ]);
        DB::table('tenants')->where('id', $company->id)->update([
            'timezone' => 'UTC',
            'week_off' => json_encode(['saturday', 'sunday']),
        ]);
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'General',
            'type' => 'fixed',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'expected_minutes' => 540,
            'grace_minutes' => 10,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('tenant_id', $company->id)->update(['shift_id' => $shiftId]);

        return $company;
    }
}
