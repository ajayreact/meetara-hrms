<?php

namespace App\Services\Hrms;

use App\Mail\CompanyAdminInvitationMail;
use App\Models\User;
use App\Support\MailTransport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompanyAdminInvitation
{
    public const ROLE = 'Owner';

    /**
     * @return array{sent: bool, message: string}
     */
    public function invite(User $actor, int $companyId, string $email): array
    {
        $this->authorize($actor);
        $email = $this->normalizeEmail($email);
        if (! $this->mailIsConfigured()) {
            return ['sent' => false, 'message' => 'Mail delivery is not configured. The invitation was not created.'];
        }
        $this->storageAvailable();

        $token = $this->token();
        $invitationId = 0;
        $companyName = '';

        try {
            DB::transaction(function () use ($actor, $companyId, $email, $token, &$invitationId, &$companyName): void {
                $company = $this->lockCompany($companyId);
                $this->assertCanInvite($companyId, $email);
                $companyName = (string) $company->name;
                $invitationId = DB::table('company_admin_invitations')->insertGetId([
                    'tenant_id' => $companyId,
                    'email' => $email,
                    'token_hash' => $this->hash($token),
                    'invited_by' => $actor->id,
                    'role_name' => self::ROLE,
                    'expires_at' => now()->addHours($this->hours()),
                    'sent_at' => null,
                    'active_key' => $this->activeKey($companyId, $email),
                    'company_open_key' => (string) $companyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit((int) $actor->id, $companyId, 'invite_created', $email);
            });
        } catch (QueryException $exception) {
            $this->reraiseDuplicate($exception);
        }

        return $this->finishDelivery((int) $actor->id, $invitationId, $companyId, $email, $token, $companyName, false);
    }

    /**
     * @return array{sent: bool, message: string}
     */
    public function resend(User $actor, int $companyId, int $invitationId): array
    {
        $this->authorize($actor);
        if (! $this->mailIsConfigured()) {
            return ['sent' => false, 'message' => 'Mail delivery is not configured. The previous invitation was left unchanged.'];
        }
        $this->storageAvailable();

        $token = $this->token();
        $email = '';
        $companyName = '';

        DB::transaction(function () use ($companyId, $invitationId, $token, &$email, &$companyName): void {
            $company = $this->lockCompany($companyId);
            $invitation = $this->lockInvitation($companyId, $invitationId);
            $this->assertOpen($invitation);
            if ((string) $company->status !== 'active') {
                throw ValidationException::withMessages([
                    'email' => 'Activate the company before resending a Head of Operations invitation.',
                ]);
            }
            $email = (string) $invitation->email;
            $companyName = (string) $company->name;
            DB::table('company_admin_invitations')->where('id', $invitation->id)->update([
                'token_hash' => $this->hash($token),
                'expires_at' => now()->addHours($this->hours()),
                'sent_at' => null,
                'updated_at' => now(),
            ]);
        });

        return $this->finishDelivery((int) $actor->id, $invitationId, $companyId, $email, $token, $companyName, true);
    }

    public function revoke(User $actor, int $companyId, int $invitationId): void
    {
        $this->authorize($actor);
        $this->storageAvailable();

        DB::transaction(function () use ($actor, $companyId, $invitationId): void {
            $invitation = $this->lockInvitation($companyId, $invitationId);
            if ($invitation->accepted_at !== null) {
                throw ValidationException::withMessages([
                    'confirm' => 'An accepted invitation cannot be revoked.',
                ]);
            }
            if ($invitation->revoked_at !== null) {
                return;
            }

            DB::table('company_admin_invitations')->where('id', $invitation->id)->update([
                'revoked_at' => now(),
                'active_key' => null,
                'company_open_key' => null,
                'updated_at' => now(),
            ]);
            $this->audit((int) $actor->id, $companyId, 'invite_revoked', (string) $invitation->email);
        });
    }

    /**
     * @return array{state: string, email?: string, companyName?: string}
     */
    public function describe(string $token): array
    {
        $invitation = $this->findByToken($token);
        if ($invitation === null) {
            return ['state' => 'invalid'];
        }

        $state = $this->state($invitation);
        if ($state !== 'pending') {
            return ['state' => $state];
        }

        if (! $this->companyIsActive((int) $invitation->tenant_id)) {
            return ['state' => 'inactive'];
        }

        return [
            'state' => 'ready',
            'email' => (string) $invitation->email,
            'companyName' => (string) DB::table('tenants')->where('id', $invitation->tenant_id)->value('name'),
        ];
    }

    public function provisionOwner(User $actor, int $companyId, string $name, string $email, string $password): User
    {
        $this->authorize($actor);
        $email = $this->normalizeEmail($email);
        if ($email === '' || trim($name) === '' || $password === '') {
            throw ValidationException::withMessages([
                'admin_email' => 'Enter the Head of Operations name, email, and password together.',
            ]);
        }
        if ($this->emailTaken($email)) {
            throw ValidationException::withMessages([
                'admin_email' => 'This email is already registered. Company creation cannot change an existing account.',
            ]);
        }

        $user = new User;
        $user->forceFill([
            'name' => trim($name),
            'email' => $email,
            'password' => $password,
            'tenant_id' => $companyId,
            'is_platform_admin' => false,
            'invite_token' => null,
            'invited_at' => null,
            'invite_accepted_at' => now(),
            'email_verified_at' => now(),
        ]);

        try {
            $user->save();
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'admin_email' => 'This email is already registered. Company creation cannot change an existing account.',
            ]);
        }

        $this->assignOwner((int) $actor->id, $user, $companyId);
        $this->audit((int) $actor->id, $companyId, 'admin_created', $email);

        return $user;
    }

    public function accept(string $token, string $name, string $password): User
    {
        if (! $this->validTokenShape($token) || ! Schema::hasTable('company_admin_invitations')) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation link is not valid.',
            ]);
        }

        return DB::transaction(function () use ($token, $name, $password): User {
            $invitation = DB::table('company_admin_invitations')
                ->where('token_hash', $this->hash($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation link is not valid.',
                ]);
            }

            $state = $this->state($invitation);
            if ($state === 'accepted') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation has already been used.',
                ]);
            }
            if ($state === 'revoked') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation is no longer available.',
                ]);
            }
            if ($state === 'expired') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation has expired.',
                ]);
            }
            if ((string) $invitation->role_name !== self::ROLE) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation link is not valid.',
                ]);
            }
            if (! $this->companyIsActive((int) $invitation->tenant_id)) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation cannot be accepted while the company is inactive.',
                ]);
            }
            if ($this->emailTaken((string) $invitation->email)) {
                throw ValidationException::withMessages([
                    'invitation' => 'This email is already registered. An invitation cannot change an existing account.',
                ]);
            }

            $companyId = (int) $invitation->tenant_id;
            $user = new User;
            $user->forceFill([
                'name' => trim($name),
                'email' => (string) $invitation->email,
                'password' => $password,
                'tenant_id' => $companyId,
                'is_platform_admin' => false,
                'invite_token' => null,
                'invited_at' => null,
                'invite_accepted_at' => now(),
                'email_verified_at' => now(),
            ]);
            try {
                $user->save();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'invitation' => 'This email is already registered. An invitation cannot change an existing account.',
                ]);
            }

            $this->assignOwner((int) $invitation->invited_by, $user, $companyId);
            DB::table('company_admin_invitations')->where('id', $invitation->id)->update([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
                'active_key' => null,
                'company_open_key' => null,
                'updated_at' => now(),
            ]);
            $this->audit((int) $user->id, $companyId, 'invite_accepted', (string) $invitation->email);

            return $user;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summary(int $companyId): ?array
    {
        if ($companyId < 1 || ! Schema::hasTable('company_admin_invitations')) {
            return null;
        }

        $invitation = DB::table('company_admin_invitations')
            ->where('tenant_id', $companyId)
            ->orderByDesc('id')
            ->first();

        if ($invitation === null) {
            return null;
        }

        $state = $this->state($invitation);

        return [
            'id' => (int) $invitation->id,
            'email' => (string) $invitation->email,
            'state' => $state,
            'delivery' => $invitation->sent_at === null ? 'not_sent' : 'sent',
            'createdAt' => (string) $invitation->created_at,
            'expiresAt' => (string) ($invitation->expires_at ?? ''),
            'acceptedAt' => $invitation->accepted_at ? (string) $invitation->accepted_at : null,
            'revokedAt' => $invitation->revoked_at ? (string) $invitation->revoked_at : null,
        ];
    }

    public function mailIsConfigured(): bool
    {
        return app(MailTransport::class)->ready();
    }

    protected function deliver(string $email, string $token, string $companyName): void
    {
        Mail::to($email)->send(new CompanyAdminInvitationMail($token, $companyName));
    }

    /**
     * @return array{sent: bool, message: string}
     */
    private function finishDelivery(int $actorId, int $invitationId, int $companyId, string $email, string $token, string $companyName, bool $resent): array
    {
        try {
            $this->deliver($email, $token, $companyName);
        } catch (Throwable $exception) {
            DB::table('company_admin_invitations')->where('id', $invitationId)->update([
                'token_hash' => null,
                'sent_at' => null,
                'updated_at' => now(),
            ]);
            app(MailTransport::class)->logFailure('Company admin invitation', $exception, [
                'invitation_id' => $invitationId,
                'company_id' => $companyId,
            ]);
            $this->audit($actorId, $companyId, 'invite_unsent', $email);

            return ['sent' => false, 'message' => 'The invitation email was not sent. Use resend when mail delivery is working.'];
        }

        DB::table('company_admin_invitations')->where('id', $invitationId)->update([
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
        if ($resent) {
            $this->audit($actorId, $companyId, 'invite_resent', $email);
        }

        return ['sent' => true, 'message' => 'Invitation email sent.'];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isPlatformAdmin(), 403);
    }

    private function storageAvailable(): void
    {
        if (! Schema::hasTable('company_admin_invitations')) {
            throw ValidationException::withMessages([
                'email' => 'Invitation storage is not available until the invitation migration has been applied.',
            ]);
        }
    }

    private function lockCompany(int $companyId): object
    {
        $company = DB::table('tenants')->where('id', $companyId)->lockForUpdate()->first();
        abort_if($company === null, 404);

        return $company;
    }

    private function lockInvitation(int $companyId, int $invitationId): object
    {
        $invitation = DB::table('company_admin_invitations')
            ->where('id', $invitationId)
            ->where('tenant_id', $companyId)
            ->lockForUpdate()
            ->first();
        abort_if($invitation === null, 404);

        return $invitation;
    }

    private function assertCanInvite(int $companyId, string $email): void
    {
        $company = DB::table('tenants')->where('id', $companyId)->first();
        if ($company === null || (string) $company->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => 'Activate the company before inviting a Head of Operations.',
            ]);
        }
        if ($this->ownerExists($companyId) || $this->acceptedExists($companyId)) {
            throw ValidationException::withMessages([
                'email' => 'This company already has a Head of Operations.',
            ]);
        }
        if ($this->openExists($companyId)) {
            throw ValidationException::withMessages([
                'email' => 'A pending invitation already exists. Resend or revoke it before inviting another address.',
            ]);
        }
        if ($this->emailTaken($email)) {
            throw ValidationException::withMessages([
                'email' => 'This email is already registered. An invitation cannot change an existing account.',
            ]);
        }
    }

    private function assertOpen(object $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'email' => 'This invitation has already been used.',
            ]);
        }
        if ($invitation->revoked_at !== null) {
            throw ValidationException::withMessages([
                'email' => 'This invitation was revoked. Send a new invitation.',
            ]);
        }
    }

    private function ownerExists(int $companyId): bool
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.tenant_id', $companyId)
            ->where('roles.name', self::ROLE)
            ->exists();
    }

    private function acceptedExists(int $companyId): bool
    {
        return DB::table('company_admin_invitations')
            ->where('tenant_id', $companyId)
            ->whereNotNull('accepted_at')
            ->exists();
    }

    private function openExists(int $companyId): bool
    {
        return DB::table('company_admin_invitations')
            ->where('company_open_key', (string) $companyId)
            ->exists();
    }

    private function emailTaken(string $email): bool
    {
        return DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists();
    }

    private function companyIsActive(int $companyId): bool
    {
        return DB::table('tenants')->where('id', $companyId)->value('status') === 'active';
    }

    private function findByToken(string $token): ?object
    {
        if (! $this->validTokenShape($token) || ! Schema::hasTable('company_admin_invitations')) {
            return null;
        }

        return DB::table('company_admin_invitations')->where('token_hash', $this->hash($token))->first();
    }

    private function state(object $invitation): string
    {
        if ($invitation->accepted_at !== null) {
            return 'accepted';
        }
        if ($invitation->revoked_at !== null) {
            return 'revoked';
        }
        if ($invitation->expires_at !== null && now()->greaterThan(Carbon::parse($invitation->expires_at))) {
            return 'expired';
        }

        return 'pending';
    }

    private function assignOwner(int $inviterId, User $user, int $companyId): void
    {
        $roleId = DB::table('roles')
            ->where('tenant_id', $companyId)
            ->where('name', self::ROLE)
            ->where('guard_name', 'web')
            ->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => $companyId,
                'name' => self::ROLE,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $companyId,
        ]);

        if (Schema::hasTable('role_assignment_events')) {
            DB::table('role_assignment_events')->insert([
                'tenant_id' => $companyId,
                'actor_id' => $inviterId,
                'subject_user_id' => $user->id,
                'role_name' => self::ROLE,
                'action' => 'assigned',
                'created_at' => now(),
            ]);
        }
    }

    private function audit(int $actorId, int $companyId, string $action, string $email): void
    {
        if (! Schema::hasTable('company_lifecycle_events')) {
            return;
        }

        $row = [
            'tenant_id' => $companyId,
            'actor_id' => $actorId,
            'action' => $action,
            'created_at' => now(),
        ];
        if (Schema::hasColumn('company_lifecycle_events', 'subject_email')) {
            $row['subject_email'] = $email;
        }

        DB::table('company_lifecycle_events')->insert($row);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function validTokenShape(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function activeKey(int $companyId, string $email): string
    {
        return $companyId.'|'.$email;
    }

    private function hours(): int
    {
        return max(1, (int) config('hrms.company_admin_invitation_hours', 168));
    }

    private function reraiseDuplicate(QueryException $exception): never
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'company_open_key') || str_contains($message, 'active_key') || str_contains($message, 'UNIQUE')) {
            throw ValidationException::withMessages([
                'email' => 'A pending invitation already exists. Resend or revoke it before inviting another address.',
            ]);
        }

        throw $exception;
    }
}
