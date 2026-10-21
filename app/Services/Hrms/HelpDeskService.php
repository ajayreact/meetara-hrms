<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HelpDeskService
{
    /** @var list<string> */
    public const CATEGORIES = ['Attendance', 'Leave', 'Payroll', 'Facilities', 'Other'];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'medium', 'high'];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'open' => ['in_progress', 'cancelled'],
        'in_progress' => ['resolved', 'cancelled'],
    ];

    public function __construct(private readonly CompanyNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(User $actor, array $input): int
    {
        $employee = $this->employeeFor($actor);
        abort_if($employee === null, 403);

        return DB::transaction(function () use ($actor, $employee, $input): int {
            $id = DB::table('tickets')->insertGetId([
                'tenant_id' => $actor->companyId(),
                'employee_id' => $employee->id,
                'category' => $input['category'],
                'title' => $input['title'],
                'description' => $input['description'],
                'priority' => $input['priority'],
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event((int) $actor->companyId(), $id, $actor->id, 'opened');
            DB::afterCommit(function () use ($actor, $id): void {
                $this->notifier->onceEach(
                    array_diff($this->supportIds((int) $actor->companyId()), [$actor->id]),
                    'ticket-'.$id.'-opened',
                    'Help desk ticket opened',
                    'A help desk ticket was opened.',
                    '/help-desk?ticket='.$id
                );
            });

            return $id;
        });
    }

    public function comment(User $actor, int $ticketId, string $body): void
    {
        $ticket = $this->visible($actor, $ticketId);

        DB::transaction(function () use ($actor, $ticket, $body): void {
            $commentId = DB::table('ticket_comments')->insertGetId([
                'tenant_id' => $ticket->tenant_id,
                'ticket_id' => $ticket->id,
                'user_id' => $actor->id,
                'body' => $body,
                'created_at' => now(),
            ]);
            $this->event((int) $ticket->tenant_id, (int) $ticket->id, $actor->id, 'commented');
            DB::afterCommit(function () use ($actor, $ticket, $commentId): void {
                $employeeUserId = (int) DB::table('employees')->where('id', $ticket->employee_id)->value('user_id');
                $recipient = (int) $actor->id === $employeeUserId ? (int) $ticket->assignee_id : $employeeUserId;
                $this->notifier->once(
                    $recipient,
                    'ticket-'.$ticket->id.'-comment-'.$commentId,
                    'Help desk ticket updated',
                    'A help desk ticket has a new comment.',
                    '/help-desk?ticket='.$ticket->id
                );
            });
        });
    }

    public function assign(User $actor, int $ticketId, int $assigneeId): void
    {
        abort_unless($this->canManage($actor), 403);
        $ticket = $this->visible($actor, $ticketId);
        abort_unless(in_array($assigneeId, $this->supportIds((int) $actor->companyId()), true), 422);

        if ((int) $ticket->assignee_id === $assigneeId) {
            return;
        }

        DB::transaction(function () use ($actor, $ticket, $assigneeId): void {
            DB::table('tickets')->where('id', $ticket->id)->update([
                'assignee_id' => $assigneeId,
                'updated_at' => now(),
            ]);
            $this->event((int) $ticket->tenant_id, (int) $ticket->id, $actor->id, 'assigned');
            DB::afterCommit(fn () => $this->notifier->once(
                $assigneeId,
                'ticket-'.$ticket->id.'-assigned-'.$assigneeId,
                'Help desk ticket assigned',
                'A help desk ticket was assigned to you.',
                '/help-desk?ticket='.$ticket->id
            ));
        });
    }

    public function transition(User $actor, int $ticketId, string $status): void
    {
        $ticket = $this->visible($actor, $ticketId);
        $allowed = self::TRANSITIONS[$ticket->status] ?? [];
        abort_unless(in_array($status, $allowed, true), 422);

        $employeeUserId = (int) DB::table('employees')->where('id', $ticket->employee_id)->value('user_id');
        $ownerCancel = $status === 'cancelled' && (int) $actor->id === $employeeUserId && $ticket->status === 'open';
        abort_unless($this->canManage($actor) || $ownerCancel, 403);

        DB::transaction(function () use ($actor, $ticket, $status, $employeeUserId): void {
            DB::table('tickets')->where('id', $ticket->id)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
            $this->event((int) $ticket->tenant_id, (int) $ticket->id, $actor->id, 'status_'.$status);
            DB::afterCommit(fn () => $this->notifier->once(
                $employeeUserId,
                'ticket-'.$ticket->id.'-status-'.$status,
                'Help desk ticket updated',
                'Your help desk ticket status changed.',
                '/help-desk?ticket='.$ticket->id
            ));
        });
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array{q?: string, status?: string, priority?: string, category?: string}  $filters
     * @return array<string, mixed>
     */
    public function page(User $actor, ?int $selectedId, array $filters = []): array
    {
        $filters = $this->filters($filters);
        $blocked = $this->employeeFor($actor) === null;
        $empty = [
            'tickets' => [],
            'selected' => null,
            'canManage' => false,
            'canOpen' => false,
            'scoped' => false,
            'openBlocked' => $actor->isPlatformAdmin()
                ? 'A platform account is not a company employee. Tickets stay inside each company and are not listed here.'
                : 'This account has no active employee record, so a new ticket cannot be opened.',
            'staff' => [],
            'filters' => $filters,
        ];

        if ($actor->companyId() === null || ! Schema::hasTable('tickets')) {
            return $empty;
        }

        $tickets = $this->applyFilters($this->scoped($actor), $filters)->orderByDesc('tickets.id')->limit(50)->get();
        $selected = null;

        if ($selectedId !== null) {
            $match = $tickets->firstWhere('id', $selectedId) ?? $this->visibleOrNull($actor, $selectedId);
            if ($match !== null) {
                $selected = $this->detail($match);
            }
        }

        $names = $this->userNames($tickets->pluck('assignee_id')->filter()->all());

        return [
            'tickets' => $tickets->map(fn ($ticket) => [
                ...$this->summary($ticket),
                'assignee' => $ticket->assignee_id ? ($names[(int) $ticket->assignee_id] ?? 'Not recorded') : null,
            ])->all(),
            'selected' => $selected,
            'canManage' => $this->canManage($actor),
            'canOpen' => ! $blocked,
            'scoped' => true,
            'openBlocked' => $blocked ? 'This account has no active employee record, so a new ticket cannot be opened.' : null,
            'staff' => $this->canManage($actor) ? $this->staff((int) $actor->companyId()) : [],
            'filters' => $filters,
        ];
    }

    /**
     * @param  array{q?: string, status?: string, priority?: string, category?: string}  $filters
     * @return array{q: string, status: string, priority: string, category: string}
     */
    private function filters(array $filters): array
    {
        $status = (string) ($filters['status'] ?? '');
        $priority = (string) ($filters['priority'] ?? '');
        $category = (string) ($filters['category'] ?? '');

        return [
            'q' => trim((string) ($filters['q'] ?? '')),
            'status' => in_array($status, ['open', 'in_progress', 'resolved', 'cancelled'], true) ? $status : '',
            'priority' => in_array($priority, self::PRIORITIES, true) ? $priority : '',
            'category' => in_array($category, self::CATEGORIES, true) ? $category : '',
        ];
    }

    /**
     * @param  array{q: string, status: string, priority: string, category: string}  $filters
     */
    private function applyFilters(mixed $query, array $filters): mixed
    {
        if ($filters['status'] !== '') {
            $query->where('tickets.status', $filters['status']);
        }
        if ($filters['priority'] !== '') {
            $query->where('tickets.priority', $filters['priority']);
        }
        if ($filters['category'] !== '') {
            $query->where('tickets.category', $filters['category']);
        }
        if ($filters['q'] !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(function ($inner) use ($filters, $like): void {
                $inner->where('tickets.title', 'like', $like);
                if (ctype_digit($filters['q'])) {
                    $inner->orWhere('tickets.id', (int) $filters['q']);
                }
            });
        }

        return $query;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    private function event(int $companyId, int $ticketId, int $actorId, string $action): void
    {
        if (! Schema::hasTable('ticket_events')) {
            return;
        }

        DB::table('ticket_events')->insert([
            'tenant_id' => $companyId,
            'ticket_id' => $ticketId,
            'actor_id' => $actorId,
            'action' => $action,
            'created_at' => now(),
        ]);
    }

    private function visible(User $actor, int $ticketId): object
    {
        $ticket = $this->visibleOrNull($actor, $ticketId);
        abort_if($ticket === null, 404);

        return $ticket;
    }

    private function visibleOrNull(User $actor, int $ticketId): ?object
    {
        $ticket = $this->scoped($actor)->where('tickets.id', $ticketId)->first();

        return $ticket;
    }

    private function scoped(User $actor)
    {
        $query = DB::table('tickets')->where('tickets.tenant_id', $actor->companyId());

        if (! $this->canManage($actor)) {
            $employeeId = DB::table('employees')->where('user_id', $actor->id)->where('tenant_id', $actor->companyId())->value('id');
            $query->where(function ($inner) use ($actor, $employeeId): void {
                $inner->where('tickets.assignee_id', $actor->id);
                if ($employeeId !== null) {
                    $inner->orWhere('tickets.employee_id', $employeeId);
                }
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(object $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'title' => $ticket->title,
            'category' => $ticket->category,
            'priority' => ucfirst((string) $ticket->priority),
            'status' => $this->label((string) $ticket->status),
            'rawStatus' => $ticket->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(object $ticket): array
    {
        $comments = Schema::hasTable('ticket_comments')
            ? DB::table('ticket_comments')->where('ticket_id', $ticket->id)->where('tenant_id', $ticket->tenant_id)->orderBy('id')->get()
            : collect();
        $authors = $this->userNames($comments->pluck('user_id')->all());
        $assignee = $ticket->assignee_id ? ($this->userNames([(int) $ticket->assignee_id])[(int) $ticket->assignee_id] ?? 'Not recorded') : null;
        $events = Schema::hasTable('ticket_events')
            ? DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('tenant_id', $ticket->tenant_id)->orderBy('id')->get()
            : collect();

        return [
            ...$this->summary($ticket),
            'description' => $ticket->description,
            'assigneeId' => $ticket->assignee_id ? (int) $ticket->assignee_id : null,
            'assignee' => $assignee,
            'comments' => $comments->map(fn ($comment) => [
                'id' => (int) $comment->id,
                'body' => $comment->body,
                'author' => (string) ($authors[(int) $comment->user_id] ?? 'Not recorded'),
                'at' => (string) $comment->created_at,
            ])->all(),
            'history' => $events->map(fn ($event) => [
                'action' => str_replace('_', ' ', (string) $event->action),
                'at' => (string) $event->created_at,
            ])->all(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function staff(int $companyId): array
    {
        $ids = $this->supportIds($companyId);

        if ($ids === []) {
            return [];
        }

        return DB::table('users')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($user) => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function supportIds(int $companyId): array
    {
        return array_values(array_unique(array_merge(
            $this->notifier->roleUserIds($companyId, 'HR'),
            $this->notifier->roleUserIds($companyId, 'Owner')
        )));
    }

    private function employeeFor(User $actor): ?Employee
    {
        if ($actor->companyId() === null) {
            return null;
        }

        return Employee::query()->where('user_id', $actor->id)->where('tenant_id', $actor->companyId())->where('status', 'active')->first();
    }

    private function canManage(User $actor): bool
    {
        return in_array($actor->applicationRole(), ['company_admin', 'hr'], true);
    }

    private function label(string $status): string
    {
        return match ($status) {
            'in_progress' => 'In progress',
            default => ucfirst($status),
        };
    }
}
