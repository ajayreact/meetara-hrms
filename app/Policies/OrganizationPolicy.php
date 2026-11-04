<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->manage($user) && (int) $record->getAttribute('tenant_id') === $user->companyId();
    }

    private function manage(User $user): bool
    {
        return $user->companyId() !== null && in_array($user->applicationRole(), ['company_admin', 'hr'], true);
    }
}
