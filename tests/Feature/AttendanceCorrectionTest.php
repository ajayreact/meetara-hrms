<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_employee_can_request_a_missing_clock_in_without_changing_attendance(): void
    {
        [$company, $owner] = $this->company();
        $this->freeze('2026-11-02 04:30:00');

        $this->from('/my-attendance')->actingAs($company->employee)->post('/my-attendance/corrections', [
            'work_date' => '2026-10-28',
            'type' => 'missing_clock_in',
            'check_in' => '09:30',
            'reason' => 'I forgot to clock in after the morning meeting.',
        ])->assertRedirect('/my-attendance')->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-28')->count());
        $this->assertDatabaseHas('attendance_correction_requests', [
            'employee_id' => $company->reportEmployeeId,
            'type' => 'missing_clock_in',
            'status' => 'pending',
        ]);
        $this->assertNotification($owner->id, 'submitted');
        $this->assertNotification($company->hr->id, 'submitted');
    }

    public function test_an_employee_can_request_a_missing_clock_out(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');
        $this->log($company, '2026-10-28', '09:30:00', null);

        $this->from('/my-attendance')->actingAs($company->employee)->post('/my-attendance/corrections', [
            'work_date' => '2026-10-28',
            'type' => 'missing_clock_out',
            'check_out' => '18:30',
            'reason' => 'I forgot to clock out before leaving the office.',
        ])->assertSessionHasNoErrors();

        $this->assertNull(DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-28')->value('check_out'));
        $this->assertSame('pending', DB::table('attendance_correction_requests')->where('type', 'missing_clock_out')->value('status'));
    }

    public function test_an_employee_can_request_an_incorrect_time_and_invalid_times_are_rejected(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');
        $this->log($company, '2026-10-28', '10:00:00', '18:30:00');

        $this->from('/my-attendance')->actingAs($company->employee)->post('/my-attendance/corrections', [
            'work_date' => '2026-10-28',
            'type' => 'incorrect_clock_in',
            'check_in' => '09:30',
            'reason' => 'The recorded clock-in is one hour late.',
        ])->assertSessionHasNoErrors();

        $this->from('/my-attendance')->actingAs($company->employee)->post('/my-attendance/corrections', [
            'work_date' => '2026-10-27',
            'type' => 'incorrect_clock_out',
            'check_in' => '18:00',
            'check_out' => '09:00',
            'reason' => 'These times are in the wrong order.',
        ])->assertSessionHasErrors('check_out');

        $this->from('/my-attendance')->actingAs($company->employee)->post('/my-attendance/corrections', [
            'work_date' => '2026-11-03',
            'type' => 'other',
            'check_in' => '09:30',
            'reason' => 'A future date must not be accepted.',
        ])->assertSessionHasErrors('work_date');

        $this->assertSame('10:00:00', substr((string) DB::table('attendance_logs')->whereDate('work_date', '2026-10-28')->value('check_in'), 11, 8));
    }

    public function test_a_second_pending_request_for_the_same_date_is_rejected_until_the_first_is_cancelled(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');

        $this->actingAs($company->employee)->post('/my-attendance/corrections', $this->request('2026-10-28', 'missing_clock_in'))->assertSessionHasNoErrors();
        $this->from('/my-attendance')->actingAs($company->employee)->post('/my-attendance/corrections', $this->request('2026-10-28', 'missing_clock_out'))->assertSessionHasErrors('work_date');
        $this->assertSame(1, DB::table('attendance_correction_requests')->where('employee_id', $company->reportEmployeeId)->count());

        $id = (int) DB::table('attendance_correction_requests')->value('id');
        $this->actingAs($company->employee)->post('/my-attendance/corrections/'.$id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame('cancelled', DB::table('attendance_correction_requests')->where('id', $id)->value('status'));
        $this->actingAs($company->employee)->post('/my-attendance/corrections', $this->request('2026-10-28', 'missing_clock_out'))->assertSessionHasNoErrors();
    }

    public function test_an_employee_cannot_open_or_cancel_another_employees_request(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');
        $this->actingAs($company->employee)->post('/my-attendance/corrections', $this->request('2026-10-28', 'missing_clock_in'));
        $id = (int) DB::table('attendance_correction_requests')->value('id');
        $other = User::query()->findOrFail(DB::table('employees')->where('id', $company->outsideEmployeeId)->value('user_id'));

        $this->actingAs($other)->post('/my-attendance/corrections/'.$id.'/cancel')->assertNotFound();
        $this->actingAs($other)->get('/my-attendance')->assertInertia(fn (Assert $page) => $page
            ->where('corrections', []));
        $this->assertSame('pending', DB::table('attendance_correction_requests')->where('id', $id)->value('status'));
    }

    public function test_an_employee_cannot_change_an_approved_request(): void
    {
        [$company] = $this->company();
        $this->freeze('2026-11-02 04:30:00');
        $this->log($company, '2026-10-28', '10:00:00', '18:30:00');
        $this->actingAs($company->employee)->post('/my-attendance/corrections', [
            'work_date' => '2026-10-28',
            'type' => 'incorrect_clock_in',
            'check_in' => '09:30',
            'reason' => 'The recorded clock-in is one hour late.',
        ]);
        $id = (int) DB::table('attendance_correction_requests')->value('id');
        $this->actingAs($company->hr)->post('/attendance/corrections/'.$id.'/approve', ['review_reason' => 'Verified with the manager.'])->assertSessionHasNoErrors();

        $this->from('/my-attendance')->actingAs($company->employee)->post('/my-attendance/corrections/'.$id.'/cancel')->assertSessionHasErrors('correction');
        $this->assertSame('approved', DB::table('attendance_correction_requests')->where('id', $id)->value('status'));
        $this->assertSame('09:30:00', substr((string) DB::table('attendance_logs')->whereDate('work_date', '2026-10-28')->value('check_in'), 11, 8));
    }

    public function test_hr_and_company_admin_can_approve_only_their_own_company_and_keep_the_original_values(): void
    {
        [$company, $owner] = $this->company();
        $other = CompanyFixture::make('Northwind People', 'northwind-corrections');
        $this->freeze('2026-11-02 04:30:00');
        $this->shift($company);
        $this->log($company, '2026-10-28', '10:00:00', '18:30:00', 30);
        $this->actingAs($company->employee)->post('/my-attendance/corrections', [
            'work_date' => '2026-10-28',
            'type' => 'incorrect_clock_in',
            'check_in' => '09:30',
            'reason' => 'The recorded clock-in is one hour late.',
        ]);
        $id = (int) DB::table('attendance_correction_requests')->value('id');

        $this->actingAs($other->hr)->post('/attendance/corrections/'.$id.'/approve')->assertNotFound();
        $this->actingAs($company->lead)->post('/attendance/corrections/'.$id.'/approve')->assertForbidden();
        $this->actingAs($this->accounts($company))->post('/attendance/corrections/'.$id.'/approve')->assertForbidden();
        $this->assertSame('10:00:00', substr((string) DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-28')->value('check_in'), 11, 8));

        $this->actingAs($owner)->post('/attendance/corrections/'.$id.'/approve', ['review_reason' => 'Verified with the manager.'])->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-28')->first();
        $this->assertSame('09:30:00', substr((string) $log->check_in, 11, 8));
        $this->assertSame('18:30:00', substr((string) $log->check_out, 11, 8));
        $this->assertSame(0, (int) $log->late_minutes);
        $audit = DB::table('attendance_correction_audits')->where('request_id', $id)->first();
        $this->assertSame('10:00:00', substr((string) $audit->original_check_in, 11, 8));
        $this->assertSame('18:30:00', substr((string) $audit->original_check_out, 11, 8));
        $this->assertSame('09:30:00', substr((string) $audit->corrected_check_in, 11, 8));
        $this->assertSame((int) $company->employee->id, (int) $audit->changed_by);
        $this->assertSame((int) $owner->id, (int) $audit->approved_by);
        $this->assertSame('approved', $audit->decision);
        $this->assertNotification($company->employee->id, 'approved');
    }

    public function test_hr_can_approve_a_company_request_and_rejection_preserves_attendance(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');
        $this->log($company, '2026-10-28', '09:30:00', null);
        $this->actingAs($company->employee)->post('/my-attendance/corrections', $this->request('2026-10-28', 'missing_clock_out', '18:30'));
        $id = (int) DB::table('attendance_correction_requests')->where('type', 'missing_clock_out')->value('id');

        $this->from('/attendance')->actingAs($company->hr)->post('/attendance/corrections/'.$id.'/reject', [])->assertSessionHasErrors('review_reason');
        $this->assertNull(DB::table('attendance_logs')->whereDate('work_date', '2026-10-28')->value('check_out'));

        $this->actingAs($company->hr)->post('/attendance/corrections/'.$id.'/reject', [
            'review_reason' => 'The clock-out time could not be confirmed.',
        ])->assertSessionHasNoErrors();

        $this->assertNull(DB::table('attendance_logs')->whereDate('work_date', '2026-10-28')->value('check_out'));
        $this->assertSame('rejected', DB::table('attendance_correction_requests')->where('id', $id)->value('status'));
        $this->assertSame('rejected', DB::table('attendance_correction_audits')->where('request_id', $id)->value('decision'));
        $this->assertNotification($company->employee->id, 'rejected');
    }

    public function test_a_holiday_or_weekly_off_without_attendance_is_not_absent(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');
        DB::table('tenants')->where('id', $company->id)->update(['week_off' => json_encode(['saturday', 'sunday'])]);
        DB::table('holidays')->insert([
            'tenant_id' => $company->id,
            'name' => 'Foundation Day',
            'date' => '2026-11-02',
            'calendar' => 'india',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->get('/my-attendance?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('history.days', function ($days): bool {
                $rows = collect($days);
                $holiday = $rows->firstWhere('date', '2026-11-02');
                $off = $rows->firstWhere('date', '2026-11-01');

                return is_array($holiday) && $holiday['status'] === 'Holiday' && $holiday['holidayName'] === 'Foundation Day' && $holiday['missing'] === false
                    && is_array($off) && $off['status'] === 'Weekly Off' && $off['missing'] === false;
            })
            ->where('history.summary.holiday', 1)
            ->where('history.summary.weeklyOff', fn ($count) => $count >= 1)
            ->where('history.summary.absent', fn ($count) => $count >= 0));

        $export = $this->actingAs($company->hr)->get('/attendance/export?date=2026-11-02')->assertOk();
        $this->assertStringContainsString('Holiday', $export->getContent());
        $this->actingAs($company->hr)->get('/attendance?date=2026-11-02')->assertInertia(fn (Assert $page) => $page
            ->where('roster.rows', function ($rows): bool {
                return collect($rows)->contains(fn ($row) => ($row['status'] ?? null) === 'Holiday' && ($row['holidayName'] ?? null) === 'Foundation Day');
            }));
    }

    public function test_holiday_clock_in_requires_authorization_and_still_enforces_office_checks(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');
        $this->network($company);
        DB::table('holidays')->insert([
            'tenant_id' => $company->id,
            'name' => 'Foundation Day',
            'date' => '2026-11-02',
            'calendar' => 'india',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->from('/my-attendance')->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'This date is the Foundation Day holiday. Clock-in is closed unless HR authorizes holiday work.']);

        $this->actingAs($company->hr)->post('/attendance/holiday-work', [
            'employee_id' => $company->reportEmployeeId,
            'work_date' => '2026-11-02',
            'kind' => 'holiday',
            'reason' => 'Coverage for the reception desk.',
        ])->assertSessionHasNoErrors();
        $this->assertNotification($company->employee->id, 'holiday-work');

        $this->from('/my-attendance')->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-11-02')->count());

        DB::table('locations')->where('id', $company->officeId)->update([
            'gps_verification_enabled' => true,
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
        ]);
        $this->from('/my-attendance')->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['latitude' => 0, 'longitude' => 0])
            ->assertSessionHasErrors('attendance');

        DB::table('locations')->where('id', $company->officeId)->update(['gps_verification_enabled' => false]);
        $this->from('/my-attendance')->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $this->assertSame('holiday_work', DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-11-02')->value('status'));
    }

    public function test_weekly_off_authorization_does_not_change_the_company_week_off(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-01 04:30:00');
        $this->network($company);
        DB::table('tenants')->where('id', $company->id)->update(['week_off' => json_encode(['saturday', 'sunday'])]);

        $this->from('/my-attendance')->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'This date is a weekly off. Clock-in is closed unless HR authorizes weekly-off work.']);

        $this->actingAs($company->hr)->post('/attendance/holiday-work', [
            'employee_id' => $company->reportEmployeeId,
            'work_date' => '2026-11-01',
            'kind' => 'weekly_off',
        ])->assertSessionHasNoErrors();
        $this->assertSame(json_encode(['saturday', 'sunday']), DB::table('tenants')->where('id', $company->id)->value('week_off'));

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->assertSame('present', DB::table('attendance_logs')->whereDate('work_date', '2026-11-01')->value('status'));
        $this->assertSame(json_encode(['saturday', 'sunday']), DB::table('tenants')->where('id', $company->id)->value('week_off'));
    }

    public function test_clock_in_break_clock_out_and_recovery_still_work(): void
    {
        $company = $this->company()[0];
        $this->freeze('2026-11-02 04:30:00');
        $this->network($company);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->post('/attendance/break-start')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->post('/attendance/break-end')->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-11-02 12:30:00');
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-out')->assertSessionHasNoErrors();
        $closed = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-11-02')->first();
        $this->assertNotNull($closed->check_out);

        $open = DB::table('attendance_logs')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'location_id' => $company->officeId,
            'work_date' => '2026-10-30',
            'check_in' => '2026-10-30 09:30:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($company->employee)->post('/attendance/'.$open.'/recover', ['reason' => 'Closed the interrupted session'])->assertForbidden();
        $this->actingAs($company->hr)->post('/attendance/'.$open.'/recover', ['reason' => 'Closed the interrupted session'])->assertSessionHasNoErrors();
        $this->assertSame('recovered', DB::table('attendance_logs')->where('id', $open)->value('status'));
    }

    public function test_invitation_payroll_and_role_boundaries_remain_unchanged(): void
    {
        $company = $this->company()[0];
        $this->get('/employee-invitations/not-a-real-token')->assertNotFound();
        $this->actingAs($company->employee)->get('/payroll/imports/'.$company->payrollRunId)->assertNotFound();
        $this->actingAs($company->lead)->get('/attendance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('corrections', [])
            ->where('canCorrect', false));
        $this->actingAs($this->accounts($company))->post('/attendance/holiday-work', [
            'employee_id' => $company->reportEmployeeId,
            'work_date' => '2026-11-02',
            'kind' => 'holiday',
        ])->assertForbidden();
    }

    /**
     * @return array{0: CompanyFixture, 1: User}
     */
    private function company(): array
    {
        $company = CompanyFixture::make('Acme Attendance '.uniqid(), 'acme-'.uniqid());
        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id');
        $owner = User::factory()->create();
        $owner->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $owner->id,
            'tenant_id' => $company->id,
        ]);

        return [$company, $owner->refresh()];
    }

    private function accounts(CompanyFixture $company): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }

    private function freeze(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'UTC'));
    }

    private function network(CompanyFixture $company): void
    {
        DB::table('locations')->where('id', $company->officeId)->update(['timezone' => 'Asia/Kolkata', 'status' => 'active']);
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function shift(CompanyFixture $company): void
    {
        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'General',
            'type' => 'fixed',
            'start_time' => '09:30:00',
            'end_time' => '18:30:00',
            'expected_minutes' => 540,
            'grace_minutes' => 0,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['shift_id' => $shiftId]);
    }

    private function log(CompanyFixture $company, string $date, ?string $in, ?string $out, int $late = 0): void
    {
        DB::table('attendance_logs')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'location_id' => $company->officeId,
            'work_date' => $date,
            'check_in' => $in ? $date.' '.$in : null,
            'check_out' => $out ? $date.' '.$out : null,
            'status' => 'present',
            'late_minutes' => $late,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function request(string $date, string $type, ?string $out = null): array
    {
        return [
            'work_date' => $date,
            'type' => $type,
            'check_in' => str_contains($type, 'clock_out') ? '' : '09:30',
            'check_out' => $out ?? (str_contains($type, 'clock_out') ? '18:30' : ''),
            'reason' => 'Please correct this attendance record.',
        ];
    }

    private function assertNotification(int $userId, string $fragment): void
    {
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $userId)->where('data', 'like', '%'.$fragment.'%')->exists());
    }
}
