<?php

namespace App\Services\Hrms;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WorkTasks
{
    public const STATUSES = ['assigned', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function ready(): bool
    {
        return Schema::hasTable('work_tasks') && Schema::hasTable('work_task_events');
    }

    public function canAssign(User $actor): bool
    {
        return $this->ready() && in_array($actor->applicationRole(), ['company_admin', 'hr', 'team_lead'], true);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function assign(User $actor, array $input): int
    {
        abort_unless($this->canAssign($actor), 404);
        $companyId = (int) $actor->companyId();
        $assignee = (int) $input['assignee_employee_id'];
        $this->assertAssignee($actor, $assignee);

        return DB::transaction(function () use ($actor, $companyId, $assignee, $input): int {
            $id = (int) DB::table('work_tasks')->insertGetId([
                'tenant_id' => $companyId,
                'assignee_employee_id' => $assignee,
                'title' => trim((string) $input['title']),
                'description' => $this->blank($input['description'] ?? null),
                'priority' => in_array($input['priority'] ?? 'medium', self::PRIORITIES, true) ? $input['priority'] : 'medium',
                'start_on' => $this->blank($input['start_on'] ?? null),
                'due_on' => $this->blank($input['due_on'] ?? null),
                'expected_on' => $this->blank($input['expected_on'] ?? null),
                'remarks' => $this->blank($input['remarks'] ?? null),
                'status' => 'assigned',
                'created_by' => $actor->id,
                'assigned_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event($companyId, $id, $actor, 'assigned', 'Task assigned.');

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function progress(User $actor, int $taskId, array $input): void
    {
        abort_unless($this->ready(), 404);

        DB::transaction(function () use ($actor, $taskId, $input): void {
            $task = DB::table('work_tasks')->where('tenant_id', $actor->companyId())->where('id', $taskId)->lockForUpdate()->first();
            abort_if($task === null, 404);
            $this->assertVisible($actor, (int) $task->assignee_employee_id);
            $status = (string) ($input['status'] ?? $task->status);

            if (! in_array($status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Choose a task status.']);
            }

            if ($actor->applicationRole() === 'employee' && $status === 'cancelled') {
                throw ValidationException::withMessages(['status' => 'An assigned employee cannot cancel this task.']);
            }

            $completed = $status === 'completed';
            DB::table('work_tasks')->where('id', $task->id)->update([
                'status' => $status,
                'remarks' => array_key_exists('remarks', $input) ? $this->blank($input['remarks']) : $task->remarks,
                'completed_by' => $completed ? $actor->id : null,
                'completed_at' => $completed ? now() : null,
                'updated_at' => now(),
            ]);
            $this->event((int) $task->tenant_id, (int) $task->id, $actor, 'progress', $this->blank($input['note'] ?? null) ?? 'Status updated.');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor): array
    {
        if (! $this->ready() || $actor->companyId() === null) {
            return [];
        }

        $ids = $this->visibleEmployeeIds($actor);

        if ($ids === []) {
            return [];
        }

        $zone = $this->zone($actor);
        $today = Carbon::now($zone)->toDateString();

        return DB::table('work_tasks')
            ->join('employees', 'employees.id', '=', 'work_tasks.assignee_employee_id')
            ->where('work_tasks.tenant_id', $actor->companyId())
            ->whereIn('work_tasks.assignee_employee_id', $ids)
            ->orderByDesc('work_tasks.id')
            ->limit(40)
            ->get([
                'work_tasks.*',
                'employees.first_name',
                'employees.last_name',
            ])
            ->map(function ($task) use ($today): array {
                $closed = in_array($task->status, ['completed', 'cancelled'], true);

                return [
                    'id' => (int) $task->id,
                    'title' => (string) $task->title,
                    'description' => (string) ($task->description ?? ''),
                    'assignee' => trim($task->first_name.' '.$task->last_name),
                    'assigneeEmployeeId' => (int) $task->assignee_employee_id,
                    'priority' => (string) $task->priority,
                    'status' => (string) $task->status,
                    'startOn' => $task->start_on ? (string) $task->start_on : '',
                    'dueOn' => $task->due_on ? (string) $task->due_on : '',
                    'expectedOn' => $task->expected_on ? (string) $task->expected_on : '',
                    'remarks' => (string) ($task->remarks ?? ''),
                    'overdue' => ! $closed && $task->due_on !== null && (string) $task->due_on < $today,
                ];
            })->all();
    }

    /**
     * @return array{assigned: int, inProgress: int, completed: int, overdue: int}
     */
    public function counts(User $actor): array
    {
        $rows = $this->list($actor);

        return [
            'assigned' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'assigned')),
            'inProgress' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'in_progress')),
            'completed' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'completed')),
            'overdue' => count(array_filter($rows, fn (array $row): bool => $row['overdue'])),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function assignees(User $actor): array
    {
        if (! $this->canAssign($actor)) {
            return [];
        }

        $ids = $this->visibleEmployeeIds($actor);
        $query = DB::table('employees')->where('tenant_id', $actor->companyId())->where('status', 'active')->orderBy('first_name');

        if ($actor->applicationRole() === 'team_lead') {
            $self = $this->employeeId($actor);
            $ids = $self === null ? [] : DB::table('employees')->where('tenant_id', $actor->companyId())->where('manager_id', $self)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($ids === []) {
            return [];
        }

        return $query->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'employee_code'])->map(fn ($employee) => [
            'id' => (int) $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name).' · '.$employee->employee_code,
        ])->all();
    }

    private function assertAssignee(User $actor, int $employeeId): void
    {
        $employee = DB::table('employees')->where('tenant_id', $actor->companyId())->where('id', $employeeId)->where('status', 'active')->first();
        abort_if($employee === null, 404);

        if ($actor->applicationRole() === 'team_lead') {
            $self = $this->employeeId($actor);
            abort_unless($self !== null && (int) $employee->manager_id === $self, 404);
        }
    }

    private function assertVisible(User $actor, int $assigneeId): void
    {
        $ids = $this->visibleEmployeeIds($actor);
        abort_unless(in_array($assigneeId, $ids, true), 404);
    }

    /**
     * @return list<int>
     */
    private function visibleEmployeeIds(User $actor): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null) {
            return [];
        }

        if (in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            return DB::table('employees')->where('tenant_id', $companyId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $self = $this->employeeId($actor);

        if ($actor->applicationRole() === 'team_lead') {
            $reports = $self === null ? [] : DB::table('employees')->where('tenant_id', $companyId)->where('manager_id', $self)->pluck('id')->map(fn ($id) => (int) $id)->all();

            return array_values(array_unique(array_filter([$self, ...$reports])));
        }

        return $self === null ? [] : [$self];
    }

    private function employeeId(User $actor): ?int
    {
        $id = DB::table('employees')->where('tenant_id', $actor->companyId())->where('user_id', $actor->id)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function zone(User $actor): string
    {
        $zone = (string) DB::table('tenants')->where('id', $actor->companyId())->value('timezone');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Asia/Kolkata';
    }

    private function event(int $companyId, int $taskId, User $actor, string $action, string $note): void
    {
        DB::table('work_task_events')->insert([
            'tenant_id' => $companyId,
            'task_id' => $taskId,
            'actor_id' => $actor->id,
            'action' => $action,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
