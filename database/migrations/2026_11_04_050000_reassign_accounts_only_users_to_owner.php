<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION = 'accounts_to_owner';

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles') || ! Schema::hasTable('users')) {
            return;
        }

        $assignments = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Accounts')
            ->where('roles.guard_name', 'web')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->get(['model_has_roles.model_id', 'model_has_roles.tenant_id', 'model_has_roles.role_id']);

        foreach ($assignments as $assignment) {
            $names = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->where('model_has_roles.model_id', $assignment->model_id)
                ->where('model_has_roles.tenant_id', $assignment->tenant_id)
                ->pluck('roles.name');

            if ($names->count() !== 1 || $names->first() !== 'Accounts') {
                continue;
            }

            $ownerId = $this->ownerRoleId((int) $assignment->tenant_id);

            if ($ownerId === null) {
                continue;
            }

            DB::table('model_has_roles')
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $assignment->model_id)
                ->where('tenant_id', $assignment->tenant_id)
                ->delete();

            DB::table('model_has_roles')->insert([
                'role_id' => $ownerId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $assignment->model_id,
                'tenant_id' => $assignment->tenant_id,
            ]);

            $this->event((int) $assignment->tenant_id, (int) $assignment->model_id);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_assignment_events') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $events = DB::table('role_assignment_events')
            ->where('action', self::ACTION)
            ->where('role_name', 'Accounts')
            ->get();

        foreach ($events as $event) {
            $names = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->where('model_has_roles.model_id', $event->subject_user_id)
                ->where('model_has_roles.tenant_id', $event->tenant_id)
                ->pluck('roles.name');

            if ($names->count() !== 1 || $names->first() !== 'Owner') {
                continue;
            }

            $accountsId = DB::table('roles')
                ->where('tenant_id', $event->tenant_id)
                ->where('name', 'Accounts')
                ->where('guard_name', 'web')
                ->value('id');

            if ($accountsId === null) {
                continue;
            }

            DB::table('model_has_roles')
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $event->subject_user_id)
                ->where('tenant_id', $event->tenant_id)
                ->delete();

            DB::table('model_has_roles')->insert([
                'role_id' => $accountsId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $event->subject_user_id,
                'tenant_id' => $event->tenant_id,
            ]);

            DB::table('role_assignment_events')->where('id', $event->id)->delete();
        }
    }

    private function ownerRoleId(int $companyId): ?int
    {
        $existing = DB::table('roles')
            ->where('tenant_id', $companyId)
            ->where('name', 'Owner')
            ->where('guard_name', 'web')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('roles')->insertGetId([
            'tenant_id' => $companyId,
            'name' => 'Owner',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(int $companyId, int $userId): void
    {
        if (! Schema::hasTable('role_assignment_events')) {
            return;
        }

        $recorded = DB::table('role_assignment_events')
            ->where('tenant_id', $companyId)
            ->where('subject_user_id', $userId)
            ->where('action', self::ACTION)
            ->exists();

        if ($recorded) {
            return;
        }

        DB::table('role_assignment_events')->insert([
            'tenant_id' => $companyId,
            'actor_id' => $userId,
            'subject_user_id' => $userId,
            'role_name' => 'Accounts',
            'action' => self::ACTION,
            'created_at' => now(),
        ]);
    }
};
