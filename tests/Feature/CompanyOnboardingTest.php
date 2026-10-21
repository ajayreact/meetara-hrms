<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\CompanyOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class CompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_edit_and_cycle_a_company_without_an_invitation(): void
    {
        $existing = CompanyFixture::make('Northwind', 'northwind');
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'invite_accepted_at' => now()])->save();
        $employees = DB::table('employees')->count();
        $payrollItems = DB::table('payroll_items')->count();
        $leaveRequests = DB::table('leave_requests')->count();

        $accounts = User::factory()->create();
        $accounts->forceFill(['tenant_id' => $existing->id, 'is_platform_admin' => false, 'invite_accepted_at' => now()])->save();
        $accountsRole = DB::table('roles')->insertGetId([
            'tenant_id' => $existing->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $accountsRole,
            'model_type' => User::class,
            'model_id' => $accounts->id,
            'tenant_id' => $existing->id,
        ]);
        $users = DB::table('users')->count();

        $this->actingAs($existing->employee)->post('/companies', $this->companyPayload())->assertForbidden();
        $this->actingAs($existing->hr)->post('/companies/'.$existing->id.'/status', [
            'status' => 'inactive',
            'confirm' => 'DEACTIVATE',
        ])->assertForbidden();
        $this->actingAs($existing->lead)->put('/companies/'.$existing->id, [
            'name' => 'Taken over',
            'timezone' => 'Asia/Kolkata',
        ])->assertForbidden();
        $this->actingAs($accounts)->post('/companies/'.$existing->id.'/status', [
            'status' => 'inactive',
            'confirm' => 'DEACTIVATE',
        ])->assertForbidden();
        $this->assertSame('active', DB::table('tenants')->where('id', $existing->id)->value('status'));
        $this->assertSame('Northwind', DB::table('tenants')->where('id', $existing->id)->value('name'));

        $this->actingAs($platform)->post('/companies', [
            ...$this->companyPayload(),
            'slug' => 'North Wind',
            'role' => 'Owner',
            'admin_user_id' => $existing->employee->id,
            'password' => 'secret-password',
        ])->assertSessionHasErrors(['role', 'admin_user_id', 'password']);
        $this->assertSame($users, DB::table('users')->count());

        $this->actingAs($platform)->post('/companies', [
            ...$this->companyPayload(),
            'timezone' => 'Not/A/Zone',
        ])->assertSessionHasErrors('timezone');

        $created = $this->actingAs($platform)->post('/companies', $this->companyPayload());
        $createdId = (int) DB::table('tenants')->where('slug', 'north-wind')->value('id');
        $created->assertRedirect(route('hrms.companies', ['company' => $createdId]));
        $this->assertSame('North Wind', DB::table('tenants')->where('id', $createdId)->value('name'));
        $this->assertSame('active', DB::table('tenants')->where('id', $createdId)->value('status'));
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame(1, DB::table('company_lifecycle_events')->where('tenant_id', $createdId)->where('action', 'created')->where('actor_id', $platform->id)->count());

        $this->actingAs($platform)->post('/companies', $this->companyPayload())->assertSessionHasErrors('slug');
        $this->actingAs($platform)->post('/companies', [
            ...$this->companyPayload(),
            'name' => 'North Wind',
            'slug' => 'north-wind-west',
        ])->assertRedirect();

        $this->actingAs($platform)->put('/companies/'.$createdId, [
            'name' => 'North Wind Holdings',
            'timezone' => 'Asia/Kolkata',
            'email' => 'ops@north-wind.test',
            'phone' => '',
            'address' => 'Harbour road',
            'week_off' => ['sunday'],
            'slug' => 'changed-slug',
        ])->assertSessionHasErrors('slug');
        $this->assertSame('north-wind', DB::table('tenants')->where('id', $createdId)->value('slug'));

        $this->actingAs($platform)->put('/companies/'.$createdId, [
            'name' => 'North Wind Holdings',
            'timezone' => 'Asia/Kolkata',
            'email' => 'ops@north-wind.test',
            'phone' => '',
            'address' => 'Harbour road',
            'week_off' => ['sunday'],
        ])->assertRedirect('/companies?company='.$createdId);
        $this->assertSame('North Wind Holdings', DB::table('tenants')->where('id', $createdId)->value('name'));
        $this->assertSame('north-wind', DB::table('tenants')->where('id', $createdId)->value('slug'));
        $this->assertSame(1, DB::table('company_lifecycle_events')->where('tenant_id', $createdId)->where('action', 'updated')->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('tenant_id', $createdId)->count());
        $this->assertSame(0, DB::table('employees')->where('tenant_id', $createdId)->count());

        $admin = User::factory()->create();
        $admin->forceFill([
            'tenant_id' => $createdId,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('sessions')->insert([
            'id' => 'company-session-before-deactivation',
            'user_id' => $admin->id,
            'payload' => 'stored-session',
            'last_activity' => time(),
        ]);

        $this->actingAs($platform)->post('/companies/'.$createdId.'/status', [
            'status' => 'inactive',
        ])->assertSessionHasErrors('confirm');
        $this->assertSame('active', DB::table('tenants')->where('id', $createdId)->value('status'));
        $this->assertDatabaseHas('sessions', ['id' => 'company-session-before-deactivation']);

        $this->actingAs($platform)->post('/companies/'.$createdId.'/status', [
            'status' => 'inactive',
            'confirm' => 'DEACTIVATE',
        ])->assertRedirect();
        $this->assertSame('inactive', DB::table('tenants')->where('id', $createdId)->value('status'));
        $this->assertSame('active', DB::table('tenants')->where('id', $existing->id)->value('status'));
        $this->assertSame($employees, DB::table('employees')->count());
        $this->assertSame($payrollItems, DB::table('payroll_items')->count());
        $this->assertSame($leaveRequests, DB::table('leave_requests')->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'company-session-before-deactivation']);
        $this->assertSame(1, DB::table('company_lifecycle_events')->where('tenant_id', $createdId)->where('action', 'deactivated')->count());
        $this->browseAsGuest();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $pending = User::factory()->create();
        $pending->forceFill([
            'tenant_id' => $createdId,
            'is_platform_admin' => false,
            'invited_at' => now(),
            'invite_accepted_at' => null,
            'invite_token' => 'plain-token-must-stay-hidden',
        ])->save();
        $this->browseAsGuest();
        $this->post('/login', ['email' => $pending->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This company is not active.']);
        $this->post('/invitations/accept', [
            'token' => 'plain-token-must-stay-hidden',
            'password' => 'new-password',
        ])->assertNotFound();
        $this->assertNull($pending->fresh()->invite_accepted_at);

        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($admin)->post('/attendance/clock-in')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($existing->employee)->get('/companies')->assertOk()->assertDontSee('North Wind Holdings');
        $this->actingAs($platform)->get('/audit?source=Company')
            ->assertOk()
            ->assertSee('deactivated')
            ->assertDontSee('plain-token-must-stay-hidden');

        $this->actingAs($platform)->post('/companies/'.$createdId.'/status', [
            'status' => 'active',
            'confirm' => 'REACTIVATE',
        ])->assertRedirect();
        $this->assertSame('active', DB::table('tenants')->where('id', $createdId)->value('status'));
        $this->assertSame($employees, DB::table('employees')->count());
        $this->assertSame($payrollItems, DB::table('payroll_items')->count());
        $this->assertSame($leaveRequests, DB::table('leave_requests')->count());
        $this->assertSame(0, DB::table('employees')->where('tenant_id', $createdId)->count());
        $this->assertSame(1, DB::table('company_lifecycle_events')->where('tenant_id', $createdId)->where('action', 'reactivated')->count());
        $this->browseAsGuest();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);

        $this->post('/invitations/accept', ['token' => 'plain-token-must-stay-hidden', 'password' => 'new-password'])->assertNotFound();
        $this->assertNull($pending->fresh()->invite_accepted_at);
    }

    public function test_failed_onboarding_rolls_back_the_company(): void
    {
        CompanyFixture::make('Northwind', 'northwind');
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();
        $service = new class extends CompanyOnboarding
        {
            protected function record(User $actor, int $companyId, string $action): void
            {
                throw new RuntimeException('audit failed');
            }
        };

        try {
            $service->create($platform, [
                'name' => 'Rollback Co',
                'slug' => 'rollback-co',
                'timezone' => 'Asia/Kolkata',
                'week_off' => ['sunday'],
            ]);
            $this->fail('The onboarding transaction should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        }

        $this->assertDatabaseMissing('tenants', ['slug' => 'rollback-co']);
    }

    private function browseAsGuest(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(): array
    {
        return [
            'name' => 'North Wind',
            'slug' => 'north-wind',
            'timezone' => 'Asia/Kolkata',
            'email' => 'hello@north-wind.test',
            'phone' => '9000000000',
            'address' => '1 Harbour road',
            'week_off' => ['saturday', 'sunday'],
        ];
    }
}
