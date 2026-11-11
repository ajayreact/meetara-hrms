<?php

namespace App\Auth;

class CompanyRole
{
    /**
     * Map a stored role name onto the six application roles.
     * A missing or unknown role receives employee access only.
     *
     * @return array{role: string, roleLabel: string}
     */
    public static function fromStored(?string $roleName, bool $platformAdmin): array
    {
        if ($platformAdmin) {
            return ['role' => 'super_admin', 'roleLabel' => 'Super Admin'];
        }

        return match ($roleName) {
            'Owner' => ['role' => 'company_admin', 'roleLabel' => 'Head of Operations'],
            'HR' => ['role' => 'hr', 'roleLabel' => 'HR & Assistant Operations'],
            'Accounts' => ['role' => 'accounts', 'roleLabel' => 'Accounts'],
            'Company Manager' => ['role' => 'team_lead', 'roleLabel' => 'Company Manager'],
            'Manager' => ['role' => 'team_lead', 'roleLabel' => 'Team Lead'],
            'Employee' => ['role' => 'employee', 'roleLabel' => 'Employee'],
            default => ['role' => 'employee', 'roleLabel' => 'Employee'],
        };
    }

    /**
     * @param  list<string>  $roleNames
     */
    public static function highest(?array $roleNames, bool $platformAdmin): array
    {
        if ($platformAdmin) {
            return self::fromStored(null, true);
        }

        $priority = ['Owner', 'HR', 'Accounts', 'Company Manager', 'Manager', 'Employee'];

        foreach ($priority as $name) {
            if (in_array($name, $roleNames ?? [], true)) {
                return self::fromStored($name, false);
            }
        }

        return self::fromStored(null, false);
    }

    /**
     * @return array<string, bool>
     */
    public static function permissions(string $role): array
    {
        $none = [
            'employees' => false,
            'settings' => false,
            'routeMap' => false,
            'attendanceManage' => false,
            'attendanceCorrect' => false,
            'leaveApprove' => false,
            'payroll' => false,
            'salaryManage' => false,
            'payrollView' => false,
            'payrollApprove' => false,
            'payrollPay' => false,
            'payrollImport' => false,
            'offices' => false,
            'exportAttendance' => false,
            'companies' => false,
            'audit' => false,
            'i983Sign' => false,
            'leadsViewAll' => false,
            'leadsImport' => false,
            'leadsAssignTeam' => false,
            'leadsDistribute' => false,
            'leadsFollowUpTeam' => false,
            'leadsFollowUpOwn' => false,
            'leadsReport' => false,
        ];

        return match ($role) {
            'super_admin' => array_merge($none, [
                'settings' => true,
                'routeMap' => true,
                'companies' => true,
                'audit' => true,
            ]),
            'company_admin' => array_merge($none, [
                'employees' => true,
                'settings' => true,
                'routeMap' => true,
                'attendanceManage' => true,
                'attendanceCorrect' => true,
                'leaveApprove' => true,
                'salaryManage' => true,
                'payrollView' => true,
                'payrollApprove' => true,
                'payrollPay' => true,
                'payrollImport' => true,
                'offices' => true,
                'exportAttendance' => true,
                'i983Sign' => true,
                'leadsViewAll' => true,
                'leadsImport' => true,
                'leadsAssignTeam' => true,
                'leadsDistribute' => true,
                'leadsFollowUpTeam' => true,
                'leadsFollowUpOwn' => true,
                'leadsReport' => true,
            ]),
            'hr' => array_merge($none, [
                'employees' => true,
                'settings' => true,
                'routeMap' => true,
                'attendanceManage' => true,
                'attendanceCorrect' => true,
                'leaveApprove' => true,
                'salaryManage' => true,
                'payrollImport' => true,
                'offices' => true,
                'exportAttendance' => true,
                'i983Sign' => true,
                'leadsViewAll' => true,
                'leadsImport' => true,
                'leadsAssignTeam' => true,
                'leadsDistribute' => true,
                'leadsFollowUpTeam' => true,
                'leadsFollowUpOwn' => true,
                'leadsReport' => true,
            ]),
            'accounts' => array_merge($none, [
                'payroll' => true,
                'salaryManage' => true,
                'payrollView' => true,
                'payrollPay' => true,
                'payrollImport' => true,
            ]),
            'team_lead' => array_merge($none, [
                'employees' => true,
                'attendanceManage' => true,
                'leaveApprove' => true,
                'leadsDistribute' => true,
                'leadsFollowUpTeam' => true,
                'leadsFollowUpOwn' => true,
                'leadsReport' => true,
            ]),
            'employee' => array_merge($none, [
                'routeMap' => true,
                'leadsFollowUpOwn' => true,
            ]),
            default => $none,
        };
    }
}
