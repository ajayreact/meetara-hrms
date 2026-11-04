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
        $this->assertSame('Head of Operations', CompanyRole::fromStored('Owner', false)['roleLabel']);
        $this->assertSame('hr', CompanyRole::fromStored('HR', false)['role']);
        $this->assertSame('HR & Assistant Operations', CompanyRole::fromStored('HR', false)['roleLabel']);
        $this->assertSame('team_lead', CompanyRole::fromStored('Manager', false)['role']);
        $this->assertSame('Team Lead', CompanyRole::fromStored('Manager', false)['roleLabel']);
        $this->assertSame('team_lead', CompanyRole::fromStored('Company Manager', false)['role']);
        $this->assertSame('Company Manager', CompanyRole::fromStored('Company Manager', false)['roleLabel']);
        $this->assertSame('employee', CompanyRole::fromStored('Employee', false)['role']);
        $this->assertSame('accounts', CompanyRole::fromStored('Accounts', false)['role']);
        $this->assertSame('super_admin', CompanyRole::fromStored('Owner', true)['role']);
        $this->assertSame('Super Admin', CompanyRole::fromStored('Owner', true)['roleLabel']);
        $this->assertSame(CompanyRole::permissions('team_lead'), CompanyRole::permissions(CompanyRole::fromStored('Company Manager', false)['role']));
        $this->assertTrue(CompanyRole::permissions('company_admin')['employees']);
        $this->assertTrue(CompanyRole::permissions('company_admin')['payrollPay']);
        $this->assertTrue(CompanyRole::permissions('company_admin')['i983Sign']);
        $this->assertFalse(CompanyRole::permissions('hr')['payrollPay']);
        $this->assertFalse(CompanyRole::permissions('hr')['i983Sign']);
        $this->assertTrue(CompanyRole::permissions('hr')['payrollImport']);
        $this->assertFalse(CompanyRole::permissions('super_admin')['employees']);

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
