<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountStatus
{
    /**
     * @var list<string>
     */
    private const BLOCKED_EMPLOYEE_STATUSES = [
        'inactive',
        'deactivated',
        'terminated',
        'exited',
        'suspended',
        'invited',
    ];

    public static function isBlockedEmployeeStatus(?string $status): bool
    {
        if ($status === null || $status === '') {
            return false;
        }

        return in_array(strtolower($status), self::BLOCKED_EMPLOYEE_STATUSES, true);
    }

    public static function denialReason(User $user): ?string
    {
        $companyId = $user->companyId();
        if ($companyId !== null && Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'status')) {
            $companyStatus = DB::table('tenants')->where('id', $companyId)->value('status');
            if ($companyStatus !== null && $companyStatus !== 'active') {
                return 'This company is not active.';
            }
        }

        if (Schema::hasColumn('users', 'invited_at') && Schema::hasColumn('users', 'invite_accepted_at')) {
            if ($user->invited_at !== null && $user->invite_accepted_at === null) {
                return 'This account is not active.';
            }
        }

        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'status') || ! Schema::hasColumn('employees', 'user_id')) {
            return null;
        }

        $status = DB::table('employees')->where('user_id', $user->id)->value('status');

        if (self::isBlockedEmployeeStatus(is_string($status) ? $status : null)) {
            return 'This account is not active.';
        }

        return null;
    }
}
