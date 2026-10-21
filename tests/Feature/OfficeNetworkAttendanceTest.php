<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfficeNetworkAttendanceTest extends TestCase
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

    public function test_an_approved_public_ip_can_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));

        $this->from('/my-attendance')->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['ip' => '198.51.100.20', 'verified' => true, 'tenant_id' => 999])
            ->assertRedirect('/my-attendance')
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('203.0.113.10', $log->check_in_ip);
        $this->assertSame('public_ip', $log->verified_as);
        $this->assertSame($company->officeId, (int) $log->location_id);
        $this->assertSame($company->id, (int) $log->tenant_id);
        $this->assertSame('pending', $log->payroll_status);
    }

    public function test_unapproved_missing_and_invalid_addresses_are_rejected(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->post('/attendance/clock-in')->assertSessionHasErrors('attendance');
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => 'not-an-ip'])->post('/attendance/clock-in')->assertSessionHasErrors('attendance');

        $this->assertSame($before, DB::table('attendance_logs')->count());
    }

    public function test_ipv6_and_cidr_ranges_match(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '2001:db8::/32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->assertSame('203.0.113.50', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));

        DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->delete();
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '2001:db8::10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->assertSame('2001:db8::10', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));
    }

    public function test_spoofed_forwarded_headers_are_rejected_and_trusted_proxies_are_honored(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->employee)->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])->post('/attendance/clock-in')->assertSessionHasErrors([
            'attendance' => 'Your office network could not be verified. Contact HR.',
        ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        config(['attendance.trusted_proxies' => ['198.51.100.8']]);
        TrustProxies::at(['198.51.100.8']);

        $this->actingAs($company->employee)->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])->post('/attendance/clock-in')->assertSessionHasNoErrors();

        $this->assertSame('203.0.113.10', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));
    }

    public function test_cross_company_inactive_and_duplicate_clock_in_are_rejected(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = $this->prepare(CompanyFixture::make('Deep Data Inc', 'deep-data'));
        $closed = DB::table('attendance_logs')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'work_date' => '2026-10-16',
            'check_in' => '2026-10-16 09:00:00',
            'check_out' => '2026-10-16 18:00:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['office_id' => $other->officeId])
            ->assertSessionHasErrors(['attendance' => 'You are not authorized to attend that office.']);

        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'inactive']);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'Your assigned office is inactive. Contact your administrator.']);
        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'active']);

        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['status' => 'inactive']);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'This account cannot record attendance.']);
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['status' => 'active']);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'You already have an active attendance session.']);
        $this->assertSame(1, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->count());

        $preserved = DB::table('attendance_logs')->where('id', $closed)->first();
        $this->assertSame('2026-10-16 09:00:00', $preserved->check_in);
        $this->assertSame('2026-10-16 18:00:00', $preserved->check_out);
    }

    public function test_break_and_clock_out_follow_valid_transitions_without_overwriting_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $checkIn = DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in');

        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->actingAs($company->employee)->post('/attendance/break-start')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->post('/attendance/break-start')->assertSessionHasErrors(['attendance' => 'You are already on a break.']);
        $this->actingAs($company->employee)->post('/attendance/clock-out')->assertSessionHasErrors(['attendance' => 'End your break before clocking out.']);

        Carbon::setTestNow('2026-10-19 10:10:00');
        $this->actingAs($company->employee)->post('/attendance/break-end')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->post('/attendance/break-end')->assertSessionHasErrors(['attendance' => 'You do not have an active break.']);

        Carbon::setTestNow('2026-10-19 11:00:00');
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->post('/attendance/clock-out')->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame($checkIn, $log->check_in);
        $this->assertSame('2026-10-19 11:00:00', $log->check_out);
        $this->assertSame(110, (int) $log->minutes);
        $this->assertSame(1, DB::table('attendance_breaks')->where('attendance_log_id', $log->id)->count());

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'You already have an attendance record for today.']);
        $this->assertSame($checkIn, DB::table('attendance_logs')->where('id', $log->id)->value('check_in'));
    }

    public function test_an_interrupted_session_is_closed_explicitly_and_keeps_the_original_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $open = DB::table('attendance_logs')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'location_id' => $company->officeId,
            'work_date' => '2026-10-18',
            'check_in' => '2026-10-18 09:05:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'An earlier attendance session is still open. Contact HR.']);

        $this->actingAs($company->employee)->post('/attendance/'.$open.'/recover', ['reason' => 'Closed the interrupted session'])->assertForbidden();
        $this->actingAs($company->hr)->post('/attendance/'.$open.'/recover', ['reason' => 'Closed the interrupted session'])->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('id', $open)->first();
        $this->assertSame('2026-10-18 09:05:00', $log->check_in);
        $this->assertNotNull($log->check_out);
        $this->assertSame('recovered', $log->status);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
    }

    public function test_attendance_visibility_stays_inside_the_company_and_team(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = $this->prepare(CompanyFixture::make('Deep Data Inc', 'deep-data'));
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();

        $this->actingAs($company->employee)->get('/attendance')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->get('/my-attendance')->assertOk()->assertDontSee($company->outsideEmail)->assertDontSee($other->reportEmail);
        $this->actingAs($company->lead)->get('/attendance')->assertOk()->assertSee('veltrix-REP')->assertDontSee('veltrix-OUT')->assertDontSee('deep-data-REP');
        $this->actingAs($company->hr)->get('/attendance/export')->assertOk()->assertDontSee('deep-data-REP');
        $this->actingAs($company->employee)->get('/attendance/export')->assertForbidden();
        $this->actingAs($platform)->get('/attendance')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($platform)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasErrors('attendance');
    }

    public function test_office_networks_are_limited_to_the_signed_in_company(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = $this->prepare(CompanyFixture::make('Deep Data Inc', 'deep-data'));

        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '192.168.1.10'])->assertSessionHasErrors('cidr');
        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '198.51.100.0/24'])->assertSessionHasNoErrors();
        $this->actingAs($company->hr)->put('/offices/'.$other->officeId, [
            'name' => 'Taken',
            'address' => 'Elsewhere',
            'timezone' => 'UTC',
            'status' => 'inactive',
        ])->assertNotFound();
        $this->assertSame('active', DB::table('locations')->where('id', $other->officeId)->value('status'));
        $this->actingAs($other->hr)->get('/offices')->assertDontSee('198.51.100.0/24');
    }

    public function test_opening_attendance_does_not_create_records(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->hr)->get('/attendance')->assertOk();
        $this->actingAs($company->employee)->get('/my-attendance')->assertOk();

        $this->assertSame($before, DB::table('attendance_logs')->count());
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
            'start_time' => '09:30:00',
            'end_time' => '18:30:00',
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
