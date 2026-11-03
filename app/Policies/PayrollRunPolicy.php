<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

class PayrollRunPolicy
{
    public function view(User $user, PayrollRun $run): bool
    {
        if ((int) $run->tenant_id !== (int) $user->companyId()) {
            return false;
        }

        if (in_array($user->applicationRole(), ['company_admin', 'accounts'], true)) {
            return true;
        }

        return $user->applicationRole() === 'hr' && ($run->source ?? 'calculated') === 'import';
    }

    public function import(User $user, ?PayrollRun $run = null): bool
    {
        return in_array($user->applicationRole(), ['accounts', 'hr'], true)
            && ($run === null || (int) $run->tenant_id === (int) $user->companyId());
    }

    public function prepare(User $user, ?PayrollRun $run = null): bool
    {
        return $user->applicationRole() === 'accounts'
            && ($run === null || (int) $run->tenant_id === (int) $user->companyId());
    }

    public function approve(User $user, PayrollRun $run): bool
    {
        return $user->applicationRole() === 'company_admin' && (int) $run->tenant_id === (int) $user->companyId();
    }
}
