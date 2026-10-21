<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $request): bool
    {
        if ((int) $request->tenant_id !== (int) $user->companyId()) {
            return false;
        }

        $own = $this->employee($user);

        if ($own !== null && (int) $request->employee_id === (int) $own->id) {
            return true;
        }

        if (in_array($user->applicationRole(), ['company_admin', 'hr'], true)) {
            return true;
        }

        if ($user->applicationRole() !== 'team_lead' || $own === null) {
            return false;
        }

        return Employee::query()->whereKey($request->employee_id)->where('manager_id', $own->id)->exists();
    }

    public function review(User $user, LeaveRequest $request): bool
    {
        $own = $this->employee($user);

        return in_array($user->applicationRole(), ['company_admin', 'hr', 'team_lead'], true)
            && $this->view($user, $request)
            && ($own === null || (int) $request->employee_id !== (int) $own->id);
    }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        if (! $this->view($user, $request)) {
            return false;
        }

        $own = $this->employee($user);

        if ($own !== null && (int) $request->employee_id === (int) $own->id) {
            return true;
        }

        return in_array($user->applicationRole(), ['company_admin', 'hr'], true);
    }

    private function employee(User $user): ?Employee
    {
        if ($user->companyId() === null) {
            return null;
        }

        return Employee::query()->where('user_id', $user->id)->where('tenant_id', $user->companyId())->first();
    }
}
