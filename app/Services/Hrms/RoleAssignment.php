<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoleAssignment
{
    /**
     * Standard company roles. Owner is Company Admin and is never assigned here.
     * Manager is shown as Team Lead. Accounts stays optional.
     *
     * @var list<string>
     */
    public const STANDARD = ['Owner', 'Employee', 'Manager', 'HR'];

    public function ensureStandard(int $companyId): void
    {
        foreach (self::STANDARD as $name) {
            $this->define($companyId, $name);
        }
    }

    public function defineAccounts(User $actor): void
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null || $actor->applicationRole() !== 'company_admin', 403);

        if ($this->define($companyId, 'Accounts')) {
            $this->event($actor, null, 'Accounts', 'defined');
        }
    }

    private function define(int $companyId, string $name): bool
    {
        $exists = DB::table('roles')
            ->where('tenant_id', $companyId)
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            DB::table('roles')->insert([
                'tenant_id' => $companyId,
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            return false;
        }

        return true;
    }

    public function assign(User $actor, User $user, int $companyId, string $roleName): void
    {
        abort_if((int) $user->tenant_id !== $companyId || (int) $actor->companyId() !== $companyId, 404);

        $roleId = DB::table('roles')
            ->where('tenant_id', $companyId)
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->value('id');

        abort_if($roleId === null, 422);

        $current = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.tenant_id', $companyId)
            ->value('roles.name');

        if ($current === $roleName) {
            return;
        }

        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('tenant_id', $companyId)
            ->delete();

        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $companyId,
        ]);

        $this->event($actor, (int) $user->id, $roleName, 'assigned');
    }

    private function event(User $actor, ?int $subjectUserId, string $roleName, string $action): void
    {
        if (! Schema::hasTable('role_assignment_events')) {
            return;
        }

        DB::table('role_assignment_events')->insert([
            'tenant_id' => $actor->companyId(),
            'actor_id' => $actor->id,
            'subject_user_id' => $subjectUserId,
            'role_name' => $roleName,
            'action' => $action,
            'created_at' => now(),
        ]);
    }
}
