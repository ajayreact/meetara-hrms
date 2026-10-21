<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\HrmsSchema;
use Tests\TestCase;

class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_cannot_list_or_change_another_companys_employees(): void
    {
        $first = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $second = CompanyFixture::make('Deep Data Inc', 'deep-data');

        $this->actingAs($first->hr)
            ->get('/employees')
            ->assertOk()
            ->assertSee($first->reportEmail)
            ->assertDontSee($second->outsideEmail)
            ->assertDontSee($second->reportEmail)
            ->assertDontSee('Tenant');

        $this->actingAs($first->hr)
            ->get('/employees?q='.$first->reportEmail)
            ->assertOk()
            ->assertSee($first->reportEmail)
            ->assertDontSee($first->outsideEmail);

        $this->actingAs($first->hr)->get('/employees/'.$second->reportEmployeeId)->assertNotFound();
        $this->actingAs($first->hr)->put('/employees/'.$second->reportEmployeeId, $this->payload($first, 'VEL-2'))->assertNotFound();
        $this->actingAs($first->hr)->post('/employees/'.$second->reportEmployeeId.'/status', ['status' => 'inactive'])->assertNotFound();

        $this->assertSame('active', DB::table('employees')->where('id', $second->reportEmployeeId)->value('status'));
    }

    public function test_team_leads_only_see_their_direct_reports_and_cannot_edit_them(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');

        $this->actingAs($company->lead)
            ->get('/employees')
            ->assertOk()
            ->assertSee($company->reportEmail)
            ->assertDontSee($company->outsideEmail)
            ->assertDontSee($other->reportEmail);

        $this->actingAs($company->lead)->get('/employees/'.$company->outsideEmployeeId)->assertNotFound();
        $this->actingAs($company->lead)->put('/employees/'.$company->reportEmployeeId, $this->payload($company, 'VEL-REP'))->assertForbidden();
        $this->actingAs($company->lead)->post('/employees', $this->payload($company, 'VEL-NEW'))->assertForbidden();
        $this->actingAs($company->lead)->get('/employees/export')->assertForbidden();
    }

    public function test_employees_can_open_only_their_own_profile(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');

        $this->actingAs($company->employee)
            ->get('/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));

        $this->actingAs($company->employee)->get('/employees/'.$company->outsideEmployeeId)->assertNotFound();
        $this->actingAs($company->employee)->get('/employees/export')->assertForbidden();

        $this->actingAs($company->employee)
            ->get('/my-profile')
            ->assertOk()
            ->assertSee($company->reportEmail)
            ->assertDontSee($company->outsideEmail);

        $this->actingAs($company->employee)
            ->get('/employees/'.$company->reportEmployeeId)
            ->assertOk()
            ->assertSee($company->reportEmail)
            ->assertDontSee($company->outsideEmail);
    }

    public function test_platform_admin_is_not_given_company_employee_access(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $platform = User::factory()->create();
        $platform->forceFill([
            'is_platform_admin' => true,
            'tenant_id' => null,
        ])->save();

        $this->actingAs($platform)
            ->get('/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hrms/forbidden')
                ->where('workspace.role', 'super_admin')
                ->where('workspace.permissions.employees', false))
            ->assertDontSee($company->reportEmail);

        $this->actingAs($platform)->get('/employees/'.$company->reportEmployeeId)->assertNotFound();
        $this->actingAs($platform)->get('/employees/export')->assertForbidden();
    }

    public function test_hr_can_create_update_and_deactivate_only_inside_their_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');

        $this->actingAs($company->hr)->post('/employees', [
            ...$this->payload($company, 'VEL-NEW'),
            'email' => 'new.person@example.test',
            'tenant_id' => $other->id,
            'is_platform_admin' => true,
            'create_login' => true,
            'role' => 'Employee',
        ])->assertRedirect();

        $created = DB::table('employees')->where('email', 'new.person@example.test')->first();
        $this->assertNotNull($created);
        $this->assertSame($company->id, (int) $created->tenant_id);
        $this->assertSame('invited', $created->status);

        $login = DB::table('users')->where('email', 'new.person@example.test')->first();
        $this->assertNotNull($login);
        $this->assertSame($company->id, (int) $login->tenant_id);
        $this->assertSame(0, (int) $login->is_platform_admin);
        $this->assertNotNull($login->invited_at);
        $this->assertNull($login->invite_accepted_at);

        $this->actingAs($company->hr)->put('/employees/'.$created->id, [
            ...$this->payload($company, 'VEL-NEW'),
            'email' => 'new.person@example.test',
            'last_name' => 'Updated',
            'tenant_id' => $other->id,
        ])->assertRedirect();

        $this->assertSame('Updated', DB::table('employees')->where('id', $created->id)->value('last_name'));
        $this->assertSame($company->id, (int) DB::table('employees')->where('id', $created->id)->value('tenant_id'));

        $this->actingAs($company->hr)
            ->post('/employees/'.$company->reportEmployeeId.'/status', ['status' => 'inactive'])
            ->assertRedirect();

        $this->assertSame('inactive', DB::table('employees')->where('id', $company->reportEmployeeId)->value('status'));
        $this->assertSame('active', DB::table('employees')->where('id', $other->reportEmployeeId)->value('status'));

        $this->actingAs($company->hr)
            ->post('/employees/'.$company->reportEmployeeId.'/status', ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', DB::table('employees')->where('id', $company->reportEmployeeId)->value('status'));
    }

    public function test_cross_company_department_manager_and_owner_assignment_are_rejected(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');

        $this->actingAs($company->hr)->post('/employees', [
            ...$this->payload($company, 'VEL-BAD'),
            'email' => 'bad.department@example.test',
            'department_id' => $other->departmentId,
        ])->assertSessionHasErrors('department_id');

        $this->actingAs($company->hr)->post('/employees', [
            ...$this->payload($company, 'VEL-MGR'),
            'email' => 'bad.manager@example.test',
            'manager_id' => $other->reportEmployeeId,
        ])->assertSessionHasErrors('manager_id');

        $this->actingAs($company->hr)->post('/employees', [
            ...$this->payload($company, 'VEL-OWN'),
            'email' => 'owner.try@example.test',
            'create_login' => true,
            'role' => 'Owner',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('employees', ['email' => 'bad.department@example.test']);
        $this->assertDatabaseMissing('employees', ['email' => 'owner.try@example.test']);
    }

    public function test_employee_export_contains_only_the_signed_in_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');

        $this->actingAs($company->hr)
            ->get('/employees/export')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="employees.csv"')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertSee($company->reportEmail)
            ->assertDontSee($other->reportEmail)
            ->assertDontSee($other->outsideEmail);
    }

    public function test_operational_records_stay_inside_their_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');

        $this->assertScoped(AttendanceLog::class, $company->id, $company->attendanceId, $other->attendanceId);
        $this->assertScoped(Holiday::class, $company->id, $company->holidayId, $other->holidayId);
        $this->assertScoped(LeaveRequest::class, $company->id, $company->leaveId, $other->leaveId);
        $this->assertScoped(Office::class, $company->id, $company->officeId, $other->officeId);
        $this->assertScoped(Ticket::class, $company->id, $company->ticketId, $other->ticketId);
        $this->assertScoped(PayrollRun::class, $company->id, $company->payrollRunId, $other->payrollRunId);
        $this->assertScoped(PayrollItem::class, $company->id, $company->payrollItemId, $other->payrollItemId);
    }

    public function test_opening_the_directory_does_not_create_payroll_records(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $before = DB::table('payroll_runs')->count();

        $this->actingAs($company->hr)->get('/employees')->assertOk();

        $this->assertSame($before, DB::table('payroll_runs')->count());
    }

    public function test_inactive_employee_schema_still_blocks_sign_in(): void
    {
        HrmsSchema::install();
        $companyId = DB::table('tenants')->insertGetId([
            'name' => 'Inactive Co',
            'slug' => 'inactive-co',
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $companyId,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('employees')->insert([
            'tenant_id' => $companyId,
            'user_id' => $user->id,
            'employee_code' => 'IN-1',
            'first_name' => 'Inactive',
            'last_name' => 'Person',
            'email' => 'inactive.person@example.test',
            'date_of_joining' => '2026-01-01',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * @param  class-string  $model
     */
    private function assertScoped(string $model, int $companyId, int $visibleId, int $hiddenId): void
    {
        $ids = $model::query()->inCompany($companyId)->pluck('id');

        $this->assertTrue($ids->contains($visibleId));
        $this->assertFalse($ids->contains($hiddenId));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CompanyFixture $company, string $code): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => strtolower($code).'@example.test',
            'phone' => '5550100',
            'employee_code' => $code,
            'department_id' => $company->departmentId,
            'date_of_joining' => '2026-02-01',
            'holiday_calendar' => 'india',
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'create_login' => false,
        ];
    }
}
