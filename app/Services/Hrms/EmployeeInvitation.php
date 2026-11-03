<?php

namespace App\Services\Hrms;

use App\Mail\EmployeeInvitationMail;
use App\Models\Employee;
use App\Models\User;
use App\Support\MailTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeInvitation
{
    /**
     * @return array{sent: bool, message: string}
     */
    public function send(User $actor, Employee $employee): array
    {
        abort_unless($actor->can('update', $employee), 403);

        if ($problem = app(MailTransport::class)->problem()) {
            return [
                'sent' => false,
                'message' => $problem.' The login stays invitation pending until you retry.',
            ];
        }

        $token = $this->token();
        $prepared = DB::transaction(function () use ($actor, $employee, $token): array {
            $companyId = (int) $actor->companyId();
            $locked = Employee::query()
                ->whereKey($employee->id)
                ->where('tenant_id', $companyId)
                ->lockForUpdate()
                ->first();
            abort_if($locked === null, 404);

            if ($locked->user_id === null) {
                throw ValidationException::withMessages([
                    'invitation' => 'Create a login before sending an invitation.',
                ]);
            }

            $user = User::query()
                ->whereKey($locked->user_id)
                ->where('tenant_id', $companyId)
                ->lockForUpdate()
                ->first();
            abort_if($user === null, 404);

            if ($user->invite_accepted_at !== null) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation has already been accepted.',
                ]);
            }

            $replaced = $user->invite_token !== null;
            $expires = now()->addHours($this->hours());
            $user->forceFill([
                'invite_token' => $this->hash($token),
                'invited_at' => now(),
                'invite_accepted_at' => null,
                'invite_expires_at' => $expires,
                'invite_sent_at' => null,
            ])->save();

            if ((string) $locked->status !== 'invited') {
                $locked->forceFill(['status' => 'invited'])->save();
            }

            $companyName = (string) DB::table('tenants')->where('id', $companyId)->value('name');

            return [
                'userId' => (int) $user->id,
                'companyId' => $companyId,
                'email' => (string) $user->email,
                'employeeName' => trim($locked->first_name.' '.$locked->last_name),
                'companyName' => $companyName,
                'expiresAt' => $expires->copy(),
                'replaced' => $replaced,
            ];
        });

        try {
            Mail::to($prepared['email'])->send(new EmployeeInvitationMail(
                $token,
                $prepared['employeeName'],
                $prepared['companyName'],
                $prepared['expiresAt'],
            ));
        } catch (Throwable $exception) {
            DB::table('users')->where('id', $prepared['userId'])->update([
                'invite_token' => null,
                'invite_expires_at' => null,
                'invite_sent_at' => null,
                'updated_at' => now(),
            ]);
            app(MailTransport::class)->logFailure('Employee invitation', $exception, [
                'user_id' => $prepared['userId'],
                'company_id' => $prepared['companyId'],
            ]);

            return [
                'sent' => false,
                'message' => $prepared['replaced']
                    ? 'The invitation email could not be sent. The previous link no longer works, and the login stays invitation pending so you can retry.'
                    : 'The invitation email could not be sent. The login stays invitation pending so you can retry.',
            ];
        }

        DB::table('users')->where('id', $prepared['userId'])->where('invite_token', $this->hash($token))->update([
            'invite_sent_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'sent' => true,
            'message' => $prepared['replaced']
                ? 'An invitation email was sent. The previous link no longer works, and the account stays pending until the employee sets a password.'
                : 'An invitation email was sent. The account stays pending until the employee sets a password.',
        ];
    }

    /**
     * @return array{state: string, email?: string, employeeName?: string, companyName?: string, expiresAt?: string}
     */
    public function describe(string $token): array
    {
        $user = $this->find($token);
        if ($user === null) {
            return ['state' => 'invalid'];
        }

        return $this->present($user);
    }

    public function accept(string $token, string $password): User
    {
        if (! $this->validTokenShape($token)) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation link is not valid.',
            ]);
        }

        return DB::transaction(function () use ($token, $password): User {
            $user = User::query()
                ->where('invite_token', $this->hash($token))
                ->lockForUpdate()
                ->first();

            if ($user === null || $user->companyId() === null) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation link is not valid.',
                ]);
            }

            $state = $this->present($user)['state'];
            if ($state === 'accepted') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation has already been used.',
                ]);
            }
            if ($state === 'expired') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation has expired.',
                ]);
            }
            if ($state !== 'ready') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation link is not valid.',
                ]);
            }

            $employee = Employee::query()
                ->where('tenant_id', $user->companyId())
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($employee === null || (string) $employee->status !== 'invited') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation link is not valid.',
                ]);
            }

            $user->forceFill([
                'password' => $password,
                'invite_token' => null,
                'invite_expires_at' => null,
                'invite_accepted_at' => now(),
            ])->save();
            $employee->forceFill(['status' => 'active'])->save();

            return $user->refresh();
        });
    }

    /**
     * @return array{state: string, email?: string, employeeName?: string, companyName?: string, expiresAt?: string}
     */
    private function present(User $user): array
    {
        if ($user->invite_accepted_at !== null) {
            return ['state' => 'accepted'];
        }

        if ($user->invite_expires_at === null || now()->greaterThan($user->invite_expires_at)) {
            return ['state' => 'expired'];
        }

        $companyId = $user->companyId();
        if ($companyId === null) {
            return ['state' => 'invalid'];
        }

        $company = DB::table('tenants')->where('id', $companyId)->first();
        if ($company === null || (string) $company->status !== 'active') {
            return ['state' => 'inactive'];
        }

        $employee = Employee::query()
            ->where('tenant_id', $companyId)
            ->where('user_id', $user->id)
            ->first();

        if ($employee === null || (string) $employee->status !== 'invited') {
            return ['state' => 'invalid'];
        }

        return [
            'state' => 'ready',
            'email' => (string) $user->email,
            'employeeName' => trim($employee->first_name.' '.$employee->last_name),
            'companyName' => (string) $company->name,
            'expiresAt' => $user->invite_expires_at->timezone((string) config('app.timezone'))->format('d M Y, H:i T'),
        ];
    }

    private function find(string $token): ?User
    {
        if (! $this->validTokenShape($token)) {
            return null;
        }

        return User::query()->where('invite_token', $this->hash($token))->first();
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

    private function hours(): int
    {
        return max(1, (int) config('hrms.employee_invitation_hours', 72));
    }
}
