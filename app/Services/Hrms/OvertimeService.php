<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OvertimeService
{
    public function __construct(private readonly CompanyNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function request(User $actor, array $input): int
    {
        $employee = $this->ownEmployee($actor);
        abort_if($employee === null, 403);
        $minutes = $this->minutes((string) $input['hours']);
        $date = (string) $input['work_date'];

        $duplicate = DB::table('overtime_entries')
            ->where('tenant_id', $actor->companyId())
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        abort_if($duplicate, 422);

        return DB::transaction(function () use ($actor, $employee, $input, $minutes, $date): int {
            $id = DB::table('overtime_entries')->insertGetId([
                'tenant_id' => $actor->companyId(),
                'employee_id' => $employee->id,
                'work_date' => $date,
                'minutes' => $minutes,
                'reason' => $input['reason'],
                'status' => 'pending',
                'payroll_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event((int) $actor->companyId(), $id, $actor->id, 'requested');

            return $id;
        });
    }

    public function decide(User $actor, int $entryId, string $decision): void
    {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        $entry = $this->visible($actor, $entryId);
        abort_unless($entry->status === 'pending', 422);
        abort_unless($this->canReview($actor, $entry), 403);

        DB::transaction(function () use ($actor, $entry, $decision): void {
            DB::table('overtime_entries')->where('id', $entry->id)->update([
                'status' => $decision,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'payroll_status' => 'pending',
                'updated_at' => now(),
            ]);
            $this->event((int) $entry->tenant_id, (int) $entry->id, $actor->id, $decision);
            $userId = (int) DB::table('employees')->where('id', $entry->employee_id)->value('user_id');
            DB::afterCommit(fn () => $this->notifier->once(
                $userId,
                'overtime-'.$entry->id.'-'.$decision,
                'Overtime request '.$decision,
                'Your overtime request for '.$entry->work_date.' was '.$decision.'. Pay was not calculated.',
                '/overtime'
            ));
        });
    }

    public function cancel(User $actor, int $entryId): void
    {
        $entry = $this->visible($actor, $entryId);
        $owns = (int) $this->ownEmployee($actor)?->id === (int) $entry->employee_id;
        $manager = in_array($actor->applicationRole(), ['company_admin', 'hr'], true);
        $allowed = ($entry->status === 'pending' && ($owns || $manager)) || ($entry->status === 'approved' && $manager);
        abort_unless($allowed, 403);

        DB::transaction(function () use ($actor, $entry): void {
            DB::table('overtime_entries')->where('id', $entry->id)->update([
                'status' => 'cancelled',
                'payroll_status' => 'pending',
                'updated_at' => now(),
            ]);
            $this->event((int) $entry->tenant_id, (int) $entry->id, $actor->id, 'cancelled');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor): array
    {
        if ($actor->companyId() === null || ! Schema::hasTable('overtime_entries')) {
            return [];
        }

        return $this->scoped($actor)->orderByDesc('overtime_entries.id')->limit(50)->get()->map(function ($entry): array {
            $name = trim(($entry->first_name ?? '').' '.($entry->last_name ?? ''));

            return [
                'id' => (int) $entry->id,
                'employee' => $name !== '' ? $name : 'Employee',
                'date' => (string) $entry->work_date,
                'requestedHours' => $this->hours((int) $entry->minutes),
                'status' => ucfirst((string) $entry->status),
                'rawStatus' => $entry->status,
                'reason' => $entry->reason,
                'payroll' => 'Not calculated',
            ];
        })->all();
    }

    public function canReviewList(User $actor): bool
    {
        return in_array($actor->applicationRole(), ['company_admin', 'hr', 'team_lead'], true);
    }

    private function minutes(string $hours): int
    {
        abort_unless(preg_match('/^\d+(\.5)?$/', $hours) === 1, 422);
        $minutes = (int) round(((float) $hours) * 60);
        abort_unless($minutes >= 30 && $minutes <= 720, 422);

        return $minutes;
    }

    private function hours(int $minutes): string
    {
        $whole = intdiv($minutes, 60);
        $half = $minutes % 60 === 30;

        return $half ? $whole.'.5' : (string) $whole;
    }

    private function canReview(User $actor, object $entry): bool
    {
        if ((int) $this->ownEmployee($actor)?->id === (int) $entry->employee_id) {
            return false;
        }

        if (in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            return true;
        }

        if ($actor->applicationRole() !== 'team_lead') {
            return false;
        }

        $lead = $this->ownEmployee($actor);

        return $lead !== null && (int) DB::table('employees')->where('id', $entry->employee_id)->value('manager_id') === (int) $lead->id;
    }

    private function visible(User $actor, int $entryId): object
    {
        $entry = $this->scoped($actor)->where('overtime_entries.id', $entryId)->first();
        abort_if($entry === null, 404);

        return $entry;
    }

    private function scoped(User $actor)
    {
        $query = DB::table('overtime_entries')
            ->join('employees', 'employees.id', '=', 'overtime_entries.employee_id')
            ->where('overtime_entries.tenant_id', $actor->companyId())
            ->select('overtime_entries.*', 'employees.first_name', 'employees.last_name', 'employees.manager_id');

        if (in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            return $query;
        }

        $employee = $this->ownEmployee($actor);
        $query->where(function ($inner) use ($actor, $employee): void {
            if ($employee !== null) {
                $inner->where('overtime_entries.employee_id', $employee->id);
                if ($actor->applicationRole() === 'team_lead') {
                    $inner->orWhere('employees.manager_id', $employee->id);
                }
            } else {
                $inner->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    private function ownEmployee(User $actor): ?Employee
    {
        if ($actor->companyId() === null) {
            return null;
        }

        return Employee::query()->where('user_id', $actor->id)->where('tenant_id', $actor->companyId())->first();
    }

    private function event(int $companyId, int $entryId, int $actorId, string $action): void
    {
        if (! Schema::hasTable('overtime_events')) {
            return;
        }

        DB::table('overtime_events')->insert([
            'tenant_id' => $companyId,
            'overtime_entry_id' => $entryId,
            'actor_id' => $actorId,
            'action' => $action,
            'created_at' => now(),
        ]);
    }
}
