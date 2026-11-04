<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OrganizationCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_company_admin_manages_departments_and_designations_for_that_company_only(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix');
        $other = CompanyFixture::make('Other Company', 'other');
        $owner = $this->owner($company);

        $this->actingAs($company->employee)->get('/departments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->lead)->get('/designations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('hrms/forbidden'));

        $this->actingAs($owner)->get('/departments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('organization/departments'));
        $this->actingAs($owner)->post('/departments', [
            'name' => 'Engineering',
            'code' => 'ENG',
        ])->assertRedirect();
        $this->actingAs($owner)->post('/departments', [
            'name' => 'Engineering',
        ])->assertSessionHasErrors('name');

        $departmentId = (int) DB::table('departments')->where('tenant_id', $company->id)->where('name', 'Engineering')->value('id');
        $this->assertNotSame(0, $departmentId);
        $this->assertSame(1, DB::table('departments')->where('tenant_id', $company->id)->where('name', 'Engineering')->count());

        $this->actingAs($owner)->put('/departments/'.$departmentId, [
            'name' => 'Engineering Operations',
            'code' => 'ENG',
            'is_active' => true,
        ])->assertRedirect();
        $this->actingAs($owner)->put('/departments/'.$departmentId, [
            'name' => 'Engineering Operations',
            'code' => 'ENG',
            'is_active' => false,
        ])->assertRedirect();
        $this->assertSame(0, (int) DB::table('departments')->where('id', $departmentId)->value('is_active'));
        $this->actingAs($other->hr)->put('/departments/'.$departmentId, [
            'name' => 'Taken',
            'is_active' => true,
        ])->assertNotFound();
        $this->assertSame('Engineering Operations', DB::table('departments')->where('id', $departmentId)->value('name'));

        $this->actingAs($owner)->post('/designations', ['name' => 'Developer'])->assertRedirect();
        $designationId = (int) DB::table('designations')->where('tenant_id', $company->id)->where('name', 'Developer')->value('id');
        $this->actingAs($owner)->put('/designations/'.$designationId, [
            'name' => 'Senior Developer',
            'is_active' => false,
        ])->assertRedirect();
        $this->assertSame(0, (int) DB::table('designations')->where('id', $designationId)->value('is_active'));

        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'department_id' => $departmentId,
            'designation_id' => $designationId,
        ]))->assertSessionHasErrors(['department_id', 'designation_id']);
        $this->assertSame(0, Employee::query()->where('email', 'ambica@veltrix.test')->count());

        $this->actingAs($owner)->put('/departments/'.$departmentId, [
            'name' => 'Engineering Operations',
            'code' => 'ENG',
            'is_active' => true,
        ])->assertRedirect();
        $this->actingAs($owner)->put('/designations/'.$designationId, [
            'name' => 'Senior Developer',
            'is_active' => true,
        ])->assertRedirect();
    }

    public function test_standard_access_levels_can_provision_the_first_employee_without_a_manager(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix');
        $other = CompanyFixture::make('Other Company', 'other');
        $owner = $this->owner($company);
        $ownerAssignments = DB::table('model_has_roles')->where('model_id', $owner->id)->count();
        DB::table('model_has_roles')->where('tenant_id', $company->id)->whereIn('role_id', DB::table('roles')->where('tenant_id', $company->id)->whereIn('name', ['Employee', 'Manager', 'HR'])->pluck('id'))->delete();
        DB::table('roles')->where('tenant_id', $company->id)->whereIn('name', ['Employee', 'Manager', 'HR'])->delete();

        $this->actingAs($owner)->get('/access-levels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('organization/access-levels')
                ->where('levels.1.label', 'Team Lead')
                ->where('levels.1.available', true)
                ->where('levels.2.label', 'Company Manager')
                ->where('levels.2.available', true)
                ->where('levels.4.label', 'Head of Operations')
                ->where('levels.4.protected', true)
                ->where('levels.4.available', true)
                ->where('workspace.role', 'company_admin')
                ->where('workspace.roleLabel', 'Head of Operations'));

        $this->actingAs($owner)->get('/employees')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.roles.0.label', 'Employee')
                ->where('options.roles.1.label', 'Team Lead')
                ->where('options.roles.2.label', 'Company Manager')
                ->where('options.roles.3.label', 'HR & Assistant Operations')
                ->where('options.roles.4.label', 'Head of Operations'));

        foreach (['Owner', 'Employee', 'Manager', 'HR', 'Company Manager'] as $role) {
            $this->assertSame(1, DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->count());
        }
        $this->assertSame($ownerAssignments, DB::table('model_has_roles')->where('model_id', $owner->id)->count());
        $this->assertSame('Owner', DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $owner->id)
            ->value('roles.name'));

        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'create_login' => true,
            'role' => 'Employee',
            'manager_id' => '',
        ]))->assertRedirect();

        $first = Employee::query()->where('email', 'ambica@veltrix.test')->firstOrFail();
        $this->assertSame($company->id, (int) $first->tenant_id);
        $this->assertNull($first->manager_id);
        $this->assertNotNull($first->user_id);
        $this->assertSame('Employee', DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $first->user_id)
            ->where('model_has_roles.tenant_id', $company->id)
            ->value('roles.name'));

        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'first_name' => 'Second',
            'email' => 'second@veltrix.test',
            'employee_code' => 'VLTX-004',
            'manager_id' => $first->id,
            'create_login' => true,
            'role' => 'HR',
        ]))->assertSessionHasErrors('manager_id');
        $this->assertNull(Employee::query()->where('email', 'second@veltrix.test')->first());

        $this->actingAs($owner)->post('/designations', ['name' => 'Operations Manager'])->assertRedirect();
        $designationId = (int) DB::table('designations')->where('tenant_id', $company->id)->where('name', 'Operations Manager')->value('id');
        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'first_name' => 'Priya',
            'last_name' => 'Shah',
            'email' => 'priya@veltrix.test',
            'employee_code' => 'VLTX-MGR',
            'designation_id' => $designationId,
            'create_login' => true,
            'role' => 'Company Manager',
        ]))->assertRedirect();
        $manager = Employee::query()->where('email', 'priya@veltrix.test')->firstOrFail();
        DB::table('users')->where('id', $manager->user_id)->update(['invite_accepted_at' => now()]);
        DB::table('employees')->where('id', $manager->id)->update(['status' => 'active']);

        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'first_name' => 'Second',
            'email' => 'second@veltrix.test',
            'employee_code' => 'VLTX-004',
            'manager_id' => $manager->id,
            'create_login' => true,
            'role' => 'HR',
        ]))->assertRedirect();

        $second = Employee::query()->where('email', 'second@veltrix.test')->firstOrFail();
        $this->assertSame((int) $manager->id, (int) $second->manager_id);
        $this->assertSame('HR', DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $second->user_id)
            ->value('roles.name'));
        $this->assertSame('Company Manager', DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $manager->user_id)
            ->value('roles.name'));

        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'email' => 'outside@veltrix.test',
            'employee_code' => 'VLTX-005',
            'department_id' => $other->departmentId,
        ]))->assertSessionHasErrors('department_id');
    }

    public function test_reporting_managers_are_active_company_managers_in_the_same_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix');
        $other = CompanyFixture::make('Other Company', 'other');
        $owner = $this->owner($company);
        $platform = User::factory()->create();
        $platform->forceFill([
            'is_platform_admin' => true,
            'tenant_id' => null,
            'invite_accepted_at' => now(),
        ])->save();

        $this->actingAs($platform)->get('/companies')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspace.role', 'super_admin')
                ->where('workspace.roleLabel', 'Super Admin'));
        $this->actingAs($company->hr)->get('/employees')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspace.role', 'hr')
                ->where('workspace.roleLabel', 'HR & Assistant Operations'));
        $this->actingAs($company->lead)->get('/employees')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspace.role', 'team_lead')
                ->where('workspace.roleLabel', 'Team Lead'));
        $this->actingAs($company->employee)->post('/employees', $this->employeePayload([
            'email' => 'blocked@veltrix.test',
            'employee_code' => 'VLTX-BLOCK',
        ]))->assertForbidden();

        $this->actingAs($company->hr)->post('/designations', ['name' => 'Operations Manager'])->assertRedirect();
        $designationId = (int) DB::table('designations')->where('tenant_id', $company->id)->where('name', 'Operations Manager')->value('id');
        $firstManager = $this->activateManager($company->hr, 'Asha', 'asha@veltrix.test', 'VLTX-A', $designationId);
        $secondManager = $this->activateManager($owner, 'Bala', 'bala@veltrix.test', 'VLTX-B', $designationId);

        $this->actingAs($company->hr)->get('/employees')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.managers', function ($managers): bool {
                    $names = collect($managers)->pluck('name');

                    return $names->contains('Asha Shah — Operations Manager')
                        && $names->contains('Bala Shah — Operations Manager')
                        && $names->contains('veltrix-LEAD Person — veltrix-role');
                }));

        $this->actingAs($company->hr)->post('/employees', $this->employeePayload([
            'first_name' => 'Neel',
            'email' => 'neel@veltrix.test',
            'employee_code' => 'VLTX-N',
            'manager_id' => $firstManager->id,
        ]))->assertRedirect();
        $neel = Employee::query()->where('email', 'neel@veltrix.test')->firstOrFail();
        $this->assertSame((int) $firstManager->id, (int) $neel->manager_id);

        $this->actingAs($firstManager->user)->get('/employees')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employees', function ($rows): bool {
                    $emails = collect($rows)->pluck('email');

                    return $emails->contains('neel@veltrix.test')
                        && $emails->doesntContain('veltrix-outside@example.test');
                }));

        $this->actingAs($company->hr)->get('/employees/'.$neel->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employee.manager', 'Asha Shah — Operations Manager'));

        $this->actingAs($company->hr)->put('/employees/'.$neel->id, $this->employeePayload([
            'first_name' => 'Neel',
            'email' => 'neel@veltrix.test',
            'employee_code' => 'VLTX-N',
            'manager_id' => $secondManager->id,
        ]))->assertRedirect();
        $this->assertSame((int) $secondManager->id, (int) $neel->refresh()->manager_id);

        $this->actingAs($company->hr)->put('/employees/'.$neel->id, $this->employeePayload([
            'first_name' => 'Neel',
            'email' => 'neel@veltrix.test',
            'employee_code' => 'VLTX-N',
            'manager_id' => '',
        ]))->assertRedirect();
        $this->assertNull($neel->refresh()->manager_id);

        $this->actingAs($company->hr)->put('/employees/'.$firstManager->id, $this->employeePayload([
            'first_name' => 'Asha',
            'last_name' => 'Shah',
            'email' => 'asha@veltrix.test',
            'employee_code' => 'VLTX-A',
            'designation_id' => $designationId,
            'manager_id' => $firstManager->id,
            'role' => 'Company Manager',
        ]))->assertSessionHasErrors('manager_id');

        $this->actingAs($company->hr)->put('/employees/'.$firstManager->id, $this->employeePayload([
            'first_name' => 'Asha',
            'last_name' => 'Shah',
            'email' => 'asha@veltrix.test',
            'employee_code' => 'VLTX-A',
            'designation_id' => $designationId,
            'manager_id' => $secondManager->id,
            'role' => 'Company Manager',
        ]))->assertRedirect();
        $this->actingAs($company->hr)->put('/employees/'.$secondManager->id, $this->employeePayload([
            'first_name' => 'Bala',
            'last_name' => 'Shah',
            'email' => 'bala@veltrix.test',
            'employee_code' => 'VLTX-B',
            'designation_id' => $designationId,
            'manager_id' => $firstManager->id,
            'role' => 'Company Manager',
        ]))->assertSessionHasErrors('manager_id');
        $this->assertNull($secondManager->refresh()->manager_id);

        $this->actingAs($company->hr)->post('/employees', $this->employeePayload([
            'email' => 'foreign@veltrix.test',
            'employee_code' => 'VLTX-F',
            'manager_id' => $other->reportEmployeeId,
        ]))->assertSessionHasErrors('manager_id');
        $this->assertDatabaseMissing('employees', ['email' => 'foreign@veltrix.test']);

        DB::table('employees')->where('id', $secondManager->id)->update(['status' => 'inactive']);
        $this->actingAs($company->hr)->post('/employees', $this->employeePayload([
            'email' => 'inactive-manager@veltrix.test',
            'employee_code' => 'VLTX-I',
            'manager_id' => $secondManager->id,
        ]))->assertSessionHasErrors('manager_id');

        $report = Employee::query()->findOrFail($company->reportEmployeeId);
        $this->assertSame($company->leadEmployeeId, (int) $report->manager_id);
        $this->actingAs($company->hr)->put('/employees/'.$report->id, [
            'first_name' => $report->first_name,
            'last_name' => $report->last_name,
            'email' => $report->email,
            'phone' => $report->phone,
            'employee_code' => $report->employee_code,
            'department_id' => $report->department_id,
            'designation_id' => $report->designation_id,
            'location_id' => $report->location_id,
            'manager_id' => $report->manager_id,
            'date_of_joining' => '2026-01-15',
            'holiday_calendar' => 'india',
            'employment_type' => 'full_time',
            'work_mode' => 'office',
        ])->assertRedirect();
        $this->assertSame($company->leadEmployeeId, (int) $report->refresh()->manager_id);
    }

    private function activateManager(User $owner, string $firstName, string $email, string $code, int $designationId): Employee
    {
        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'first_name' => $firstName,
            'last_name' => 'Shah',
            'email' => $email,
            'employee_code' => $code,
            'designation_id' => $designationId,
            'create_login' => true,
            'role' => 'Company Manager',
        ]))->assertRedirect();

        $manager = Employee::query()->where('email', $email)->firstOrFail();
        DB::table('users')->where('id', $manager->user_id)->update(['invite_accepted_at' => now()]);
        DB::table('employees')->where('id', $manager->id)->update(['status' => 'active']);
        $manager->refresh();
        $manager->setRelation('user', User::query()->findOrFail($manager->user_id));

        return $manager;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ambica',
            'last_name' => 'Mohan',
            'email' => 'ambica@veltrix.test',
            'phone' => '5550100',
            'employee_code' => 'VLTX-003',
            'department_id' => '',
            'designation_id' => '',
            'location_id' => '',
            'manager_id' => '',
            'date_of_joining' => '2026-10-20',
            'holiday_calendar' => 'india',
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'role' => '',
            'create_login' => false,
        ], $overrides);
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
