<?php

namespace App\Policies;

use App\Models\PayrollPayment;
use App\Models\User;

class PayrollPaymentPolicy
{
    public function manage(User $user, ?PayrollPayment $payment = null): bool
    {
        return $user->applicationRole() === 'accounts'
            && ($payment === null || (int) $payment->tenant_id === (int) $user->companyId());
    }
}
