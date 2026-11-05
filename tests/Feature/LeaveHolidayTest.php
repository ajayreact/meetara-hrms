<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\LeaveCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeaveHolidayTest extends TestCase
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

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/leave')->assertRedirect('/login');
        $this->get('/holidays')->assertRedirect('/login');
    }

    public function test_an_employee_can_request_leave_only_for_themselves(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 10);
        $foreignType = $this->seedLeave($other, $other->reportEmployeeId, 10);
        $before = DB::table('leave_requests')->count();

        $this->from('/leave')->actingAs($company->employee)->post('/leave', [
            'leave_type_id' => $type,
            'employee_id' => $company->outsideEmployeeId,
            'tenant_id' => $other->id,
            'start_date' => '2026-10-19',
            'end_date' => '2026-10-21',
            'reason' => 'Family event',
            'days' => 9,
        ])->assertRedirect('/leave')->assertSessionHas('status');

        $request = DB::table('leave_requests')->where('employee_id', $company->reportEmployeeId)->whereDate('start_date', '2026-10-19')->first();
        $this->assertSame($company->id, (int) $request->tenant_id);
        $this->assertSame($company->reportEmployeeId, (int) $request->employee_id);
        $this->assertEquals(2, (float) $request->days);
        $this->assertSame('pending', $request->status);
        $this->assertSame('pending', $request->payroll_status);
        $this->assertEquals(2, (float) DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->value('pending'));
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->value('used'));

        $this->from('/leave')->actingAs($company->employee)->post('/leave', [
            'leave_type_id' => $foreignType,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'Family event',
        ])->assertSessionHas('error');

        $this->assertSame($before + 1, DB::table('leave_requests')->count());
        $this->assertSame(0, DB::table('leave_requests')->where('tenant_id', $other->id)->where('leave_type_id', $foreignType)->count());
    }

    public function test_cross_company_review_is_denied(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 10);
        $id = $this->requestLeave($company->employee, $type, '2026-10-19', '2026-10-19');

        $this->actingAs($other->hr)->post("/leave/{$id}/approve", ['note' => 'Approved'])->assertNotFound();
        $this->assertSame('pending', DB::table('leave_requests')->where('id', $id)->value('status'));
        $this->assertEquals(1, (float) DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->value('pending'));
    }

    public function test_a_team_lead_reviews_only_direct_reports_and_cannot_approve_twice(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 10);
        $outsideType = $this->seedLeave($company, $company->outsideEmployeeId, 10);
        $id = $this->requestLeave($company->employee, $type, '2026-10-19', '2026-10-19');
        $outsideUser = User::query()->findOrFail(DB::table('employees')->where('id', $company->outsideEmployeeId)->value('user_id'));
        $outsideId = $this->requestLeave($outsideUser, $outsideType, '2026-10-21', '2026-10-21');

        $this->actingAs($company->lead)->post("/leave/{$outsideId}/approve")->assertNotFound();
        $this->actingAs($company->employee)->post("/leave/{$id}/approve")->assertForbidden();
        $this->actingAs($company->lead)->post("/leave/{$id}/approve", ['note' => 'Approved for the team'])->assertRedirect();

        $balance = DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->where('leave_type_id', $type)->first();
        $this->assertEquals(0, (float) $balance->pending);
        $this->assertEquals(1, (float) $balance->used);
        $this->assertSame('approved', DB::table('leave_requests')->where('id', $id)->value('status'));
        $this->assertSame($company->lead->id, (int) DB::table('leave_requests')->where('id', $id)->value('reviewed_by'));

        $this->from('/leave')->actingAs($company->hr)->post("/leave/{$id}/approve")->assertSessionHas('error');
        $balance = DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->where('leave_type_id', $type)->first();
        $this->assertEquals(1, (float) $balance->used);
        $this->assertSame(1, DB::table('leave_request_events')->where('leave_request_id', $id)->where('action', 'approved')->count());
    }

    public function test_overlapping_requests_are_refused_and_rejection_releases_the_hold(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 10);
        $this->requestLeave($company->employee, $type, '2026-10-19', '2026-10-21');

        $this->from('/leave')->actingAs($company->employee)->post('/leave', [
            'leave_type_id' => $type,
            'start_date' => '2026-10-21',
            'end_date' => '2026-10-22',
            'reason' => 'Family event',
        ])->assertSessionHas('error', 'Those dates overlap a pending or approved request.');

        $this->assertEquals(2, (float) DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->where('leave_type_id', $type)->value('pending'));
        $id = DB::table('leave_requests')->where('leave_type_id', $type)->value('id');

        $this->from('/leave')->actingAs($company->hr)->post("/leave/{$id}/reject", ['note' => 'Dates clash'])->assertRedirect();
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->where('leave_type_id', $type)->value('pending'));
        $this->assertSame('Dates clash', DB::table('leave_requests')->where('id', $id)->value('reviewer_note'));

        $this->requestLeave($company->employee, $type, '2026-10-21', '2026-10-22');
        $this->assertEquals(2, (float) DB::table('leave_balances')->where('employee_id', $company->reportEmployeeId)->where('leave_type_id', $type)->value('pending'));
    }

    public function test_weekends_holidays_and_half_days_follow_the_stored_rules(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 10);
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $calendar = app(LeaveCalendar::class);

        $this->assertSame(4.0, $calendar->workingDays($employee, Carbon::parse('2026-10-19'), Carbon::parse('2026-10-25')));

        DB::table('holidays')->insert([
            'tenant_id' => $company->id,
            'calendar' => 'india',
            'name' => 'Midweek',
            'date' => '2026-10-21',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('holidays')->insert([
            'tenant_id' => $other->id,
            'calendar' => 'india',
            'name' => 'Other company',
            'date' => '2026-10-22',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('holidays')->insert([
            'tenant_id' => $company->id,
            'calendar' => 'usa',
            'name' => 'United States only',
            'date' => '2026-10-22',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('holidays')->insert([
            'tenant_id' => $company->id,
            'calendar' => 'india',
            'name' => 'Saturday holiday',
            'date' => '2026-10-24',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(3.0, $calendar->workingDays($employee, Carbon::parse('2026-10-19'), Carbon::parse('2026-10-25')));

        DB::table('holidays')->where('name', 'Midweek')->update(['status' => 'inactive']);
        $this->assertSame(4.0, $calendar->workingDays($employee, Carbon::parse('2026-10-19'), Carbon::parse('2026-10-25')));
        DB::table('holidays')->where('name', 'Midweek')->update(['status' => 'active']);

        $this->from('/leave')->actingAs($company->employee)->post('/leave', [
            'leave_type_id' => $type,
            'start_date' => '2026-10-19',
            'end_date' => '2026-10-25',
            'reason' => 'Family event',
            'half_day' => true,
        ])->assertSessionHas('error', 'Half-day leave is not configured for this company.');

        $this->assertSame(0, DB::table('leave_requests')->where('leave_type_id', $type)->count());
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('pending'));

        $id = $this->requestLeave($company->employee, $type, '2026-10-19', '2026-10-25');
        $this->assertEquals(3, (float) DB::table('leave_requests')->where('id', $id)->value('days'));
    }

    public function test_cancellation_and_reversal_update_balances_once(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 10);
        $pending = $this->requestLeave($company->employee, $type, '2026-10-19', '2026-10-19');

        $this->actingAs($company->employee)->post("/leave/{$pending}/cancel")->assertRedirect();
        $this->assertSame('cancelled', DB::table('leave_requests')->where('id', $pending)->value('status'));
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('pending'));

        $approved = $this->requestLeave($company->employee, $type, '2026-10-21', '2026-10-21');
        $this->actingAs($company->lead)->post("/leave/{$approved}/approve")->assertRedirect();
        $this->from('/leave')->actingAs($company->employee)->post("/leave/{$approved}/cancel")->assertSessionHas('error');
        $this->assertEquals(1, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('used'));

        $this->actingAs($company->hr)->post("/leave/{$approved}/cancel", ['note' => 'Reversed by HR'])->assertRedirect();
        $this->assertSame('cancelled', DB::table('leave_requests')->where('id', $approved)->value('status'));
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('used'));
        $this->assertSame(1, DB::table('leave_request_events')->where('leave_request_id', $approved)->where('action', 'cancelled')->count());
        $this->assertSame(0, DB::table('payroll_runs')->where('status', 'processed')->count());
    }

    public function test_requests_cannot_invent_or_exceed_a_balance(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 1);

        $this->from('/leave')->actingAs($company->employee)->post('/leave', [
            'leave_type_id' => $type,
            'start_date' => '2026-10-19',
            'end_date' => '2026-10-21',
            'reason' => 'Family event',
        ])->assertSessionHas('error', 'The request is longer than the remaining balance.');

        $this->assertEquals(1, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('allocated'));
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('pending'));
        $this->assertSame(0, DB::table('leave_requests')->where('leave_type_id', $type)->count());
    }

    public function test_holiday_administration_stays_inside_the_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $existing = DB::table('holidays')->where('tenant_id', $company->id)->count();

        $this->actingAs($company->employee)->get('/leave')->assertOk();
        $this->actingAs($company->employee)->get('/holidays')->assertOk();
        $this->assertSame($existing, DB::table('holidays')->where('tenant_id', $company->id)->count());
        $this->assertSame(1, DB::table('leave_requests')->where('tenant_id', $company->id)->count());

        $this->actingAs($company->employee)->post('/holidays', [
            'name' => 'Blocked',
            'date' => '2026-11-02',
            'calendar' => 'india',
        ])->assertForbidden();

        $this->actingAs($company->hr)->post('/holidays', [
            'name' => 'Foundation Day',
            'date' => '2026-11-02',
            'calendar' => 'india',
        ])->assertRedirect();

        $holidayId = (int) DB::table('holidays')->where('name', 'Foundation Day')->value('id');
        $this->assertSame($company->id, (int) DB::table('holidays')->where('id', $holidayId)->value('tenant_id'));

        $this->actingAs($other->hr)->put("/holidays/{$holidayId}", [
            'name' => 'Taken',
            'date' => '2026-11-02',
            'calendar' => 'india',
        ])->assertNotFound();

        $this->actingAs($company->hr)->put("/holidays/{$holidayId}", [
            'name' => 'Foundation Day',
            'date' => '2026-11-02',
            'calendar' => 'india',
            'status' => 'inactive',
        ])->assertRedirect();

        $this->assertSame('inactive', DB::table('holidays')->where('id', $holidayId)->value('status'));
        $this->assertSame($existing + 1, DB::table('holidays')->where('tenant_id', $company->id)->count());
        $this->assertSame('Foundation Day', DB::table('holidays')->where('id', $holidayId)->value('name'));

        $this->actingAs($company->employee)->get('/holidays?calendar=india&year=2026')
            ->assertInertia(fn (Assert $page) => $page->component('holidays/index')->where('holidays', fn ($rows) => collect($rows)->pluck('name')->contains('Republic Day')
                && collect($rows)->pluck('name')->contains('Dussehra')
                && collect($rows)->pluck('name')->doesntContain('Foundation Day')));

        $this->actingAs($company->employee)->get('/holidays?calendar=usa&year=2026')
            ->assertInertia(fn (Assert $page) => $page->where('holidays', fn ($rows) => collect($rows)->pluck('name')->contains('Thanksgiving Day')
                && collect($rows)->contains(fn ($row) => $row['name'] === 'Independence Day' && $row['date'] === '2026-07-03')));

        $this->actingAs($other->hr)->get('/holidays?calendar=india&year=2026')
            ->assertInertia(fn (Assert $page) => $page->where('holidays', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Foundation Day')));
    }

    public function test_a_company_admin_can_change_the_waiting_period_without_changing_balances(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $type = $this->seedLeave($company, $company->reportEmployeeId, 8);
        $policyId = (int) DB::table('leave_policies')->where('leave_type_id', $type)->value('id');
        $admin = $this->owner($company);

        $this->actingAs($company->hr)->put("/leave/policies/{$policyId}", ['applicable_after_days' => 365])->assertNotFound();
        $this->actingAs($admin)->put("/leave/policies/{$policyId}", ['applicable_after_days' => 365])->assertRedirect();

        $this->assertSame(365, (int) DB::table('leave_policies')->where('id', $policyId)->value('applicable_after_days'));
        $this->assertEquals(8, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('allocated'));

        $this->from('/leave')->actingAs($company->employee)->post('/leave', [
            'leave_type_id' => $type,
            'start_date' => '2026-10-19',
            'end_date' => '2026-10-19',
            'reason' => 'Family event',
        ])->assertSessionHas('error', 'This leave type is not available yet.');
    }

    private function seedLeave(CompanyFixture $company, int $employeeId, float $allocated): int
    {
        DB::table('tenants')->where('id', $company->id)->update([
            'week_off' => json_encode(['saturday', 'sunday']),
        ]);

        $typeId = DB::table('leave_types')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Casual Leave',
            'code' => 'CL-'.$employeeId,
            'days_per_year' => $allocated,
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

        DB::table('leave_balances')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $employeeId,
            'leave_type_id' => $typeId,
            'year' => 2026,
            'allocated' => $allocated,
            'used' => 0,
            'pending' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $typeId;
    }

    private function requestLeave(User $user, int $typeId, string $start, string $end): int
    {
        $this->from('/leave')->actingAs($user)->post('/leave', [
            'leave_type_id' => $typeId,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Family event',
        ])->assertRedirect()->assertSessionHas('status');

        return (int) DB::table('leave_requests')->where('leave_type_id', $typeId)->whereDate('start_date', $start)->value('id');
    }

    private function owner(CompanyFixture $company): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();

        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
