<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

class PayrollRunPolicy
{
    public function view(User $user, PayrollRun $run): bool
    {
        return (int) $run->tenant_id === (int) $user->companyId()
            && in_array($user->applicationRole(), ['company_admin', 'accounts'], true);
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
