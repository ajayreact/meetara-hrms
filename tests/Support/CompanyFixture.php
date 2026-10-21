<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanyFixture
{
    public int $id;

    public User $hr;

    public User $lead;

    public User $employee;

    public int $departmentId;

    public int $leadEmployeeId;

    public int $reportEmployeeId;

    public int $outsideEmployeeId;

    public string $reportEmail;

    public string $outsideEmail;

    public int $attendanceId;

    public int $holidayId;

    public int $leaveId;

    public int $officeId;

    public int $ticketId;

    public int $payrollRunId;

    public int $payrollItemId;

    public static function make(string $name, string $slug): self
    {
        HrmsSchema::install();

        $fixture = new self;
        $now = now();
        $fixture->id = DB::table('tenants')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roles = [];
        foreach (['Owner', 'HR', 'Manager', 'Employee'] as $role) {
            $roles[$role] = DB::table('roles')->insertGetId([
                'tenant_id' => $fixture->id,
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $fixture->departmentId = DB::table('departments')->insertGetId([
            'tenant_id' => $fixture->id,
            'name' => $slug.'-department',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $designationId = DB::table('designations')->insertGetId([
            'tenant_id' => $fixture->id,
            'name' => $slug.'-role',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fixture->officeId = DB::table('locations')->insertGetId([
            'tenant_id' => $fixture->id,
            'name' => $slug.'-office',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fixture->hr = self::user($fixture->id, $roles['HR']);
        $fixture->lead = self::user($fixture->id, $roles['Manager']);
        $fixture->employee = self::user($fixture->id, $roles['Employee']);

        $fixture->leadEmployeeId = self::employee($fixture, $fixture->lead, $slug.'-lead@example.test', $slug.'-LEAD', null, $designationId);
        $fixture->reportEmail = $slug.'-report@example.test';
        $fixture->reportEmployeeId = self::employee($fixture, $fixture->employee, $fixture->reportEmail, $slug.'-REP', $fixture->leadEmployeeId, $designationId);
        $outside = self::user($fixture->id, $roles['Employee']);
        $fixture->outsideEmail = $slug.'-outside@example.test';
        $fixture->outsideEmployeeId = self::employee($fixture, $outside, $fixture->outsideEmail, $slug.'-OUT', null, $designationId);

        $fixture->attendanceId = DB::table('attendance_logs')->insertGetId([
            'tenant_id' => $fixture->id,
            'employee_id' => $fixture->reportEmployeeId,
            'work_date' => '2026-10-01',
            'status' => 'present',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fixture->holidayId = DB::table('holidays')->insertGetId([
            'tenant_id' => $fixture->id,
            'name' => $slug.'-holiday',
            'date' => '2026-12-25',
            'calendar' => 'usa',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fixture->leaveId = DB::table('leave_requests')->insertGetId([
            'tenant_id' => $fixture->id,
            'employee_id' => $fixture->reportEmployeeId,
            'start_date' => '2026-10-02',
            'end_date' => '2026-10-02',
            'days' => 1,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fixture->ticketId = DB::table('tickets')->insertGetId([
            'tenant_id' => $fixture->id,
            'employee_id' => $fixture->reportEmployeeId,
            'category' => 'HR',
            'title' => $slug.'-ticket',
            'priority' => 'low',
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fixture->payrollRunId = DB::table('payroll_runs')->insertGetId([
            'tenant_id' => $fixture->id,
            'year' => 2026,
            'month' => 10,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fixture->payrollItemId = DB::table('payroll_items')->insertGetId([
            'tenant_id' => $fixture->id,
            'payroll_run_id' => $fixture->payrollRunId,
            'employee_id' => $fixture->reportEmployeeId,
            'net' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $fixture;
    }

    private static function user(int $companyId, int $roleId): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $companyId,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();

        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $companyId,
        ]);

        return $user->refresh();
    }

    private static function employee(self $fixture, User $user, string $email, string $code, ?int $managerId, int $designationId): int
    {
        $now = now();

        return DB::table('employees')->insertGetId([
            'tenant_id' => $fixture->id,
            'user_id' => $user->id,
            'employee_code' => $code,
            'first_name' => $code,
            'last_name' => 'Person',
            'email' => $email,
            'department_id' => $fixture->departmentId,
            'designation_id' => $designationId,
            'location_id' => $fixture->officeId,
            'manager_id' => $managerId,
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'holiday_calendar' => 'india',
            'date_of_joining' => '2026-01-15',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
