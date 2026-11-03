<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitationMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\MailTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class ZeptoMailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_invitation_and_resend_use_the_branded_template(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix-mail');
        Mail::fake();

        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-MAIL', 'mail.invite@example.test'))
            ->assertRedirect()
            ->assertSessionHas('notice_tone', 'success');

        $employeeId = (int) DB::table('employees')->where('email', 'mail.invite@example.test')->value('id');
        $this->actingAs($company->hr)->post('/employees/'.$employeeId.'/invitation')
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'previous link no longer works'))
            ->assertSessionHas('notice_tone', 'success');

        Mail::assertSent(EmployeeInvitationMail::class, 2);
        Mail::assertSent(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('mail.invite@example.test')
                && str_contains($html, 'Veltrix HRMS')
                && str_contains($html, 'Veltrix Digital Solutions Inc')
                && str_contains($html, 'New Person')
                && str_contains($html, '72 hours')
                && str_contains($html, 'Set your password');
        });
        $this->assertNotNull(DB::table('users')->where('email', 'mail.invite@example.test')->value('invite_sent_at'));
    }

    public function test_failed_smtp_delivery_keeps_the_invitation_pending_and_hides_secrets(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix-fail');
        $logged = '';
        Log::listen(function ($message) use (&$logged): void {
            $logged .= $message->message.json_encode($message->context);
        });
        $pending = \Mockery::mock();
        $pending->shouldReceive('send')->andThrow(new RuntimeException('smtp password sample-secret invite-token-secret'));
        Mail::shouldReceive('to')->andReturn($pending);

        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-FAIL', 'fail.mail@example.test'))
            ->assertRedirect()
            ->assertSessionHas('notice_tone', 'error')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'could not be sent'));

        $login = DB::table('users')->where('email', 'fail.mail@example.test')->first();
        $this->assertNull($login->invite_sent_at);
        $this->assertNull($login->invite_token);
        $this->assertSame('invited', DB::table('employees')->where('email', 'fail.mail@example.test')->value('status'));
        $this->assertStringNotContainsString('sample-secret', $logged);
        $this->assertStringNotContainsString('invite-token-secret', $logged);
        $this->assertStringContainsString('delivery', $logged);
    }

    public function test_invalid_smtp_settings_do_not_send_or_mark_the_invitation_sent(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix-config');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.zeptomail.in',
            'mail.mailers.smtp.username' => 'emailapikey',
            'mail.mailers.smtp.password' => '',
            'mail.from.address' => 'hr@veltrixds.com',
            'mail.sender_domain' => 'veltrixds.com',
        ]);
        Mail::fake();

        $this->assertNotNull(app(MailTransport::class)->problem());
        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-CFG', 'config.mail@example.test'))
            ->assertSessionHas('notice_tone', 'error')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'not sent'));

        Mail::assertNothingSent();
        $this->assertNull(DB::table('users')->where('email', 'config.mail@example.test')->value('invite_sent_at'));
        $this->assertSame('invited', DB::table('employees')->where('email', 'config.mail@example.test')->value('status'));

        config([
            'mail.mailers.smtp.password' => 'sample-secret',
            'mail.from.address' => 'person@gmail.com',
        ]);
        $this->assertStringContainsString('veltrixds.com', (string) app(MailTransport::class)->problem());
        $this->assertStringNotContainsString('sample-secret', (string) app(MailTransport::class)->problem());
    }

    public function test_password_reset_mail_is_branded_and_a_delivery_failure_hides_the_token(): void
    {
        $user = User::factory()->create(['name' => 'Asha Rao']);
        $mail = (new ResetPassword('reset-token-value'))->toMail($user);
        $this->assertInstanceOf(PasswordResetMail::class, $mail);
        $html = $mail->render();
        $this->assertStringContainsString('Veltrix HRMS', $html);
        $this->assertStringContainsString('Asha Rao', $html);
        $this->assertStringContainsString('60 minutes', $html);
        $this->assertStringContainsString('Reset your password', $html);

        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_password_reset_transport_failure_does_not_log_the_token_or_password(): void
    {
        $user = User::factory()->create();
        $logged = '';
        Log::listen(function ($message) use (&$logged): void {
            $logged .= $message->message.json_encode($message->context);
        });
        Password::shouldReceive('sendResetLink')->once()->andThrow(new RuntimeException('reset-token-secret mailbox password sample-secret'));

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasErrors('email');

        $this->assertStringNotContainsString('reset-token-secret', $logged);
        $this->assertStringNotContainsString('sample-secret', $logged);
    }

    public function test_invitation_acceptance_login_and_payroll_access_stay_unchanged(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix-keep');
        Mail::fake();
        $email = 'accept.mail@example.test';
        $this->actingAs($company->hr)->post('/employees', $this->payload($company, 'VEL-ACC', $email))->assertRedirect();
        $token = null;
        $hash = (string) DB::table('users')->where('email', $email)->value('invite_token');
        Mail::assertSent(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail) use ($email, $hash, &$token) {
            if ($mail->hasTo($email) && hash('sha256', $mail->token) === $hash) {
                $token = $mail->token;
            }

            return true;
        });
        Auth::logout();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77']);
        $this->post('/employee-invitations/'.$token, [
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertRedirect(route('dashboard'));
        $this->assertSame('active', DB::table('employees')->where('email', $email)->value('status'));
        $this->assertTrue(Hash::check('Password1', (string) DB::table('users')->where('email', $email)->value('password')));

        Auth::logout();
        $this->post('/login', ['email' => $company->employee->email, 'password' => 'password'])->assertRedirect('/dashboard');
        Mail::fake();
        $this->actingAs($company->employee)->get('/payroll/imports/'.$company->payrollRunId)->assertNotFound();
        $this->actingAs($company->hr)->get('/payslips')->assertOk();
        Mail::assertNothingSent();
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
