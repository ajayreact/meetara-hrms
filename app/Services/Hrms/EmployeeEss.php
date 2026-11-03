<?php

namespace App\Services\Hrms;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class EmployeeEss
{
    public function __construct(
        private readonly EmployeeDirectory $employees,
        private readonly AttendanceDirectory $attendance,
        private readonly LeaveDirectory $leave,
        private readonly PayrollDirectory $payroll,
        private readonly Announcements $announcements,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function present(User $user): ?array
    {
        if ($user->applicationRole() !== 'employee' || $user->companyId() === null) {
            return null;
        }

        $profile = $this->employees->selfProfile($user);
        $timing = $this->attendance->timing($user);
        $history = $this->attendance->month($user, null);
        $leave = $this->leave->preview($user);
        $payslips = $this->payroll->payslips($user);
        $latest = $payslips[0] ?? null;
        $employee = Employee::query()->where('tenant_id', $user->companyId())->where('user_id', $user->id)->first();
        $today = $this->today($employee, $timing);

        return [
            'welcome' => $profile['employment'] ?? [
                'name' => $user->name,
                'code' => null,
                'designation' => null,
                'department' => null,
                'office' => null,
                'status' => 'Not linked',
            ],
            'today' => $today,
            'attendance' => $history['summary'] ?? null,
            'leaveBalances' => array_slice($leave['balances'] ?? [], 0, 4),
            'pendingLeave' => $employee === null ? 0 : LeaveRequest::query()
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count(),
            'payslip' => $latest === null ? null : [
                'id' => (int) $latest['id'],
                'period' => (string) ($latest['period'] ?? ''),
            ],
            'holidays' => array_slice($leave['upcoming'] ?? [], 0, 3),
            'unreadAnnouncements' => $this->announcements->unreadCount($user),
            'actions' => $this->actions($employee),
        ];
    }

    /**
     * @param  array<string, mixed>  $timing
     * @return array{status: string, checkIn: string|null, checkOut: string|null}
     */
    private function today(?Employee $employee, array $timing): array
    {
        $checkOut = null;

        if ($employee !== null && Schema::hasTable('attendance_logs')) {
            $office = Office::query()->whereKey($employee->location_id)->where('tenant_id', $employee->tenant_id)->first();
            $timezone = $office?->timezone ?: config('app.timezone');
            $log = AttendanceLog::query()
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', now()->timezone($timezone)->toDateString())
                ->first();
            $checkOut = $log?->check_out ? Carbon::parse((string) $log->check_out, $timezone)->format('g:i A') : null;
        }

        $status = match ($timing['state'] ?? 'unavailable') {
            'in' => 'Clocked in',
            'break' => 'On break',
            'closed' => 'Clocked out',
            'out' => 'Not clocked in',
            default => 'No attendance record',
        };

        return [
            'status' => $status,
            'checkIn' => $timing['clockIn'] ?? null,
            'checkOut' => $checkOut,
        ];
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function actions(?Employee $employee): array
    {
        $actions = [
            ['label' => 'Mark Attendance', 'href' => route('hrms.my-attendance')],
            ['label' => 'My Attendance', 'href' => route('hrms.my-attendance')],
            ['label' => 'My Profile', 'href' => route('hrms.profile')],
            ['label' => 'My Documents', 'href' => route('hrms.my-documents')],
            ['label' => 'Change Password', 'href' => route('password.edit')],
            ['label' => 'My Payslips', 'href' => route('hrms.payslips')],
        ];

        if ($employee !== null && $employee->status === 'active') {
            array_splice($actions, 2, 0, [['label' => 'Apply for Leave', 'href' => route('hrms.leave')]]);
        }

        return $actions;
    }
}
