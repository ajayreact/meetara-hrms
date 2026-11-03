<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeJoinedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkplaceBoard
{
    public function __construct(
        private readonly FollowUps $followUps,
        private readonly WorkTasks $tasks,
        private readonly BirthdayBoard $birthdays,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $actor): array
    {
        $feed = $this->followUps->reminderFeed($actor);
        $bucket = fn (string $name): array => array_values(array_filter($feed, fn (array $row): bool => $row['bucket'] === $name));
        $role = $actor->applicationRole();
        $summary = in_array($role, ['company_admin', 'hr', 'team_lead'], true) ? $this->summary($actor) : null;

        return [
            'summary' => $summary,
            'tasks' => $this->tasks->list($actor),
            'assignees' => $this->tasks->assignees($actor),
            'canAssignTasks' => $this->tasks->canAssign($actor),
            'reminders' => [
                'today' => $bucket('today'),
                'upcoming' => $bucket('upcoming'),
                'overdue' => $bucket('overdue'),
            ],
            'candidates' => $this->followUps->page($actor)['candidates'],
            'birthdays' => $this->birthdays->upcoming($actor),
            'announcements' => $this->announcements($actor),
            'notices' => $this->notices($actor),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(User $actor): array
    {
        $companyId = (int) $actor->companyId();
        $follow = $this->followUps->counts($actor);
        $tasks = $this->tasks->counts($actor);
        $joined = 0;

        if (Schema::hasTable('employees')) {
            $query = Employee::query()->inCompany($companyId)->where('status', 'active')->where('date_of_joining', '>=', Carbon::now()->subDays(30)->toDateString());

            if ($actor->applicationRole() === 'team_lead') {
                $self = DB::table('employees')->where('tenant_id', $companyId)->where('user_id', $actor->id)->value('id');
                $query->where('manager_id', $self ?? 0);
            }

            $joined = $query->count();
        }

        $active = Employee::query()->inCompany($companyId)->where('status', 'active');

        if ($actor->applicationRole() === 'team_lead') {
            $self = DB::table('employees')->where('tenant_id', $companyId)->where('user_id', $actor->id)->value('id');
            $active->where(function ($query) use ($self): void {
                $query->where('manager_id', $self ?? 0)->orWhere('id', $self ?? 0);
            });
        }

        return [
            'activeEmployees' => $active->count(),
            'newEmployees' => $joined,
            'pendingFollowUps' => $follow['pending'],
            'overdueReminders' => $follow['overdue'],
            'tasksAssigned' => $tasks['assigned'],
            'tasksInProgress' => $tasks['inProgress'],
            'tasksCompleted' => $tasks['completed'],
            'tasksOverdue' => $tasks['overdue'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function announcements(User $actor): array
    {
        if (! Schema::hasTable('notifications')) {
            return [];
        }

        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $actor->id)
            ->where('type', EmployeeJoinedNotification::class)
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(function ($row): array {
                $data = json_decode((string) $row->data, true) ?: [];

                return [
                    'id' => (string) $row->id,
                    'title' => (string) ($data['title'] ?? 'Welcome Our New Team Member!'),
                    'message' => (string) ($data['message'] ?? ''),
                    'url' => (string) ($data['url'] ?? ''),
                    'initials' => (string) ($data['initials'] ?? ''),
                    'read' => $row->read_at !== null,
                ];
            })->all();
    }

    /**
     * @return array{unread: int, items: list<array<string, mixed>>}
     */
    private function notices(User $actor): array
    {
        if (! Schema::hasTable('notifications')) {
            return ['unread' => 0, 'items' => []];
        }

        $rows = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $actor->id)
            ->latest('created_at')
            ->limit(8)
            ->get();

        return [
            'unread' => DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $actor->id)->whereNull('read_at')->count(),
            'items' => $rows->map(function ($row): array {
                $data = json_decode((string) $row->data, true) ?: [];

                return [
                    'id' => (string) $row->id,
                    'title' => (string) ($data['title'] ?? 'Notification'),
                    'message' => (string) ($data['message'] ?? ''),
                    'url' => (string) ($data['url'] ?? ''),
                    'read' => $row->read_at !== null,
                ];
            })->all(),
        ];
    }
}
