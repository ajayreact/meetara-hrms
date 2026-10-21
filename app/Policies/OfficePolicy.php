<?php

namespace App\Policies;

use App\Models\Office;
use App\Models\User;

class OfficePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Office $office): bool
    {
        return $this->manage($user) && (int) $office->tenant_id === $user->companyId();
    }

    private function manage(User $user): bool
    {
        return $user->companyId() !== null && in_array($user->applicationRole(), ['company_admin', 'hr'], true);
    }
}
