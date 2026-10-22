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
