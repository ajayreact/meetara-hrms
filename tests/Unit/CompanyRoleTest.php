<?php

namespace Tests\Unit;

use App\Auth\AccountStatus;
use App\Auth\CompanyRole;
use PHPUnit\Framework\TestCase;

class CompanyRoleTest extends TestCase
{
    public function test_platform_admin_does_not_receive_company_hr_permissions(): void
    {
        $resolved = CompanyRole::highest(['Owner'], true);

        $this->assertSame('super_admin', $resolved['role']);
        $this->assertTrue(CompanyRole::permissions($resolved['role'])['companies']);
        $this->assertFalse(CompanyRole::permissions($resolved['role'])['employees']);
        $this->assertFalse(CompanyRole::permissions($resolved['role'])['payroll']);
    }

    public function test_owner_hr_manager_and_employee_map_without_accounts_access(): void
    {
        $this->assertSame('company_admin', CompanyRole::fromStored('Owner', false)['role']);
        $this->assertSame('hr', CompanyRole::fromStored('HR', false)['role']);
        $this->assertSame('team_lead', CompanyRole::fromStored('Manager', false)['role']);
        $this->assertSame('employee', CompanyRole::fromStored('Employee', false)['role']);
        $this->assertSame('accounts', CompanyRole::fromStored('Accounts', false)['role']);

        $this->assertFalse(CompanyRole::permissions('hr')['payroll']);
        $this->assertFalse(CompanyRole::permissions('team_lead')['exportAttendance']);
        $this->assertFalse(CompanyRole::permissions('accounts')['employees']);
        $this->assertTrue(CompanyRole::permissions('accounts')['payroll']);
    }

    public function test_unknown_role_stays_an_employee(): void
    {
        $resolved = CompanyRole::highest(['Custom'], false);

        $this->assertSame('employee', $resolved['role']);
    }

    public function test_blocked_employee_statuses(): void
    {
        $this->assertTrue(AccountStatus::isBlockedEmployeeStatus('Inactive'));
        $this->assertTrue(AccountStatus::isBlockedEmployeeStatus('invited'));
        $this->assertFalse(AccountStatus::isBlockedEmployeeStatus('active'));
        $this->assertFalse(AccountStatus::isBlockedEmployeeStatus(null));
    }
}
