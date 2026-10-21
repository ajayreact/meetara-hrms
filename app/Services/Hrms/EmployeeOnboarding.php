<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeJoinedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EmployeeOnboarding
{
    /**
     * @var array<string, string>
     */
    private const ACTION_LABELS = [
        'historical_decision' => 'Historical decision',
        'offer_issued' => 'Offer letter issued',
        'document_uploaded' => 'Document uploaded',
        'document_reviewed' => 'Document reviewed',
        'welcome_notified' => 'Welcome notice',
    ];
    public const TASKS = [
        'profile' => 'Employee profile completed',
        'offer_issued' => 'Offer letter generated/issued',
        'signed_offer' => 'Signed offer letter uploaded',
        'pan' => 'PAN document uploaded and reviewed',
        'aadhaar' => 'Aadhaar document uploaded and reviewed',
        'bank_proof' => 'Bank details/proof submitted and reviewed',
        'resume' => 'Resume uploaded and reviewed',
    ];

    public const DOCUMENT_TASKS = [
        'generated_offer' => 'offer_issued',
        'signed_offer' => 'signed_offer',
        'pan' => 'pan',
        'aadhaar' => 'aadhaar',
        'bank_proof' => 'bank_proof',
        'resume' => 'resume',
    ];

    public function ready(): bool
    {
        return Schema::hasTable('onboardings')
            && Schema::hasTable('onboarding_tasks')
            && Schema::hasColumn('onboarding_tasks', 'code')
            && Schema::hasColumn('onboardings', 'welcomed_at')
            && Schema::hasTable('employee_documents')
            && Schema::hasColumn('employee_documents', 'category')
            && Schema::hasColumn('employee_documents', 'review_status');
    }

    public function open(User $actor, Employee $employee): void
    {
        if (! $this->ready()) {
            return;
        }

        $companyId = (int) $employee->tenant_id;
        abort_if((int) $actor->companyId() !== $companyId, 404);

        DB::transaction(function () use ($actor, $employee, $companyId): void {
            $onboardingId = $this->onboardingId($actor, $employee, $companyId);
            $this->ensureTasks($companyId, $onboardingId, (int) $actor->id);
            $this->sync($employee);
            $this->welcome($actor, $employee, $onboardingId);
        });
    }

    public function sync(Employee $employee): void
    {
        if (! $this->ready()) {
            return;
        }

        $onboarding = DB::table('onboardings')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->first();

        if ($onboarding === null) {
            return;
        }

        $latest = DB::table('employee_documents')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->get()
            ->unique('category');

        foreach (self::DOCUMENT_TASKS as $category => $code) {
            $document = $latest->firstWhere('category', $category);
            $status = 'pending';
            $completedAt = null;
            $completedBy = null;

            if ($document !== null && $document->review_status === 'verified') {
                $status = 'completed';
                $completedAt = $document->reviewed_at ?? now();
                $completedBy = $document->reviewed_by;
            } elseif ($document !== null) {
                $status = 'in_progress';
            } else {
                continue;
            }

            DB::table('onboarding_tasks')
                ->where('onboarding_id', $onboarding->id)
                ->where('code', $code)
                ->update([
                    'status' => $status,
                    'completed_at' => $completedAt,
                    'completed_by' => $completedBy,
                    'updated_at' => now(),
                ]);
        }

        $tasks = DB::table('onboarding_tasks')->where('onboarding_id', $onboarding->id)->get();
        $required = $tasks->filter(fn ($task) => array_key_exists((string) $task->code, self::TASKS));
        $completed = $required->where('status', 'completed')->count();
        $total = count(self::TASKS);
        $status = $completed === 0 ? 'pending' : ($completed === $total ? 'completed' : 'in_progress');

        DB::table('onboardings')->where('id', $onboarding->id)->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? ($onboarding->completed_at ?? now()) : null,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $actor, Employee $employee): array
    {
        $manage = $actor->can('update', $employee);
        $self = (int) $employee->user_id === (int) $actor->id;

        if (! $this->ready()) {
            return [
                'ready' => false,
                'started' => false,
                'status' => 'Not started',
                'tasks' => [],
                'documents' => [],
                'canStart' => false,
                'canUpload' => false,
                'canReview' => false,
                'canDecide' => false,
                'decisionsReady' => false,
                'categories' => [],
            ];
        }

        $decisionsReady = Schema::hasColumn('employee_record_events', 'note');
        $onboarding = DB::table('onboardings')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->first();
        $tasks = $onboarding === null
            ? []
            : DB::table('onboarding_tasks')->where('onboarding_id', $onboarding->id)->orderBy('id')->get()->map(function ($task) use ($employee, $decisionsReady): array {
                $name = null;

                if ($task->completed_by) {
                    $name = User::query()->whereKey($task->completed_by)->where('tenant_id', $employee->tenant_id)->value('name');
                }

                $required = array_key_exists((string) $task->code, self::TASKS);
                $decision = $required || ! $decisionsReady ? null : $this->latestDecision($employee, (int) $task->id);
                $decisionName = null;

                if ($decision !== null) {
                    $decisionName = User::query()->whereKey($decision->actor_id)->where('tenant_id', $employee->tenant_id)->value('name');
                }

                return [
                    'id' => (int) $task->id,
                    'code' => (string) $task->code,
                    'title' => (string) $task->title,
                    'status' => $this->label((string) $task->status),
                    'required' => $required,
                    'note' => $required ? null : 'Historical task. It was not mapped to a required checklist item.',
                    'decisionNote' => $decision?->note !== null && $decision->note !== '' ? (string) $decision->note : null,
                    'decisionAt' => $decision?->created_at ? (string) $decision->created_at : null,
                    'decisionBy' => $decisionName ? (string) $decisionName : null,
                    'completedAt' => $task->completed_at ? (string) $task->completed_at : null,
                    'completedBy' => $name ? (string) $name : null,
                ];
            })->all();

        return [
            'ready' => true,
            'started' => $onboarding !== null,
            'status' => $onboarding === null ? 'Not started' : $this->label((string) $onboarding->status),
            'tasks' => $tasks,
            'documents' => ($manage || $self) ? $this->documents($employee, $manage) : [],
            'canStart' => $manage && $onboarding === null,
            'canUpload' => $manage || $self,
            'canReview' => $manage && ! $self,
            'canDecide' => $manage && $decisionsReady,
            'decisionsReady' => $decisionsReady,
            'categories' => $this->categories($manage),
        ];
    }

    /**
     * @return array{ready: bool, events: list<array{id: int, action: string, detail: string, actor: string|null, at: string|null, note: string|null}>}
     */
    public function activity(User $actor, Employee $employee): array
    {
        if ($actor->cannot('view', $employee) || ! Schema::hasTable('employee_record_events')) {
            return ['ready' => false, 'events' => []];
        }

        $hasNote = Schema::hasColumn('employee_record_events', 'note');
        $rows = DB::table('employee_record_events')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->limit(40)
            ->get();
        $names = User::query()
            ->where('tenant_id', $employee->tenant_id)
            ->whereIn('id', $rows->pluck('actor_id')->filter()->all())
            ->pluck('name', 'id');

        return [
            'ready' => true,
            'events' => $rows->map(function ($event) use ($hasNote, $names): array {
                $action = (string) $event->action;

                return [
                    'id' => (int) $event->id,
                    'action' => self::ACTION_LABELS[$action] ?? str_replace('_', ' ', $action),
                    'detail' => $this->activityDetail($action, $event->subject !== null ? (string) $event->subject : null),
                    'actor' => $names->get($event->actor_id) ? (string) $names->get($event->actor_id) : null,
                    'at' => $event->created_at ? (string) $event->created_at : null,
                    'note' => $hasNote && isset($event->note) && $event->note !== '' ? (string) $event->note : null,
                ];
            })->all(),
        ];
    }

    public function decide(User $actor, Employee $employee, int $taskId, string $outcome, ?string $note): void
    {
        abort_unless($this->ready() && Schema::hasColumn('employee_record_events', 'note'), 404);
        abort_unless($actor->can('update', $employee), 403);
        abort_if((int) $actor->companyId() !== (int) $employee->tenant_id, 404);

        if (! in_array($outcome, ['completed', 'not_applicable', 'pending'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose a historical task outcome.']);
        }

        $note = trim((string) $note);

        if ($outcome !== 'pending' && $note === '') {
            throw ValidationException::withMessages(['note' => 'A decision note is required.']);
        }

        if (mb_strlen($note) > 5000) {
            throw ValidationException::withMessages(['note' => 'The decision note is too long.']);
        }

        DB::transaction(function () use ($actor, $employee, $taskId, $outcome, $note): void {
            $onboarding = DB::table('onboardings')
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();
            abort_if($onboarding === null, 404);

            $task = DB::table('onboarding_tasks')
                ->where('id', $taskId)
                ->where('onboarding_id', $onboarding->id)
                ->where('tenant_id', $employee->tenant_id)
                ->lockForUpdate()
                ->first();
            abort_if($task === null, 404);

            if ($task->code !== null && $task->code !== '') {
                throw ValidationException::withMessages(['task' => 'A required checklist task cannot be changed here.']);
            }

            $subject = 't'.$task->id.':'.$outcome;

            if (strlen($subject) > 40) {
                throw ValidationException::withMessages(['task' => 'This historical task cannot be recorded.']);
            }

            DB::table('onboarding_tasks')->where('id', $task->id)->update([
                'status' => $outcome,
                'completed_by' => $outcome === 'completed' ? $actor->id : null,
                'completed_at' => $outcome === 'completed' ? now() : null,
                'updated_at' => now(),
            ]);
            DB::table('employee_record_events')->insert([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'actor_id' => $actor->id,
                'action' => 'historical_decision',
                'subject' => $subject,
                'note' => $note === '' ? null : $note,
                'created_at' => now(),
            ]);
        });
    }

    private function activityDetail(string $action, ?string $subject): string
    {
        if ($action === 'historical_decision' && is_string($subject) && preg_match('/^t\d+:(completed|not_applicable|pending)$/', $subject, $match) === 1) {
            return match ($match[1]) {
                'completed' => 'Recorded as completed. This does not change the required checklist.',
                'not_applicable' => 'Recorded as not applicable. This does not change the required checklist.',
                default => 'Left open. This does not change the required checklist.',
            };
        }

        return $subject ?? '';
    }

    private function latestDecision(Employee $employee, int $taskId): ?object
    {
        return DB::table('employee_record_events')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('action', 'historical_decision')
            ->whereIn('subject', [
                't'.$taskId.':completed',
                't'.$taskId.':not_applicable',
                't'.$taskId.':pending',
            ])
            ->orderByDesc('id')
            ->first();
    }

    public function record(Employee $employee, User $actor, string $action, ?string $subject = null): void
    {
        if (! Schema::hasTable('employee_record_events')) {
            return;
        }

        DB::table('employee_record_events')->insert([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'subject' => $subject,
            'created_at' => now(),
        ]);
    }

    private function onboardingId(User $actor, Employee $employee, int $companyId): int
    {
        $existing = DB::table('onboardings')->where('employee_id', $employee->id)->lockForUpdate()->first();

        if ($existing !== null) {
            abort_if((int) $existing->tenant_id !== $companyId, 404);

            return (int) $existing->id;
        }

        return (int) DB::table('onboardings')->insertGetId([
            'tenant_id' => $companyId,
            'employee_id' => $employee->id,
            'status' => 'pending',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureTasks(int $companyId, int $onboardingId, int $actorId): void
    {
        $tasks = DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->lockForUpdate()->get();

        foreach (self::TASKS as $code => $title) {
            if ($tasks->contains(fn ($task) => (string) $task->code === $code)) {
                continue;
            }

            $matches = $tasks->filter(function ($task) use ($title) {
                return ($task->code === null || $task->code === '')
                    && mb_strtolower(trim((string) $task->title)) === mb_strtolower(trim($title));
            })->values();

            if ($matches->count() === 1) {
                DB::table('onboarding_tasks')->where('id', $matches[0]->id)->update([
                    'code' => $code,
                    'updated_at' => now(),
                ]);
                $matches[0]->code = $code;

                continue;
            }

            $completed = $code === 'profile';
            $id = DB::table('onboarding_tasks')->insertGetId([
                'tenant_id' => $companyId,
                'onboarding_id' => $onboardingId,
                'code' => $code,
                'title' => $title,
                'assignee_role' => 'HR',
                'status' => $completed ? 'completed' : 'pending',
                'completed_at' => $completed ? now() : null,
                'completed_by' => $completed ? $actorId : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $tasks->push((object) ['id' => $id, 'code' => $code, 'title' => $title]);
        }
    }

    private function welcome(User $actor, Employee $employee, int $onboardingId): void
    {
        $onboarding = DB::table('onboardings')->where('id', $onboardingId)->lockForUpdate()->first();

        if ($onboarding === null || $onboarding->welcomed_at !== null || ! Schema::hasTable('notifications')) {
            return;
        }

        $marker = '"employeeId":'.(int) $employee->id.',';
        $sent = DB::table('notifications')
            ->where('type', EmployeeJoinedNotification::class)
            ->where('data', 'like', '%'.$marker.'%')
            ->exists();

        if ($sent) {
            DB::table('onboardings')->where('id', $onboardingId)->update(['welcomed_at' => now(), 'updated_at' => now()]);

            return;
        }

        $employee->load(['department', 'designation', 'manager']);
        $companyId = (int) $employee->tenant_id;
        $designation = $employee->designation && (int) $employee->designation->tenant_id === $companyId ? $employee->designation->name : 'Not assigned';
        $department = $employee->department && (int) $employee->department->tenant_id === $companyId ? $employee->department->name : 'Not assigned';
        $manager = $employee->manager && (int) $employee->manager->tenant_id === $companyId
            ? trim($employee->manager->first_name.' '.$employee->manager->last_name)
            : 'Not assigned';
        $payload = [
            'employeeId' => (int) $employee->id,
            'title' => 'New employee joined',
            'message' => trim($employee->first_name.' '.$employee->last_name).' joined as '.$designation.' in '.$department.' on '.optional($employee->date_of_joining)->toDateString().'. Reporting manager: '.$manager.'.',
            'url' => route('hrms.employees.show', $employee),
        ];
        $notification = new EmployeeJoinedNotification($payload);

        foreach ($this->recipients($employee) as $user) {
            $user->notify($notification);
        }

        DB::table('onboardings')->where('id', $onboardingId)->update(['welcomed_at' => now(), 'updated_at' => now()]);
        $this->record($employee, $actor, 'welcome_notified', 'joined');
    }

    /**
     * @return list<User>
     */
    private function recipients(Employee $employee): array
    {
        $companyId = (int) $employee->tenant_id;
        $ids = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.tenant_id', $companyId)
            ->where('roles.tenant_id', $companyId)
            ->where('users.tenant_id', $companyId)
            ->whereIn('roles.name', ['Owner', 'HR'])
            ->pluck('users.id');

        if ($employee->manager && (int) $employee->manager->tenant_id === $companyId && $employee->manager->user_id) {
            $managerUser = User::query()->whereKey($employee->manager->user_id)->where('tenant_id', $companyId)->value('id');

            if ($managerUser) {
                $ids->push($managerUser);
            }
        }

        return User::query()->whereIn('id', $ids->unique()->all())->where('tenant_id', $companyId)->get()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(Employee $employee, bool $notes): array
    {
        return DB::table('employee_documents')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->get()
            ->map(function ($document) use ($employee, $notes): array {
                $reviewer = $document->reviewed_by
                    ? User::query()->whereKey($document->reviewed_by)->where('tenant_id', $employee->tenant_id)->value('name')
                    : null;

                return [
                    'id' => (int) $document->id,
                    'category' => EmployeeDocuments::label((string) $document->category),
                    'categoryValue' => (string) $document->category,
                    'status' => $this->label((string) $document->review_status),
                    'uploadedAt' => (string) $document->created_at,
                    'fileName' => EmployeeDocuments::mask((string) $document->original_name),
                    'reviewer' => $reviewer ? (string) $reviewer : null,
                    'reviewedAt' => $document->reviewed_at ? (string) $document->reviewed_at : null,
                    'note' => $notes && $document->review_note ? EmployeeDocuments::mask((string) $document->review_note) : null,
                ];
            })->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function categories(bool $manage): array
    {
        $categories = EmployeeDocuments::CATEGORIES;

        if (! $manage) {
            unset($categories['generated_offer']);
        }

        $options = [];

        foreach ($categories as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    private function label(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'not_applicable' => 'Not applicable',
            'under_review' => 'Under Review',
            'verified' => 'Verified',
            'rejected' => 'Rejected',
            default => 'Pending',
        };
    }
}
