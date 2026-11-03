<?php

namespace App\Http\Controllers\Hrms;

use App\Auth\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StoreInternalMailRequest;
use App\Models\User;
use App\Services\Hrms\CompanyNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('inbox/index', [
            'notifications' => $this->rows($user),
            'recipients' => $this->recipients($user),
            'canCompose' => $user->companyId() !== null,
            'notice' => session('status'),
        ]);
    }

    public function store(StoreInternalMailRequest $request, CompanyNotifier $notifier): RedirectResponse
    {
        $sender = $request->user();
        abort_unless($sender instanceof User, 403);
        $recipient = User::query()
            ->whereKey($request->integer('recipient_id'))
            ->where('tenant_id', $sender->companyId())
            ->first();
        abort_unless($recipient instanceof User && $recipient->id !== $sender->id, 404);

        $validated = $request->validated();
        $notifier->mail($sender, $recipient, $validated['subject'], $validated['message']);

        return back()->with('status', 'Message delivered inside the app.');
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $updated = DB::table('notifications')
            ->where('id', $notification)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            $owns = DB::table('notifications')
                ->where('id', $notification)
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->exists();
            abort_unless($owns, 404);
        }

        return back()->with('status', 'Notification marked read.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(User $user): array
    {
        if (! Schema::hasTable('notifications')) {
            return [];
        }

        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(function ($row): array {
                $data = json_decode((string) $row->data, true);

                $title = is_array($data) ? (string) ($data['title'] ?? 'Notice') : 'Notice';
                $key = is_array($data) ? (string) ($data['key'] ?? '') : '';

                return [
                    'id' => (string) $row->id,
                    'title' => $title,
                    'message' => is_array($data) ? (string) ($data['message'] ?? '') : '',
                    'url' => is_array($data) ? (string) ($data['url'] ?? '') : '',
                    'topic' => $this->topic($key, $title),
                    'read' => $row->read_at !== null,
                    'at' => (string) $row->created_at,
                    'direction' => is_array($data) && ($data['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                    'kind' => is_array($data) && ($data['kind'] ?? '') === 'mail' ? 'mail' : 'notice',
                    'from' => is_array($data) ? (string) ($data['from_name'] ?? '') : '',
                    'to' => is_array($data) ? (string) ($data['to_name'] ?? '') : '',
                ];
            })->all();
    }

    private function topic(string $key, string $title): string
    {
        $value = strtolower($title);

        if (str_starts_with($key, 'announcement-') || str_contains($value, 'announcement')) {
            return 'announcement';
        }

        if (str_contains($value, 'leave')) {
            return 'leave';
        }

        if (str_contains($value, 'attendance') || str_contains($value, 'correction')) {
            return 'attendance';
        }

        if (str_contains($value, 'payslip')) {
            return 'payslip';
        }

        if (str_starts_with($key, 'document-') || str_contains($value, 'document')) {
            return 'document';
        }

        return 'notice';
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    private function recipients(User $user): array
    {
        $companyId = $user->companyId();

        if ($companyId === null || ! Schema::hasColumn('users', 'tenant_id')) {
            return [];
        }

        $columns = ['id', 'name', 'email'];
        foreach (['tenant_id', 'is_platform_admin', 'invited_at', 'invite_accepted_at'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = $column;
            }
        }

        return User::query()
            ->where('tenant_id', $companyId)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->limit(200)
            ->get($columns)
            ->filter(fn (User $person): bool => AccountStatus::denialReason($person) === null)
            ->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
            ])
            ->values()
            ->all();
    }
}
