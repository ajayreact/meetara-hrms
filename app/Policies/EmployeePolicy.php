<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->companyId() !== null && in_array($user->applicationRole(), ['company_admin', 'hr', 'team_lead'], true);
    }

    public function view(User $user, Employee $employee): bool
    {
        if (! $this->sameCompany($user, $employee)) {
            return false;
        }

        return match ($user->applicationRole()) {
            'company_admin', 'hr' => true,
            'team_lead' => $this->isSelfOrDirectReport($user, $employee),
            'employee' => (int) $employee->user_id === (int) $user->id,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->companyId() !== null && in_array($user->applicationRole(), ['company_admin', 'hr'], true);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->create($user) && $this->sameCompany($user, $employee);
    }

    public function export(User $user): bool
    {
        return $this->create($user);
    }

    private function sameCompany(User $user, Employee $employee): bool
    {
        return $user->companyId() !== null && (int) $employee->tenant_id === $user->companyId();
    }

    private function isSelfOrDirectReport(User $user, Employee $employee): bool
    {
        $actor = Employee::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $user->companyId())
            ->first();

        if ($actor === null) {
            return false;
        }

        return (int) $employee->id === (int) $actor->id
            || (int) $employee->manager_id === (int) $actor->id;
    }
}
