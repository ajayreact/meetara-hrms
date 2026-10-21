<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\HrmsSchema;
use Tests\TestCase;

class HrmsInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_company_pages(): void
    {
        foreach (['/employees', '/attendance', '/payslips', '/offices', '/companies'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_client_cannot_choose_a_role(): void
    {
        $this->post('/preview/role', ['role' => 'super_admin'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_employee_without_a_stored_role_cannot_open_management_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hrms/forbidden')
                ->where('workspace.permissions.employees', false)
                ->where('workspace.permissions.payroll', false));

        $this->get('/companies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
    }

    public function test_inactive_employee_cannot_sign_in(): void
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
}
