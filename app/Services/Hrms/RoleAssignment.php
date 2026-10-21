<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoleAssignment
{
    public function defineAccounts(User $actor): void
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null || $actor->applicationRole() !== 'company_admin', 403);

        $exists = DB::table('roles')
            ->where('tenant_id', $companyId)
            ->where('name', 'Accounts')
            ->where('guard_name', 'web')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('roles')->insert([
            'tenant_id' => $companyId,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->event($actor, null, 'Accounts', 'defined');
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
