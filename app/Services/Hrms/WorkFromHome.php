<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkFromHome
{
    public function allows(Employee $employee, string $date): bool
    {
        $mode = (string) $employee->work_mode;

        if ($mode === 'remote') {
            return true;
        }

        if ($mode !== 'hybrid' || ! Schema::hasTable('wfh_requests')) {
            return false;
        }

        return DB::table('wfh_requests')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->where('status', 'approved')
            ->exists();
    }

    public function label(?string $mode): string
    {
        return match ($mode) {
            'wfh', 'work_from_home' => 'Work From Home',
            'remote' => 'Remote',
            'hybrid' => 'Hybrid',
            default => 'Office',
        };
    }

    public function request(User $actor, string $date, string $reason): void
    {
        $employee = $this->employee($actor);

        if ((string) $employee->work_mode !== 'hybrid') {
            throw new AttendanceRejected('A daily work-from-home request is only for hybrid employees. Office attendance still uses the approved office network.');
        }

        $reason = trim($reason);

        if (strlen($reason) < 8 || strlen($reason) > 191) {
            throw new AttendanceRejected('Enter a reason between 8 and 191 characters.');
        }

        $timezone = $this->timezone((int) $employee->tenant_id);
        try {
            $day = Carbon::parse($date, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw new AttendanceRejected('Choose a valid work date.');
        }

        $today = now()->timezone($timezone)->startOfDay();

        if ($day->lt($today) || $day->gt($today->copy()->addDays(60))) {
            throw new AttendanceRejected('Choose a work date from today through the next 60 days.');
        }

        if (! Schema::hasTable('wfh_requests')) {
            throw new AttendanceRejected('Work-from-home requests are not available yet.');
        }

        $exists = DB::table('wfh_requests')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $day->toDateString())
            ->exists();

        if ($exists) {
            throw new AttendanceRejected('A work-from-home request already exists for that date.');
        }

        DB::table('wfh_requests')->insert([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'work_date' => $day->toDateString(),
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function review(User $actor, int $requestId, string $decision): void
    {
        if ($actor->companyId() === null || ! in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            throw new AttendanceRejected('You cannot review work-from-home requests.');
        }

        if (! in_array($decision, ['approved', 'rejected'], true) || ! Schema::hasTable('wfh_requests')) {
            throw new AttendanceRejected('This work-from-home request cannot be reviewed.');
        }

        $row = DB::table('wfh_requests')
            ->where('id', $requestId)
            ->where('tenant_id', $actor->companyId())
            ->where('status', 'pending')
            ->first();

        if ($row === null) {
            throw new AttendanceRejected('This work-from-home request is no longer pending.');
        }

        $workDate = substr((string) $row->work_date, 0, 10);
        $today = now()->timezone($this->timezone((int) $actor->companyId()))->toDateString();

        if ($decision === 'approved' && $workDate < $today) {
            throw new AttendanceRejected('A past work-from-home date cannot be approved.');
        }

        $updated = DB::table('wfh_requests')
            ->where('id', $row->id)
            ->where('tenant_id', $actor->companyId())
            ->where('status', 'pending')
            ->update([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new AttendanceRejected('This work-from-home request is no longer pending.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visible(User $actor): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null || ! Schema::hasTable('wfh_requests')) {
            return [];
        }

        if (in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            return $this->rows((int) $companyId, null);
        }

        if ($actor->applicationRole() !== 'team_lead') {
            return [];
        }

        $leadId = DB::table('employees')
            ->where('tenant_id', $companyId)
            ->where('user_id', $actor->id)
            ->value('id');

        if ($leadId === null) {
            return [];
        }

        $teamIds = DB::table('employees')
            ->where('tenant_id', $companyId)
            ->where(function ($query) use ($leadId): void {
                $query->where('id', $leadId)->orWhere('manager_id', $leadId);
            })
            ->pluck('id');

        return $this->rows((int) $companyId, null, $teamIds->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mine(User $actor): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null || ! Schema::hasTable('wfh_requests')) {
            return [];
        }

        $employeeId = DB::table('employees')
            ->where('tenant_id', $companyId)
            ->where('user_id', $actor->id)
            ->value('id');

        if ($employeeId === null) {
            return [];
        }

        return $this->rows((int) $companyId, (int) $employeeId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<int>|null  $employeeIds
     * @return list<array<string, mixed>>
     */
    private function rows(int $companyId, ?int $employeeId, ?array $employeeIds = null): array
    {
        $query = DB::table('wfh_requests')
            ->join('employees', function ($join) use ($companyId): void {
                $join->on('employees.id', '=', 'wfh_requests.employee_id')
                    ->where('employees.tenant_id', '=', $companyId);
            })
            ->where('wfh_requests.tenant_id', $companyId)
            ->orderByDesc('wfh_requests.work_date')
            ->limit(30);

        if ($employeeId !== null) {
            $query->where('wfh_requests.employee_id', $employeeId);
        } elseif ($employeeIds !== null) {
            $query->whereIn('wfh_requests.employee_id', $employeeIds);
        } else {
            $query->where('wfh_requests.status', 'pending');
        }

        return $query->get([
            'wfh_requests.id',
            'wfh_requests.work_date',
            'wfh_requests.reason',
            'wfh_requests.status',
            'employees.first_name',
            'employees.last_name',
        ])->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'date' => substr((string) $row->work_date, 0, 10),
            'reason' => (string) $row->reason,
            'status' => (string) $row->status,
            'employee' => trim($row->first_name.' '.$row->last_name),
        ])->all();
    }

    private function employee(User $actor): Employee
    {
        $companyId = $actor->companyId();
        $employee = $companyId === null ? null : Employee::query()
            ->where('user_id', $actor->id)
            ->where('tenant_id', $companyId)
            ->first();

        if ($employee === null || $employee->status !== 'active') {
            throw new AttendanceRejected('This account cannot request work from home.');
        }

        return $employee;
    }

    private function timezone(int $tenantId): string
    {
        $timezone = DB::table('tenants')->where('id', $tenantId)->value('timezone');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('attendance.fallback_timezone', 'Asia/Kolkata');
    }
}
