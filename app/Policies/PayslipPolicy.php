<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\User;

class PayslipPolicy
{
    public function view(User $user, PayrollItem $item): bool
    {
        if ((int) $item->tenant_id !== (int) $user->companyId() || $item->run?->status !== 'processed') {
            return false;
        }

        if (in_array($user->applicationRole(), ['company_admin', 'accounts', 'hr'], true)) {
            return true;
        }

        if ($user->applicationRole() === 'super_admin' || ! in_array($user->applicationRole(), ['employee', 'team_lead'], true)) {
            return false;
        }

        $employeeId = Employee::query()->where('user_id', $user->id)->where('tenant_id', $user->companyId())->value('id');

        return $employeeId !== null && (int) $employeeId === (int) $item->employee_id;
    }
}
