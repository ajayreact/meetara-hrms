<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class FiveRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_of_operations_prepares_payroll_and_payslips_stay_with_the_owner(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix');
        $other = CompanyFixture::make('Other Company', 'other');
        $owner = $this->owner($company);
        $payrollRuns = DB::table('payroll_runs')->count();
        $payrollItems = DB::table('payroll_items')->count();

        $this->actingAs($owner)->post('/payroll/runs', ['year' => 2026, 'month' => 12])->assertRedirect();
        $this->actingAs($company->hr)->post('/payroll/runs', ['year' => 2026, 'month' => 8])->assertForbidden();
        $this->actingAs($company->hr)->get('/payments')->assertForbidden();
        $this->actingAs($company->lead)->post('/payroll/runs', ['year' => 2026, 'month' => 7])->assertForbidden();
        $this->actingAs($owner)->get('/payments')->assertOk();

        DB::table('payroll_runs')->where('id', $company->payrollRunId)->update(['status' => 'processed']);
        $leadSlip = DB::table('payroll_items')->insertGetId([
            'tenant_id' => $company->id,
            'payroll_run_id' => $company->payrollRunId,
            'employee_id' => $company->leadEmployeeId,
            'net' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->lead)->get('/payslips')
            ->assertOk()
            ->assertSee('veltrix-LEAD')
            ->assertDontSee('veltrix-report@example.test');
        $this->actingAs($company->lead)->get('/payslips/'.$company->payrollItemId)->assertNotFound();
        $this->actingAs($company->employee)->get('/payslips/'.$leadSlip)->assertNotFound();
        $this->actingAs($company->hr)->get('/payslips/'.$leadSlip)->assertOk();
        $this->actingAs($other->hr)->get('/payslips/'.$leadSlip)->assertNotFound();

        $this->assertSame($payrollItems + 1, DB::table('payroll_items')->count());
        $this->assertSame($payrollRuns + 1, DB::table('payroll_runs')->count());
    }

    public function test_accounts_only_users_can_move_to_owner_and_roll_back_without_deleting_the_role(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix');
        $only = User::factory()->create();
        $only->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $mixed = User::factory()->create();
        $mixed->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $accountsId = (int) DB::table('roles')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeeRoleId = (int) DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Employee')->value('id');
        DB::table('model_has_roles')->insert([
            ['role_id' => $accountsId, 'model_type' => User::class, 'model_id' => $only->id, 'tenant_id' => $company->id],
            ['role_id' => $accountsId, 'model_type' => User::class, 'model_id' => $mixed->id, 'tenant_id' => $company->id],
            ['role_id' => $employeeRoleId, 'model_type' => User::class, 'model_id' => $mixed->id, 'tenant_id' => $company->id],
        ]);
        $runs = DB::table('payroll_runs')->count();

        $migration = require database_path('migrations/2026_11_04_050000_reassign_accounts_only_users_to_owner.php');
        $migration->up();

        $this->assertSame('Owner', $this->roleName($only->id));
        $this->assertSame('Accounts', $this->roleName($mixed->id));
        $this->assertNotNull(DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Accounts')->value('id'));
        $this->assertSame(1, DB::table('role_assignment_events')->where('subject_user_id', $only->id)->where('action', 'accounts_to_owner')->count());
        $this->assertSame(0, DB::table('role_assignment_events')->where('subject_user_id', $mixed->id)->where('action', 'accounts_to_owner')->count());
        $this->assertSame($runs, DB::table('payroll_runs')->count());

        $migration->down();

        $this->assertSame('Accounts', $this->roleName($only->id));
        $this->assertSame('Accounts', $this->roleName($mixed->id));
        $this->assertSame(0, DB::table('role_assignment_events')->where('action', 'accounts_to_owner')->count());
        $this->assertNotNull(DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Accounts')->value('id'));
    }

    private function roleName(int $userId): ?string
    {
        return DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $userId)
            ->orderBy('roles.name')
            ->value('roles.name');
    }

    private function owner(CompanyFixture $company): User
    {
        $roleId = (int) DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id');
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
}
