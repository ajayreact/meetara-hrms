<?php

namespace App\Support;

use App\Auth\CompanyRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthenticatedWorkspace
{
    /**
     * Build the signed-in workspace from the user record.
     * Client-supplied roles are ignored.
     *
     * @return array<string, mixed>
     */
    public static function resolve(User $user): array
    {
        $platformAdmin = $user->isPlatformAdmin();
        $roleNames = $user->storedRoleNames();
        $resolved = CompanyRole::highest($roleNames, $platformAdmin);
        $employee = self::employee($user);

        return [
            'interfacePreview' => true,
            'role' => $resolved['role'],
            'roleLabel' => $resolved['roleLabel'],
            'companyName' => self::companyName($user, $platformAdmin),
            'userName' => $user->name,
            'designation' => $employee['designation'] ?? $resolved['roleLabel'],
            'email' => $user->email,
            'officeName' => $employee['officeName'] ?? null,
            'officeRegion' => $employee['officeRegion'] ?? null,
            'authenticated' => true,
            'authName' => $user->name,
            'authEmail' => $user->email,
            'permissions' => CompanyRole::permissions($resolved['role']),
        ];
    }

    private static function companyName(User $user, bool $platformAdmin): ?string
    {
        if ($platformAdmin || ! Schema::hasTable('tenants') || ! Schema::hasColumn('users', 'tenant_id') || $user->tenant_id === null) {
            return null;
        }

        $name = DB::table('tenants')->where('id', $user->tenant_id)->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return array{designation: ?string, officeName: ?string, officeRegion: ?string}|null
     */
    private static function employee(User $user): ?array
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'user_id')) {
            return null;
        }

        $employee = DB::table('employees')->where('user_id', $user->id)->first();

        if ($employee === null) {
            return null;
        }

        if (Schema::hasColumn('users', 'tenant_id') && Schema::hasColumn('employees', 'tenant_id') && $user->tenant_id !== null && (int) $employee->tenant_id !== (int) $user->tenant_id) {
            return null;
        }

        $designation = null;

        if (isset($employee->designation_id) && Schema::hasTable('designations')) {
            $designationQuery = DB::table('designations')->where('id', $employee->designation_id);

            if (Schema::hasColumn('designations', 'tenant_id')) {
                $designationQuery->where('tenant_id', $employee->tenant_id);
            }

            $designation = $designationQuery->value('name');
        }

        $officeName = null;

        if (isset($employee->location_id) && Schema::hasTable('locations')) {
            $officeName = DB::table('locations')->where('id', $employee->location_id)->where('tenant_id', $employee->tenant_id)->value('name');
        }

        $region = null;

        if (isset($employee->holiday_calendar)) {
            $region = match (strtolower((string) $employee->holiday_calendar)) {
                'usa', 'us', 'united states' => 'United States',
                'india', 'in' => 'India',
                default => null,
            };
        }

        return [
            'designation' => is_string($designation) ? $designation : null,
            'officeName' => is_string($officeName) ? $officeName : null,
            'officeRegion' => $region,
        ];
    }
}
