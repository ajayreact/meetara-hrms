<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CompanyNotifier
{
    public function once(int $userId, string $key, string $title, string $message, string $url): void
    {
        if ($userId <= 0 || ! Schema::hasTable('notifications')) {
            return;
        }

        $exists = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->where('data->key', $key)
            ->exists();

        if ($exists) {
            return;
        }

        $this->write($userId, [
            'key' => $key,
            'title' => $title,
            'message' => $message,
            'url' => $url,
        ], null);
    }

    public function mail(User $sender, User $recipient, string $subject, string $message): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $thread = (string) Str::uuid();
        $shared = [
            'kind' => 'mail',
            'title' => $subject,
            'message' => $message,
            'url' => '',
            'from_name' => $sender->name,
            'from_user_id' => $sender->id,
            'to_name' => $recipient->name,
            'to_user_id' => $recipient->id,
        ];

        $this->write($recipient->id, array_merge($shared, [
            'key' => 'mail-'.$thread.'-in',
            'direction' => 'in',
        ]), null);
        $this->write($sender->id, array_merge($shared, [
            'key' => 'mail-'.$thread.'-out',
            'direction' => 'out',
        ]), now());
    }

    /**
     * @param  list<int>  $userIds
     */
    public function onceEach(array $userIds, string $key, string $title, string $message, string $url): void
    {
        foreach (array_unique($userIds) as $userId) {
            $this->once((int) $userId, $key, $title, $message, $url);
        }
    }

    /**
     * @return list<int>
     */
    public function roleUserIds(int $companyId, string $roleName): array
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return [];
        }

        $roleId = DB::table('roles')->where('tenant_id', $companyId)->where('name', $roleName)->value('id');

        if ($roleId === null) {
            return [];
        }

        return DB::table('model_has_roles')
            ->where('tenant_id', $companyId)
            ->where('role_id', $roleId)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(int $userId, array $data, mixed $readAt): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\WorkspaceNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'data' => json_encode($data),
            'read_at' => $readAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
