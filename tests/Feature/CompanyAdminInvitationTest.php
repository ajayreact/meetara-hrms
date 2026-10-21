<?php

namespace Tests\Feature;

use App\Logging\RedactSecrets;
use App\Mail\CompanyAdminInvitationMail;
use App\Models\User;
use App\Services\Hrms\CompanyAdminInvitation;
use App\Services\Hrms\CompanyOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class CompanyAdminInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_super_admin_invitation_is_hashed_single_use_and_hidden_from_other_roles(): void
    {
        $company = CompanyFixture::make('Northwind', 'northwind');
        $platform = $this->platformAdmin();
        $existingToken = (string) DB::table('users')->where('id', $company->employee->id)->value('invite_token');
        $rolesBefore = DB::table('model_has_roles')->where('model_id', $company->employee->id)->count();

        $this->actingAs($company->hr)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test'])->assertForbidden();
        $this->actingAs($company->lead)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test'])->assertForbidden();
        $this->actingAs($company->employee)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test'])->assertForbidden();
        $this->assertSame(0, DB::table('company_admin_invitations')->count());

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', [
            'email' => 'Admin@Northwind.test',
            'role' => 'Owner',
            'password' => 'secret-password',
            'tenant_id' => $company->id,
        ])->assertSessionHasErrors(['role', 'password', 'tenant_id']);

        $created = $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', [
            'email' => 'Admin@Northwind.test',
        ]);
        $created->assertRedirect();
        $created->assertSessionHas('status', 'Invitation email sent.');
        $token = $this->latestToken();
        $this->assertNotSame('', $token);
        $created->assertDontSee($token);
        $invitation = DB::table('company_admin_invitations')->first();
        $this->assertNotNull($invitation);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertSame('admin@northwind.test', $invitation->email);
        $this->assertSame('Owner', $invitation->role_name);
        $this->assertSame((int) $platform->id, (int) $invitation->invited_by);
        $this->assertNull($invitation->accepted_at);
        $this->assertStringNotContainsString($token, json_encode($invitation));
        $this->assertSame(0, DB::table('users')->where('email', 'admin@northwind.test')->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('tenant_id', $company->id)->where('role_id', $this->ownerRole($company->id))->count());
        $this->assertSame($existingToken, (string) DB::table('users')->where('id', $company->employee->id)->value('invite_token'));
        $this->assertSame($rolesBefore, DB::table('model_has_roles')->where('model_id', $company->employee->id)->count());
        $this->assertDatabaseHas('company_lifecycle_events', [
            'tenant_id' => $company->id,
            'actor_id' => $platform->id,
            'action' => 'invite_created',
            'subject_email' => 'admin@northwind.test',
        ]);

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', [
            'email' => 'other@northwind.test',
        ])->assertSessionHasErrors('email');
        $this->assertSame(1, DB::table('company_admin_invitations')->count());

        $this->actingAs($platform)->get('/companies?company='.$company->id)
            ->assertOk()
            ->assertSee('admin@northwind.test')
            ->assertDontSee($token);
        $this->actingAs($platform)->get('/audit?source=Company')
            ->assertOk()
            ->assertSee('admin@northwind.test')
            ->assertDontSee($token);
        $this->actingAs($company->employee)->get('/companies')->assertOk()->assertDontSee('admin@northwind.test');

        $other = CompanyFixture::make('Southwind', 'southwind');
        $this->actingAs($platform)->post('/companies/'.$other->id.'/invitations/'.$invitation->id.'/resend')->assertNotFound();
        $this->actingAs($other->hr)->post('/companies/'.$company->id.'/invitations/'.$invitation->id.'/resend')->assertForbidden();
    }

    public function test_resend_revocation_expiry_and_replay_keep_one_company_admin(): void
    {
        $company = CompanyFixture::make('Northwind', 'northwind');
        $platform = $this->platformAdmin();
        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test'])->assertSessionHas('status');
        $first = $this->latestToken();
        $invitationId = (int) DB::table('company_admin_invitations')->value('id');

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations/'.$invitationId.'/resend')->assertSessionHas('status', 'Invitation email sent.');
        $second = $this->latestToken();
        $this->assertNotSame($first, $second);
        $this->assertSame(hash('sha256', $second), DB::table('company_admin_invitations')->value('token_hash'));
        $this->guest();
        $this->get('/invitations/accept/'.$first)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'invalid')->missing('email'));
        $this->get('/invitations/accept/'.$second)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('state', 'ready')
            ->where('email', 'admin@northwind.test')
            ->missing('token'));

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations/'.$invitationId.'/revoke', [])->assertSessionHasErrors('confirm');
        $this->assertNull(DB::table('company_admin_invitations')->value('revoked_at'));
        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations/'.$invitationId.'/revoke', ['confirm' => 'REVOKE'])->assertSessionHas('status', 'Invitation revoked.');
        $this->guest();
        $this->get('/invitations/accept/'.$second)->assertInertia(fn (Assert $page) => $page->where('state', 'revoked')->missing('email'));
        $this->assertSame('revoked', app(CompanyAdminInvitation::class)->summary($company->id)['state']);

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test'])->assertSessionHas('status');
        $token = $this->latestToken();
        $this->travel(169)->hours();
        $this->guest();
        $this->get('/invitations/accept/'.$token)->assertInertia(fn (Assert $page) => $page->where('state', 'expired')->missing('email'));
        $this->post('/invitations/accept/'.$token, $this->acceptance())->assertSessionHasErrors('invitation');
        $this->assertSame(0, DB::table('users')->where('email', 'admin@northwind.test')->count());

        app(CompanyOnboarding::class)->transition($platform, $company->id, 'inactive');
        app(CompanyOnboarding::class)->transition($platform, $company->id, 'active');
        $this->post('/invitations/accept/'.$token, $this->acceptance())->assertSessionHasErrors('invitation');
        $this->assertSame('expired', app(CompanyAdminInvitation::class)->summary($company->id)['state']);

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations/'.DB::table('company_admin_invitations')->orderByDesc('id')->value('id').'/resend')
            ->assertSessionHas('status');
        $fresh = $this->latestToken();
        $this->guest();
        $this->post('/invitations/accept/not-a-token', $this->acceptance())->assertNotFound();
        $this->post('/invitations/accept/'.$fresh, [
            ...$this->acceptance(),
            'role' => 'Owner',
            'email' => 'someone-else@northwind.test',
            'tenant_id' => 999,
        ])->assertSessionHasErrors(['role', 'email', 'tenant_id']);
        $this->assertSame(0, DB::table('users')->where('email', 'admin@northwind.test')->count());

        $this->post('/invitations/accept/'.$fresh, $this->acceptance())->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $admin = User::query()->where('email', 'admin@northwind.test')->firstOrFail();
        $this->assertSame($company->id, (int) $admin->tenant_id);
        $this->assertFalse($admin->isPlatformAdmin());
        $this->assertNull($admin->invite_token);
        $this->assertNotNull($admin->invite_accepted_at);
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $admin->id)->where('tenant_id', $company->id)->count());
        $accepted = DB::table('company_admin_invitations')->where('email', 'admin@northwind.test')->whereNull('revoked_at')->first();
        $this->assertNotNull($accepted->accepted_at);
        $this->assertNotNull($accepted->token_hash);
        $this->assertNotSame($fresh, $accepted->token_hash);
        $this->assertDatabaseHas('company_lifecycle_events', [
            'action' => 'invite_accepted',
            'subject_email' => 'admin@northwind.test',
            'actor_id' => $admin->id,
        ]);

        Cache::flush();
        $this->guest();
        $this->post('/invitations/accept/'.$fresh, $this->acceptance())->assertSessionHasErrors('invitation');
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $admin->id)->count());
        $this->assertSame(1, DB::table('users')->where('email', 'admin@northwind.test')->count());
        $this->travelBack();
    }

    public function test_inactive_companies_and_existing_accounts_cannot_be_taken_over(): void
    {
        $company = CompanyFixture::make('Northwind', 'northwind');
        $platform = $this->platformAdmin();
        $employeeRoles = DB::table('model_has_roles')->where('model_id', $company->employee->id)->value('role_id');

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', [
            'email' => $company->employee->email,
        ])->assertSessionHasErrors('email');
        $this->assertSame($employeeRoles, DB::table('model_has_roles')->where('model_id', $company->employee->id)->value('role_id'));
        $this->assertSame(0, DB::table('company_admin_invitations')->count());

        config(['mail.default' => 'log', 'mail.from.address' => 'hello@example.com']);
        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test'])
            ->assertSessionHasErrors(['email' => 'Mail delivery is not configured. The invitation was not created.']);
        $this->assertSame(0, DB::table('company_admin_invitations')->count());
        config(['mail.default' => 'array', 'mail.from.address' => 'hello@example.com']);

        $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test'])->assertSessionHas('status');
        $token = $this->latestToken();
        app(CompanyOnboarding::class)->transition($platform, $company->id, 'inactive');
        $this->actingAs($platform)->from('/companies?company='.$company->id)->post('/companies/'.$company->id.'/invitations', ['email' => 'second@northwind.test'])
            ->assertSessionHasErrors('email');
        $this->guest();
        $this->post('/invitations/accept/'.$token, $this->acceptance())->assertSessionHasErrors(['invitation' => 'This invitation cannot be accepted while the company is inactive.']);
        $this->assertSame(0, DB::table('users')->where('email', 'admin@northwind.test')->count());
        $this->assertNotNull(DB::table('company_admin_invitations')->value('token_hash'));

        app(CompanyOnboarding::class)->transition($platform, $company->id, 'active');
        $this->post('/invitations/accept/'.$token, $this->acceptance())->assertRedirect('/dashboard');
        $this->assertSame(1, DB::table('users')->where('email', 'admin@northwind.test')->count());
    }

    public function test_mail_failure_clears_the_token_and_does_not_log_it(): void
    {
        $company = CompanyFixture::make('Northwind', 'northwind');
        $platform = $this->platformAdmin();
        $spy = new class extends CompanyAdminInvitation
        {
            public string $raw = '';

            protected function deliver(string $email, string $token, string $companyName): void
            {
                $this->raw = $token;
                throw new RuntimeException('smtp failed');
            }
        };
        $this->app->instance(CompanyAdminInvitation::class, $spy);
        $response = $this->actingAs($platform)->post('/companies/'.$company->id.'/invitations', ['email' => 'admin@northwind.test']);
        $response->assertSessionHasErrors(['email' => 'The invitation email was not sent. Use resend when mail delivery is working.']);
        $this->assertNotSame('', $spy->raw);
        $this->assertNull(DB::table('company_admin_invitations')->value('token_hash'));
        $this->assertSame(0, DB::table('users')->where('email', 'admin@northwind.test')->count());
        $this->get('/companies?company='.$company->id)->assertDontSee($spy->raw);
        $this->assertSame(
            '/invitations/accept/[redacted]',
            RedactSecrets::redact('/invitations/accept/'.$spy->raw)
        );
        $this->assertStringNotContainsString($spy->raw, json_encode(DB::table('company_lifecycle_events')->get()));
    }

    public function test_acceptance_is_rate_limited(): void
    {
        $payload = $this->acceptance();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/invitations/accept/'.str_repeat('ab', 32), $payload)->assertSessionHasErrors();
        }

        $this->post('/invitations/accept/'.str_repeat('ab', 32), $payload)->assertStatus(429);
    }

    private function guest(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    /**
     * @return array<string, string>
     */
    private function acceptance(): array
    {
        return [
            'name' => 'North Admin',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ];
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'invite_accepted_at' => now()])->save();

        return $user->refresh();
    }

    private function latestToken(): string
    {
        $mail = Mail::sent(CompanyAdminInvitationMail::class)->last();
        $this->assertInstanceOf(CompanyAdminInvitationMail::class, $mail);

        return $mail->token;
    }

    private function ownerRole(int $companyId): int
    {
        return (int) DB::table('roles')->where('tenant_id', $companyId)->where('name', 'Owner')->value('id');
    }
}
