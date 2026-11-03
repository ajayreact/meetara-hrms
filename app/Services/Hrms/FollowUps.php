<?php

namespace App\Services\Hrms;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FollowUps
{
    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'follow_up' => 'Follow-up Required',
        'interested' => 'Interested',
        'not_interested' => 'Not Interested',
        'converted' => 'Converted',
    ];

    public function ready(): bool
    {
        return Schema::hasTable('followup_candidates') && Schema::hasTable('followup_reminders') && Schema::hasTable('followup_events');
    }

    /**
     * @return array{canManage: bool, candidates: list<array<string, mixed>>, owners: list<array{id: int, name: string}>}
     */
    public function page(User $actor): array
    {
        if (! $this->ready() || $actor->companyId() === null || ! $this->canOpen($actor)) {
            return ['canManage' => false, 'candidates' => [], 'owners' => []];
        }

        return [
            'canManage' => true,
            'candidates' => $this->rows($actor),
            'owners' => $this->owners($actor),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, int $ownerEmployeeId, array $input, ?int $candidateId = null): int
    {
        $this->assertReady($actor);
        $companyId = (int) $actor->companyId();
        $this->assertOwner($actor, $ownerEmployeeId);
        $email = strtolower(trim((string) $input['email']));
        $phone = $this->blank($input['phone'] ?? null);

        return DB::transaction(function () use ($actor, $companyId, $ownerEmployeeId, $input, $candidateId, $email, $phone): int {
            $existing = DB::table('followup_candidates')->where('tenant_id', $companyId)->where('email', $email)->lockForUpdate()->first();

            if ($existing !== null && (int) $existing->id !== (int) $candidateId) {
                throw ValidationException::withMessages(['email' => 'A candidate with this email is already recorded for this company.']);
            }

            if ($phone !== null) {
                $samePhone = DB::table('followup_candidates')
                    ->where('tenant_id', $companyId)
                    ->where('phone', $phone)
                    ->when($candidateId !== null, fn ($query) => $query->where('id', '!=', $candidateId))
                    ->exists();

                if ($samePhone) {
                    throw ValidationException::withMessages(['phone' => 'A candidate with this phone number is already recorded for this company.']);
                }
            }

            $values = [
                'owner_employee_id' => $ownerEmployeeId,
                'name' => trim((string) $input['name']),
                'email' => $email,
                'phone' => $phone,
                'university' => $this->blank($input['university'] ?? null),
                'state' => $this->blank($input['state'] ?? null),
                'remarks' => $this->blank($input['remarks'] ?? null),
                'follow_up_on' => $this->blank($input['follow_up_on'] ?? null),
                'status' => array_key_exists((string) $input['status'], self::STATUSES) ? $input['status'] : 'new',
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ];

            if ($candidateId === null) {
                $id = (int) DB::table('followup_candidates')->insertGetId([
                    ...$values,
                    'tenant_id' => $companyId,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);
                $this->event($companyId, $id, $actor, 'created', 'Candidate recorded.');
            } else {
                $row = $this->lockVisible($actor, $candidateId);
                DB::table('followup_candidates')->where('id', $row->id)->update($values);
                $this->event($companyId, (int) $row->id, $actor, 'updated', 'Candidate details updated.');
                $id = (int) $row->id;
            }

            $this->storeReminder($actor, $id, $input['remind_at'] ?? null, $input['reminder_notes'] ?? null);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function remind(User $actor, int $candidateId, array $input): void
    {
        $this->assertReady($actor);
        $row = $this->lockVisible($actor, $candidateId);
        $this->storeReminder($actor, (int) $row->id, $input['remind_at'] ?? null, $input['notes'] ?? null);
    }

    public function complete(User $actor, int $reminderId): void
    {
        $this->assertReady($actor);
        DB::transaction(function () use ($actor, $reminderId): void {
            $reminder = $this->lockReminder($actor, $reminderId);

            if ($reminder->status === 'completed') {
                return;
            }

            DB::table('followup_reminders')->where('id', $reminder->id)->update([
                'status' => 'completed',
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event((int) $reminder->tenant_id, (int) $reminder->candidate_id, $actor, 'reminder_completed', 'Reminder marked completed.');
        });
    }

    public function reschedule(User $actor, int $reminderId, string $remindAt, ?string $notes): void
    {
        $this->assertReady($actor);
        DB::transaction(function () use ($actor, $reminderId, $remindAt, $notes): void {
            $reminder = $this->lockReminder($actor, $reminderId);

            if ($reminder->status !== 'open') {
                throw ValidationException::withMessages(['reminder' => 'A completed reminder cannot be rescheduled. Add a new reminder.']);
            }

            DB::table('followup_reminders')->where('id', $reminder->id)->update([
                'remind_at' => Carbon::parse($remindAt),
                'notes' => $this->blank($notes) ?? $reminder->notes,
                'updated_at' => now(),
            ]);
            $this->event((int) $reminder->tenant_id, (int) $reminder->candidate_id, $actor, 'reminder_rescheduled', 'Reminder rescheduled.');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reminderFeed(User $actor): array
    {
        if (! $this->ready() || ! $this->canOpen($actor)) {
            return [];
        }

        $zone = $this->zone($actor);
        $today = Carbon::now($zone)->startOfDay();
        $ownerIds = $this->ownerIds($actor);
        $query = DB::table('followup_reminders')
            ->join('followup_candidates', 'followup_candidates.id', '=', 'followup_reminders.candidate_id')
            ->join('employees', 'employees.id', '=', 'followup_candidates.owner_employee_id')
            ->where('followup_reminders.tenant_id', $actor->companyId())
            ->where('followup_reminders.status', 'open');

        if ($ownerIds !== null) {
            $query->whereIn('followup_candidates.owner_employee_id', $ownerIds);
        }

        return $query
            ->orderBy('followup_reminders.remind_at')
            ->limit(40)
            ->get([
                'followup_reminders.id',
                'followup_reminders.remind_at',
                'followup_reminders.notes',
                'followup_candidates.id as candidate_id',
                'followup_candidates.name',
                'followup_candidates.phone',
                'followup_candidates.status',
                'employees.first_name',
                'employees.last_name',
            ])
            ->map(function ($row) use ($zone, $today): array {
                $when = Carbon::parse($row->remind_at)->timezone($zone);

                return [
                    'id' => (int) $row->id,
                    'candidateId' => (int) $row->candidate_id,
                    'name' => (string) $row->name,
                    'phone' => (string) ($row->phone ?? ''),
                    'when' => $when->format('Y-m-d H:i'),
                    'notes' => (string) ($row->notes ?? ''),
                    'owner' => trim($row->first_name.' '.$row->last_name),
                    'status' => self::STATUSES[$row->status] ?? (string) $row->status,
                    'bucket' => $when->lt($today) ? 'overdue' : ($when->isSameDay($today) ? 'today' : 'upcoming'),
                ];
            })->all();
    }

    /**
     * @return array{pending: int, overdue: int}
     */
    public function counts(User $actor): array
    {
        $feed = $this->reminderFeed($actor);

        return [
            'pending' => count($feed),
            'overdue' => count(array_filter($feed, fn (array $row): bool => $row['bucket'] === 'overdue')),
        ];
    }

    private function storeReminder(User $actor, int $candidateId, mixed $remindAt, mixed $notes): void
    {
        $when = $this->blank($remindAt);

        if ($when === null) {
            return;
        }

        $companyId = (int) $actor->companyId();
        DB::table('followup_reminders')->insert([
            'tenant_id' => $companyId,
            'candidate_id' => $candidateId,
            'remind_at' => Carbon::parse($when),
            'notes' => $this->blank($notes),
            'status' => 'open',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event($companyId, $candidateId, $actor, 'reminder_added', 'Reminder added.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(User $actor): array
    {
        $ownerIds = $this->ownerIds($actor);
        $query = DB::table('followup_candidates')->where('tenant_id', $actor->companyId())->orderByDesc('id')->limit(80);

        if ($ownerIds !== null) {
            $query->whereIn('owner_employee_id', $ownerIds);
        }

        return $query->get()->map(function ($row): array {
            $reminders = DB::table('followup_reminders')->where('candidate_id', $row->id)->orderBy('remind_at')->get();
            $next = $reminders->first(fn ($reminder) => $reminder->status === 'open');
            $events = DB::table('followup_events')->where('candidate_id', $row->id)->orderByDesc('id')->limit(8)->get();

            return [
                'id' => (int) $row->id,
                'ownerEmployeeId' => (int) $row->owner_employee_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'phone' => (string) ($row->phone ?? ''),
                'university' => (string) ($row->university ?? ''),
                'state' => (string) ($row->state ?? ''),
                'remarks' => (string) ($row->remarks ?? ''),
                'followUpOn' => $row->follow_up_on ? (string) $row->follow_up_on : '',
                'nextReminder' => $next ? (string) $next->remind_at : null,
                'status' => (string) $row->status,
                'statusLabel' => self::STATUSES[$row->status] ?? (string) $row->status,
                'reminders' => $reminders->map(fn ($reminder) => [
                    'id' => (int) $reminder->id,
                    'at' => (string) $reminder->remind_at,
                    'notes' => (string) ($reminder->notes ?? ''),
                    'status' => (string) $reminder->status,
                ])->all(),
                'history' => $events->map(fn ($event) => [
                    'action' => (string) $event->action,
                    'note' => (string) ($event->note ?? ''),
                    'at' => (string) $event->created_at,
                ])->all(),
            ];
        })->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function owners(User $actor): array
    {
        $ids = $this->ownerIds($actor);
        $query = DB::table('employees')->where('tenant_id', $actor->companyId())->where('status', 'active')->orderBy('first_name');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get(['id', 'first_name', 'last_name', 'employee_code'])->map(fn ($employee) => [
            'id' => (int) $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name).' · '.$employee->employee_code,
        ])->all();
    }

    /**
     * Null means every employee in the company.
     *
     * @return list<int>|null
     */
    private function ownerIds(User $actor): ?array
    {
        $role = $actor->applicationRole();

        if (in_array($role, ['company_admin', 'hr'], true)) {
            return null;
        }

        $self = $this->employeeId($actor);

        if ($role === 'team_lead') {
            $reports = $self === null
                ? []
                : DB::table('employees')->where('tenant_id', $actor->companyId())->where('manager_id', $self)->pluck('id')->map(fn ($id) => (int) $id)->all();

            return array_values(array_unique(array_filter([$self, ...$reports])));
        }

        return $self === null ? [] : [$self];
    }

    private function assertOwner(User $actor, int $ownerEmployeeId): void
    {
        $owner = DB::table('employees')->where('tenant_id', $actor->companyId())->where('id', $ownerEmployeeId)->first();
        abort_if($owner === null, 404);
        $allowed = $this->ownerIds($actor);

        if ($allowed !== null && ! in_array($ownerEmployeeId, $allowed, true)) {
            abort(404);
        }
    }

    private function lockVisible(User $actor, int $candidateId): object
    {
        $row = DB::table('followup_candidates')->where('tenant_id', $actor->companyId())->where('id', $candidateId)->lockForUpdate()->first();
        abort_if($row === null, 404);
        $allowed = $this->ownerIds($actor);

        if ($allowed !== null && ! in_array((int) $row->owner_employee_id, $allowed, true)) {
            abort(404);
        }

        return $row;
    }

    private function lockReminder(User $actor, int $reminderId): object
    {
        $reminder = DB::table('followup_reminders')->where('tenant_id', $actor->companyId())->where('id', $reminderId)->lockForUpdate()->first();
        abort_if($reminder === null, 404);
        $this->lockVisible($actor, (int) $reminder->candidate_id);

        return $reminder;
    }

    private function canOpen(User $actor): bool
    {
        return $actor->companyId() !== null && in_array($actor->applicationRole(), ['company_admin', 'hr', 'team_lead', 'employee'], true);
    }

    private function assertReady(User $actor): void
    {
        abort_unless($this->ready() && $this->canOpen($actor), 404);
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

    private function event(int $companyId, int $candidateId, User $actor, string $action, string $note): void
    {
        DB::table('followup_events')->insert([
            'tenant_id' => $companyId,
            'candidate_id' => $candidateId,
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
