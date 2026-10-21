<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        return Inertia::render('inbox/index', [
            'notifications' => $this->rows($request->user()),
            'notice' => session('status'),
        ]);
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

                return [
                    'id' => (string) $row->id,
                    'title' => is_array($data) ? (string) ($data['title'] ?? 'Notice') : 'Notice',
                    'message' => is_array($data) ? (string) ($data['message'] ?? '') : '',
                    'url' => is_array($data) ? (string) ($data['url'] ?? '') : '',
                    'read' => $row->read_at !== null,
                    'at' => (string) $row->created_at,
                ];
            })->all();
    }
}
