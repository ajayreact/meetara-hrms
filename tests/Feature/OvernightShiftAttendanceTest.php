<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OvernightShiftAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_schedule_validation_keeps_companies_separate_and_accepts_an_overnight_shift(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $owner = $this->member($company, 'Owner');
        $otherOwner = $this->member($other, 'Owner');
        DB::table('shifts')->insert([
            'tenant_id' => $other->id,
            'name' => 'General',
            'type' => 'fixed',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'expected_minutes' => 480,
            'grace_minutes' => 5,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($otherOwner)->put('/offices/schedule', $this->schedule('18:30', '03:30'))->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('shifts')->where('tenant_id', $company->id)->count());
        $this->assertSame('18:30:00', DB::table('shifts')->where('tenant_id', $other->id)->value('start_time'));

        $this->actingAs($owner)->put('/offices/schedule', $this->schedule('09:30', '18:30'))->assertSessionHasNoErrors();
        $saved = DB::table('shifts')->where('tenant_id', $company->id)->where('is_default', true)->first();
        $this->assertSame('09:30:00', $saved->start_time);
        $this->assertSame('18:30:00', $saved->end_time);
        $this->assertSame(540, (int) $saved->expected_minutes);

        $this->actingAs($owner)->put('/offices/schedule', $this->schedule('18:30', '18:30'))->assertSessionHasErrors('work_end');
        $this->assertSame('09:30:00', DB::table('shifts')->where('tenant_id', $company->id)->value('start_time'));

        $this->actingAs($company->employee)->put('/offices/schedule', $this->schedule('18:30', '03:30'))->assertForbidden();
        $this->actingAs($owner)->put('/offices/schedule', $this->schedule('18:30', '03:30', 10))->assertSessionHasNoErrors();

        $overnight = DB::table('shifts')->where('tenant_id', $company->id)->where('is_default', true)->first();
        $this->assertSame('18:30:00', $overnight->start_time);
        $this->assertSame('03:30:00', $overnight->end_time);
        $this->assertSame(540, (int) $overnight->expected_minutes);
        $this->assertSame(10, (int) $overnight->grace_minutes);
        $this->assertEqualsCanonicalizing(
            ['saturday', 'sunday'],
            json_decode((string) DB::table('tenants')->where('id', $company->id)->value('week_off'), true),
        );
        $this->assertSame('18:30:00', DB::table('shifts')->where('tenant_id', $other->id)->value('start_time'));
        $this->assertSame('03:30:00', DB::table('shifts')->where('tenant_id', $other->id)->value('end_time'));
    }

    public function test_a_late_night_clock_in_and_next_morning_clock_out_stay_on_the_india_shift(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->member($company, 'Owner');
        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'active', 'timezone' => 'Asia/Kolkata']);
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($owner)->put('/offices/schedule', $this->schedule('18:30', '03:30', 10))->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-10-16 18:45:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, DB::table('attendance_logs')->whereDate('work_date', '2026-10-16')->count());

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-10-17 02:00:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn ($page) => $page
            ->where('timing.state', 'in')
            ->where('timing.canClockIn', false)
            ->where('timing.canClockOut', true)
            ->where('timing.clockIn', '6:45 PM'));

        Carbon::setTestNow(Carbon::parse('2026-10-17 03:20:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-out')
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-16')->first();
        $this->assertNotNull($log);
        $this->assertSame('2026-10-16', substr((string) $log->work_date, 0, 10));
        $this->assertSame(5, (int) $log->late_minutes);
        $this->assertSame(515, (int) $log->minutes);
        $this->assertSame(0, DB::table('attendance_logs')->whereDate('work_date', '2026-10-17')->count());
        $this->assertSame('public_ip', $log->verified_as);

        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $friday = WorkSchedule::forEmployee($employee, Carbon::parse('2026-10-16', 'Asia/Kolkata'));
        $saturday = WorkSchedule::forEmployee($employee, Carbon::parse('2026-10-17', 'Asia/Kolkata'));
        $this->assertTrue($friday->expected);
        $this->assertFalse($saturday->expected);
        $this->assertSame(10, $friday->earlyMinutes(
            Carbon::parse('2026-10-17 03:20:00', 'Asia/Kolkata'),
            Carbon::parse((string) $log->check_in, 'Asia/Kolkata'),
        ));
        $this->assertSame(270, $friday->earlyMinutes(
            Carbon::parse('2026-10-16 23:00:00', 'Asia/Kolkata'),
            Carbon::parse((string) $log->check_in, 'Asia/Kolkata'),
        ));
    }

    public function test_a_morning_clock_out_does_not_block_the_next_evening_shift(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->member($company, 'Owner');
        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'active', 'timezone' => 'Asia/Kolkata']);
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($owner)->put('/offices/schedule', $this->schedule('18:30', '04:00', 0))->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-11-05 04:54:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'Clock-in for this shift is open from 5:30 PM to 4:00 AM the next morning.']);
        $this->assertSame(0, DB::table('attendance_logs')->whereDate('work_date', '2026-11-05')->count());

        Carbon::setTestNow(Carbon::parse('2026-11-05 18:30:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, DB::table('attendance_logs')->whereDate('work_date', '2026-11-05')->count());
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-11-06 04:00:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-out')
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-11-05')->first();
        $this->assertSame('2026-11-05', substr((string) $log->work_date, 0, 10));
        $this->assertSame('2026-11-05 18:30:00', $log->check_in);
        $this->assertSame('2026-11-06 04:00:00', $log->check_out);
        $this->assertSame(0, (int) $log->late_minutes);

        Carbon::setTestNow(Carbon::parse('2026-11-06 11:00:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn ($page) => $page
            ->where('timing.canClockIn', false)
            ->where('timing.shiftNotice', 'Clock-in for this shift is open from 5:30 PM to 4:00 AM the next morning.')
            ->where('history.days', function ($days): bool {
                $thursday = collect($days)->firstWhere('date', '2026-11-05');

                return is_array($thursday)
                    && $thursday['clockIn'] === '6:30 PM'
                    && $thursday['clockOut'] === '4:00 AM, Fri 6 Nov';
            }));

        Carbon::setTestNow(Carbon::parse('2026-11-06 18:30:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-11-06', substr((string) DB::table('attendance_logs')->whereDate('work_date', '2026-11-06')->value('work_date'), 0, 10));
        $this->assertSame('2026-11-05 18:30:00', DB::table('attendance_logs')->whereDate('work_date', '2026-11-05')->value('check_in'));
    }

    public function test_an_existing_closed_shift_record_is_not_rewritten(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->member($company, 'Owner');
        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'active', 'timezone' => 'Asia/Kolkata']);
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($owner)->put('/offices/schedule', $this->schedule('18:30', '04:00', 0))->assertSessionHasNoErrors();
        DB::table('attendance_logs')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'location_id' => $company->officeId,
            'work_date' => '2026-11-05',
            'check_in' => '2026-11-05 04:54:00',
            'check_out' => '2026-11-05 05:02:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-05 23:48:00', 'Asia/Kolkata'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'You already have an attendance record for this shift.']);
        $kept = DB::table('attendance_logs')->whereDate('work_date', '2026-11-05')->first();
        $this->assertSame('2026-11-05 04:54:00', $kept->check_in);
        $this->assertSame('2026-11-05 05:02:00', $kept->check_out);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(string $start, string $end, int $grace = 10): array
    {
        return [
            'work_start' => $start,
            'work_end' => $end,
            'grace_minutes' => $grace,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
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
        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id');
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user;
    }
}
