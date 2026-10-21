<?php

namespace App\Policies;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->companyId() !== null && in_array($user->applicationRole(), ['company_admin', 'hr', 'team_lead'], true);
    }

    public function view(User $user, AttendanceLog $log): bool
    {
        if ($user->companyId() === null || (int) $log->tenant_id !== $user->companyId()) {
            return false;
        }

        return match ($user->applicationRole()) {
            'company_admin', 'hr' => true,
            'team_lead' => $this->onTeam($user, (int) $log->employee_id),
            'employee' => $this->owns($user, (int) $log->employee_id),
            default => false,
        };
    }

    public function correct(User $user, AttendanceLog $log): bool
    {
        return $user->companyId() !== null
            && (int) $log->tenant_id === $user->companyId()
            && in_array($user->applicationRole(), ['company_admin', 'hr'], true);
    }

    public function export(User $user): bool
    {
        return $user->companyId() !== null && in_array($user->applicationRole(), ['company_admin', 'hr'], true);
    }

    private function owns(User $user, int $employeeId): bool
    {
        return Employee::query()
            ->whereKey($employeeId)
            ->where('tenant_id', $user->companyId())
            ->where('user_id', $user->id)
            ->exists();
    }

    private function onTeam(User $user, int $employeeId): bool
    {
        $actor = Employee::query()->where('user_id', $user->id)->where('tenant_id', $user->companyId())->first();

        if ($actor === null) {
            return false;
        }

        return Employee::query()
            ->whereKey($employeeId)
            ->where('tenant_id', $user->companyId())
            ->where(function ($query) use ($actor): void {
                $query->where('id', $actor->id)->orWhere('manager_id', $actor->id);
            })
            ->exists();
    }
}
