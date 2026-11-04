<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Announcements
{
    public const CATEGORIES = [
        'general' => 'General Updates',
        'hr' => 'HR Notices',
        'holiday' => 'Holidays',
        'payroll' => 'Payroll Updates',
        'event' => 'Company Events',
        'important' => 'Important Notices',
    ];

    public const VISIBILITY = [
        'company' => 'Everyone in the company',
        'employees' => 'Employees and team leads',
        'hr' => 'HR and company admin',
    ];

    public function __construct(private readonly CompanyNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, array $input, ?UploadedFile $attachment, ?int $announcementId = null): int
    {
        $this->assertManager($actor);
        $companyId = (int) $actor->companyId();
        $current = null;

        if ($announcementId !== null) {
            $current = $this->find($companyId, $announcementId);
            abort_if($current->status === 'archived', 404);
        }

        $publishAt = $this->dateTime($input['publish_at'] ?? null);
        $expires = $this->dateOnly($input['expires_on'] ?? null);

        if ($publishAt !== null && $expires !== null && $expires < substr($publishAt, 0, 10)) {
            throw ValidationException::withMessages(['expires_on' => 'The expiry date must be on or after the publish date.']);
        }

        $values = [
            'title' => trim((string) $input['title']),
            'description' => trim((string) $input['description']),
            'category' => (string) $input['category'],
            'visibility' => (string) $input['visibility'],
            'publish_at' => $publishAt,
            'expires_on' => $expires,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ];

        if ($attachment !== null) {
            $stored = $attachment->store('announcements/'.$companyId, 'local');
            $values['attachment_path'] = $stored;
            $values['attachment_name'] = $attachment->getClientOriginalName();
        }

        if ($current === null) {
            $values['tenant_id'] = $companyId;
            $values['status'] = 'draft';
            $values['created_by'] = $actor->id;
            $values['created_at'] = now();

            return (int) DB::table('hr_announcements')->insertGetId($values);
        }

        DB::table('hr_announcements')->where('id', $current->id)->update($values);

        return (int) $current->id;
    }

    public function publish(User $actor, int $announcementId): void
    {
        $this->assertManager($actor);
        $row = $this->find((int) $actor->companyId(), $announcementId);
        abort_if($row->status === 'archived', 404);
        $publishAt = $row->publish_at ?? now();
        $due = strtotime((string) $publishAt) <= time();

        DB::table('hr_announcements')->where('id', $row->id)->update([
            'status' => $due ? 'published' : 'scheduled',
            'publish_at' => $publishAt,
            'published_at' => $due ? ($row->published_at ?? now()) : null,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);

        if ($due) {
            $this->notify($row->id);
        }
    }

    public function archive(User $actor, int $announcementId): void
    {
        $this->assertManager($actor);
        $row = $this->find((int) $actor->companyId(), $announcementId);
        DB::table('hr_announcements')->where('id', $row->id)->update([
            'status' => 'archived',
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
    }

    public function releaseDue(int $companyId): void
    {
        if (! Schema::hasTable('hr_announcements')) {
            return;
        }

        $due = DB::table('hr_announcements')
            ->where('tenant_id', $companyId)
            ->where('status', 'scheduled')
            ->where('publish_at', '<=', now())
            ->pluck('id');

        foreach ($due as $id) {
            DB::table('hr_announcements')->where('id', $id)->update([
                'status' => 'published',
                'published_at' => now(),
                'updated_at' => now(),
            ]);
            $this->notify((int) $id);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function feed(User $actor): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null || ! Schema::hasTable('hr_announcements')) {
            return [];
        }

        $this->releaseDue((int) $companyId);
        $manage = $this->manages($actor);
        $today = now()->toDateString();

        return DB::table('hr_announcements')
            ->where('tenant_id', $companyId)
            ->orderByDesc('id')
            ->get()
            ->filter(function ($row) use ($actor, $manage, $today): bool {
                if ($manage) {
                    return true;
                }

                return $this->visibleNow($row, $actor, $today);
            })
            ->map(fn ($row) => $this->present($row))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, int $announcementId): array
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null, 404);
        $this->releaseDue((int) $companyId);
        $row = $this->find((int) $companyId, $announcementId);
        abort_unless($this->manages($actor) || $this->visibleNow($row, $actor, now()->toDateString()), 404);

        return $this->present($row, true);
    }

    public function download(User $actor, int $announcementId): StreamedResponse
    {
        $companyId = (int) $actor->companyId();
        $row = $this->find($companyId, $announcementId);
        abort_unless($this->manages($actor) || $this->visibleNow($row, $actor, now()->toDateString()), 404);
        $path = (string) ($row->attachment_path ?? '');
        $prefix = 'announcements/'.$companyId.'/';
        abort_unless($path !== '' && str_starts_with($path, $prefix) && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, (string) ($row->attachment_name ?: 'announcement'), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function unreadCount(User $actor): int
    {
        if (! Schema::hasTable('notifications')) {
            return 0;
        }

        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $actor->id)
            ->whereNull('read_at')
            ->where('data->key', 'like', 'announcement-%')
            ->count();
    }

    public function manages(User $actor): bool
    {
        return $actor->companyId() !== null && in_array($actor->applicationRole(), ['company_admin', 'hr'], true);
    }

    private function notify(int $announcementId): void
    {
        $row = DB::table('hr_announcements')->where('id', $announcementId)->first();

        if ($row === null || $row->status !== 'published') {
            return;
        }

        $ids = $this->audienceIds((int) $row->tenant_id, (string) $row->visibility);
        $this->notifier->onceEach(
            $ids,
            'announcement-'.$row->id,
            (string) $row->title,
            (string) $row->description,
            route('hrms.announcements.show', $row->id),
        );
    }

    /**
     * @return list<int>
     */
    private function audienceIds(int $companyId, string $visibility): array
    {
        $roles = match ($visibility) {
            'hr' => ['Owner', 'HR'],
            'employees' => ['Employee', 'Manager', 'Company Manager'],
            default => ['Owner', 'HR', 'Accounts', 'Manager', 'Company Manager', 'Employee'],
        };
        $ids = [];

        foreach ($roles as $role) {
            $ids = [...$ids, ...$this->notifier->roleUserIds($companyId, $role)];
        }

        return array_values(array_unique($ids));
    }

    private function visibleNow(object $row, User $actor, string $today): bool
    {
        if ((string) $row->status !== 'published') {
            return false;
        }

        if ($row->publish_at !== null && strtotime((string) $row->publish_at) > time()) {
            return false;
        }

        if ($row->expires_on !== null && substr((string) $row->expires_on, 0, 10) < $today) {
            return false;
        }

        return $this->audienceAllows((string) $row->visibility, $actor->applicationRole());
    }

    private function audienceAllows(string $visibility, string $role): bool
    {
        return match ($visibility) {
            'hr' => in_array($role, ['company_admin', 'hr'], true),
            'employees' => in_array($role, ['employee', 'team_lead'], true),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row, bool $full = false): array
    {
        $author = $row->created_by === null
            ? ''
            : (string) DB::table('users')->where('id', $row->created_by)->value('name');

        $item = [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'category' => self::CATEGORIES[$row->category] ?? (string) $row->category,
            'categoryKey' => (string) $row->category,
            'visibility' => self::VISIBILITY[$row->visibility] ?? (string) $row->visibility,
            'visibilityKey' => (string) $row->visibility,
            'status' => $this->statusLabel($row),
            'statusKey' => (string) $row->status,
            'publishAt' => $row->publish_at === null ? null : (string) $row->publish_at,
            'expiresOn' => $row->expires_on === null ? null : substr((string) $row->expires_on, 0, 10),
            'createdBy' => $author,
            'hasAttachment' => is_string($row->attachment_path) && $row->attachment_path !== '',
            'attachmentName' => (string) ($row->attachment_name ?? ''),
        ];

        if ($full) {
            $item['description'] = (string) $row->description;
        }

        return $item;
    }

    private function statusLabel(object $row): string
    {
        if ((string) $row->status === 'published' && $row->expires_on !== null && substr((string) $row->expires_on, 0, 10) < now()->toDateString()) {
            return 'Expired';
        }

        return match ((string) $row->status) {
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'archived' => 'Archived',
            default => 'Draft',
        };
    }

    private function find(int $companyId, int $announcementId): object
    {
        $row = DB::table('hr_announcements')->where('tenant_id', $companyId)->where('id', $announcementId)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function assertManager(User $actor): void
    {
        abort_unless($this->manages($actor), 403);
        abort_unless(Schema::hasTable('hr_announcements'), 404);
    }

    private function dateTime(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $stamp = strtotime($text);

        return $stamp === false ? null : date('Y-m-d H:i:s', $stamp);
    }

    private function dateOnly(mixed $value): ?string
    {
        $text = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
    }
}
