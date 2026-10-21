<?php

namespace App\Policies;

use App\Models\SalaryStructure;
use App\Models\User;

class SalaryStructurePolicy
{
    public function manage(User $user, ?SalaryStructure $structure = null): bool
    {
        if (! in_array($user->applicationRole(), ['company_admin', 'hr', 'accounts'], true)) {
            return false;
        }

        return $structure === null || (int) $structure->tenant_id === (int) $user->companyId();
    }
}
