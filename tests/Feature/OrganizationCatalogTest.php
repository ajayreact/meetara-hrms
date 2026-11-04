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
                ->where('levels.3.label', 'Company Admin')
                ->where('levels.3.protected', true)
                ->where('levels.3.available', true));

        $this->actingAs($owner)->get('/employees')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.roles.0.label', 'Employee')
                ->where('options.roles.1.label', 'Team Lead')
                ->where('options.roles.2.label', 'HR')
                ->where('options.roles.3.label', 'Company Admin'));

        foreach (['Owner', 'Employee', 'Manager', 'HR'] as $role) {
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
        ]))->assertRedirect();

        $second = Employee::query()->where('email', 'second@veltrix.test')->firstOrFail();
        $this->assertSame((int) $first->id, (int) $second->manager_id);
        $this->assertSame('HR', DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $second->user_id)
            ->value('roles.name'));

        $this->actingAs($owner)->post('/employees', $this->employeePayload([
            'email' => 'outside@veltrix.test',
            'employee_code' => 'VLTX-005',
            'department_id' => $other->departmentId,
        ]))->assertSessionHasErrors('department_id');
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
