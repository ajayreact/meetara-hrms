<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    public function view(User $user, Holiday $holiday): bool
    {
        return (int) $holiday->tenant_id === (int) $user->companyId();
    }

    public function manage(User $user, ?Holiday $holiday = null): bool
    {
        if (! in_array($user->applicationRole(), ['company_admin', 'hr'], true)) {
            return false;
        }

        return $holiday === null || (int) $holiday->tenant_id === (int) $user->companyId();
    }
}
