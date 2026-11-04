<?php

namespace Tests\Feature;

use App\Auth\CompanyRole;
use App\Mail\EmployeeInvitationMail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_and_company_admin_create_a_pending_login_and_send_the_invitation(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        Mail::fake();

        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-HR', 'hr.invite@example.test'))
            ->assertRedirect();
        $this->actingAs($owner)->post('/employees', $this->payload($company, 'VEL-OWN', 'admin.invite@example.test'))
            ->assertRedirect();

        foreach (['hr.invite@example.test', 'admin.invite@example.test'] as $email) {
            $employee = DB::table('employees')->where('email', $email)->first();
            $login = DB::table('users')->where('email', $email)->first();
            $this->assertNotNull($employee);
            $this->assertSame('invited', $employee->status);
            $this->assertSame($company->id, (int) $employee->tenant_id);
            $this->assertNotNull($login->invited_at);
            $this->assertNull($login->invite_accepted_at);
            $this->assertNotNull($login->invite_sent_at);
            $this->assertNotNull($login->invite_expires_at);

            Mail::assertSent(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail) use ($email, $login) {
                return $mail->hasTo($email)
                    && $mail->companyName === 'Veltrix Digital Solutions Inc'
                    && hash('sha256', $mail->token) === $login->invite_token
                    && $mail->token !== $login->invite_token
                    && str_contains($mail->render(), $mail->employeeName)
                    && str_contains($mail->render(), 'Veltrix Digital Solutions Inc');
            });
        }
    }

    public function test_unconfigured_mail_keeps_the_invitation_pending_without_claiming_delivery(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-LOG', 'log.invite@example.test'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Mail delivery is not configured'));

        Mail::assertNothingSent();
        $login = DB::table('users')->where('email', 'log.invite@example.test')->first();
        $this->assertNull($login->invite_token);
        $this->assertSame('invited', DB::table('employees')->where('email', 'log.invite@example.test')->value('status'));
    }

    public function test_a_valid_invitation_sets_the_password_and_activates_only_that_employee(): void
    {
        [$company, $other, $token, $email] = $this->invitedEmployee();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
        $this->get('/employee-invitations/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-employee-invitation')
                ->where('state', 'ready')
                ->where('email', $email)
                ->where('companyName', 'Veltrix Digital Solutions Inc'));

        $this->post('/login', ['email' => $email, 'password' => 'Password1'])->assertSessionHasErrors('email');

        $this->post('/employee-invitations/'.$token, [
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');
        $this->post('/employee-invitations/'.$token, [
            'password' => 'Password1',
            'password_confirmation' => 'Different1',
        ])->assertSessionHasErrors('password');
        $this->post('/employee-invitations/'.$token, [
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'employee_id' => $other->reportEmployeeId,
        ])->assertSessionHasErrors('employee_id');

        $this->assertSame('invited', DB::table('employees')->where('email', $email)->value('status'));
        $this->assertSame('active', DB::table('employees')->where('id', $other->reportEmployeeId)->value('status'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21']);
        $this->post('/employee-invitations/'.$token, [
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Welcome to Veltrix Digital Solutions Inc'));

        $login = DB::table('users')->where('email', $email)->first();
        $this->assertNull($login->invite_token);
        $this->assertNotNull($login->invite_accepted_at);
        $this->assertTrue(Hash::check('Password1', $login->password));
        $this->assertSame('active', DB::table('employees')->where('email', $email)->value('status'));
        $this->assertSame('active', DB::table('employees')->where('id', $other->reportEmployeeId)->value('status'));
        $this->assertSame('Employee', DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $login->id)
            ->value('roles.name'));

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/employee')
            ->where('notice', fn (string $notice) => str_contains($notice, 'Welcome')));

        Auth::logout();
        $this->post('/login', ['email' => $email, 'password' => 'Password1'])->assertRedirect('/dashboard');
        Auth::logout();

        $this->post('/employee-invitations/'.$token, [
            'password' => 'Password2a',
            'password_confirmation' => 'Password2a',
        ])->assertSessionHasErrors('invitation');
        $this->assertTrue(Hash::check('Password1', DB::table('users')->where('email', $email)->value('password')));
    }

    public function test_expired_and_replaced_invitations_are_rejected(): void
    {
        [$company, , $token, $email] = $this->invitedEmployee();

        $this->travel(73)->hours();
        $this->get('/employee-invitations/'.$token)->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
        $this->post('/employee-invitations/'.$token, [
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertSessionHasErrors('invitation');
        $this->assertSame('invited', DB::table('employees')->where('email', $email)->value('status'));
        $this->travelBack();

        $this->actingAs($company->hr)->post('/employees/'.DB::table('employees')->where('email', $email)->value('id').'/invitation')
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'previous link no longer works'));

        Auth::logout();
        $this->get('/employee-invitations/'.$token)->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
        $replacement = $this->tokenFor($email);
        $this->get('/employee-invitations/'.$replacement)->assertInertia(fn (Assert $page) => $page->where('state', 'ready'));
        $this->assertNotSame(hash('sha256', $token), DB::table('users')->where('email', $email)->value('invite_token'));
    }

    public function test_delivery_failure_does_not_leave_a_usable_token_or_log_it(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $logged = '';
        Log::listen(function ($message) use (&$logged): void {
            $logged .= $message->message.json_encode($message->context);
        });
        $pending = \Mockery::mock();
        $pending->shouldReceive('send')->andThrow(new \RuntimeException('smtp unavailable'));
        Mail::shouldReceive('to')->andReturn($pending);

        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-FAIL', 'fail.invite@example.test'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'could not be sent'));

        $this->assertNull(DB::table('users')->where('email', 'fail.invite@example.test')->value('invite_token'));
        $this->assertSame('invited', DB::table('employees')->where('email', 'fail.invite@example.test')->value('status'));
        $this->assertStringNotContainsString('smtp unavailable', $logged);
        $this->assertStringNotContainsString('invite_token', $logged);
    }

    public function test_unauthorized_users_cannot_resend_and_another_company_cannot_use_the_link(): void
    {
        [$company, $other, $token, $email] = $this->invitedEmployee();
        $employeeId = (int) DB::table('employees')->where('email', $email)->value('id');

        $this->actingAs($company->employee)->post('/employees/'.$employeeId.'/invitation')->assertNotFound();
        $this->actingAs($company->lead)->post('/employees/'.$employeeId.'/invitation')->assertNotFound();
        $this->actingAs($other->hr)->post('/employees/'.$employeeId.'/invitation')->assertNotFound();

        Auth::logout();
        $this->get('/employee-invitations/'.str_repeat('ab', 32))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
        $this->assertNotSame($token, str_repeat('ab', 32));
        $this->assertSame('invited', DB::table('employees')->where('email', $email)->value('status'));
        $this->assertSame('active', DB::table('employees')->where('id', $other->reportEmployeeId)->value('status'));
    }

    public function test_password_reset_uses_configured_mail_and_does_not_send_when_unconfigured(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        Notification::fake();

        $this->post('/forgot-password', ['email' => $company->employee->email])->assertSessionHas('status');
        Notification::assertSentTo($company->employee, ResetPassword::class, function (ResetPassword $notification) use ($company) {
            $this->get('/reset-password/'.$notification->token)->assertOk();
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $company->employee->email,
                'password' => 'Password1',
                'password_confirmation' => 'Password1',
            ])->assertRedirect(route('login'));

            return true;
        });
        $this->assertTrue(Hash::check('Password1', $company->employee->refresh()->password));

        config(['mail.default' => 'log']);
        Notification::fake();
        $this->post('/forgot-password', ['email' => $company->employee->email])
            ->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_an_inactive_employee_session_ends_without_signing_out_hr(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');

        foreach (['inactive', 'deactivated', 'terminated', 'exited', 'suspended'] as $status) {
            $this->post('/login', [
                'email' => $company->employee->email,
                'password' => 'password',
            ])->assertRedirect('/dashboard');
            DB::table('employees')->where('id', $company->reportEmployeeId)->update(['status' => $status]);
            $this->get('/dashboard')->assertRedirect(route('login'));
            $this->assertGuest();
            DB::table('employees')->where('id', $company->reportEmployeeId)->update(['status' => 'active']);
        }

        $this->actingAs($company->hr)->get('/employees')->assertOk();
        DB::table('tenants')->where('id', $company->id)->update(['status' => 'inactive']);
        $this->actingAs($company->hr)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_role_payroll_and_payslip_permissions_stay_unchanged(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        $accounts = $this->owner($company);
        DB::table('model_has_roles')->where('model_id', $accounts->id)->delete();
        $accountsRole = DB::table('roles')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $accountsRole,
            'model_type' => User::class,
            'model_id' => $accounts->id,
            'tenant_id' => $company->id,
        ]);

        $this->actingAs($owner)->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('options.roles', function ($roles) {
                $values = collect($roles)->map(fn ($role) => is_array($role) ? ($role['value'] ?? null) : $role);

                return $values->contains('Owner')
                    && ! $values->contains('Accounts')
                    && $values->contains('Employee')
                    && $values->contains('HR')
                    && $values->contains('Manager')
                    && $values->contains('Company Manager');
            }));
        $this->actingAs($company->hr)->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('options.roles', function ($roles) {
                $values = collect($roles)->pluck('value');

                return $values->contains('Employee')
                    && $values->contains('HR')
                    && $values->contains('Manager')
                    && ! $values->contains('Owner')
                    && ! $values->contains('Accounts');
            }));

        $this->actingAs($company->hr)->post('/employees', [
            ...$this->payload($company, 'VEL-BAD', 'bad.role@example.test'),
            'role' => 'Owner',
        ])->assertSessionHasErrors('role');
        $this->actingAs($accounts)->post('/employees', $this->payload($company, 'VEL-ACC', 'accounts.invite@example.test'))->assertForbidden();

        $this->assertFalse(CompanyRole::permissions('hr')['payroll']);
        $this->assertTrue(CompanyRole::permissions('hr')['payrollImport']);
        $this->assertTrue(CompanyRole::permissions('accounts')['payroll']);
        $this->assertFalse(CompanyRole::permissions('employee')['payroll']);
        $this->assertTrue(CompanyRole::permissions('company_admin')['payrollImport']);

        $this->actingAs($company->hr)->get('/payroll')->assertOk();
        $this->actingAs($company->hr)->get('/payments')->assertForbidden();
        $this->actingAs($company->hr)->get('/payslips')->assertOk();
        $this->actingAs($owner)->get('/payroll')->assertOk();
        $this->actingAs($owner)->get('/payments')->assertOk();
        $this->actingAs($owner)->get('/payslips')->assertOk();
        $this->actingAs($accounts)->get('/payroll')->assertOk();
        $this->actingAs($accounts)->get('/payslips')->assertOk();
        $this->actingAs($company->employee)->get('/payroll')->assertForbidden();
        $this->actingAs($company->employee)->get('/payslips')->assertOk();
        $this->actingAs($company->lead)->get('/payroll')->assertForbidden();
        $this->actingAs($company->lead)->get('/payslips')->assertOk();
    }

    public function test_invitation_acceptance_is_rate_limited(): void
    {
        $token = str_repeat('a', 64);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
                ->get('/employee-invitations/'.$token)
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->get('/employee-invitations/'.$token)
            ->assertStatus(429);
    }

    /**
     * @return array{0: CompanyFixture, 1: CompanyFixture, 2: string, 3: string}
     */
    private function invitedEmployee(): array
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        Mail::fake();
        $email = 'person.invite@example.test';
        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-ONE', $email))->assertRedirect();
        Auth::logout();

        return [$company, $other, $this->tokenFor($email), $email];
    }

    private function tokenFor(string $email): string
    {
        $hash = (string) DB::table('users')->where('email', $email)->value('invite_token');
        $token = null;
        Mail::assertSent(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail) use ($email, $hash, &$token) {
            if ($mail->hasTo($email) && hash('sha256', $mail->token) === $hash) {
                $token = $mail->token;
            }

            return true;
        });
        $this->assertNotNull($token);

        return $token;
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

    /**
     * @return array<string, mixed>
     */
    private function payload(CompanyFixture $company, string $code, string $email): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => $email,
            'phone' => '5550100',
            'employee_code' => $code,
            'department_id' => $company->departmentId,
            'date_of_joining' => '2026-02-01',
            'holiday_calendar' => 'india',
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'create_login' => true,
            'role' => 'Employee',
        ];
    }
}
