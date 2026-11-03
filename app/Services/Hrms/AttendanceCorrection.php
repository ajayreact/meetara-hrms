<?php

namespace App\Services\Hrms;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AttendanceCorrection
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly CompanyNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function submit(User $actor, array $input): int
    {
        $employee = $this->ownEmployee($actor);
        $timezone = $this->timezone($employee);
        $date = $this->date((string) ($input['work_date'] ?? ''), $timezone);
        $type = (string) ($input['type'] ?? '');
        $reason = trim((string) ($input['reason'] ?? ''));
        $this->assertType($type);
        $this->assertReason($reason, 'reason');
        $this->assertWindow($date, $timezone);

        $in = $this->time($date, $input['check_in'] ?? null, $timezone);
        $out = $this->time($date, $input['check_out'] ?? null, $timezone);
        $this->assertTimes($employee, $date, $type, $in, $out);

        $pending = DB::table('attendance_correction_requests')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'work_date' => 'A correction for this date is already waiting for HR.',
            ]);
        }

        $log = AttendanceLog::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->first();

        $id = DB::table('attendance_correction_requests')->insertGetId([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'attendance_log_id' => $log?->id,
            'work_date' => $date->toDateString(),
            'type' => $type,
            'requested_check_in' => $in?->format('Y-m-d H:i:s'),
            'requested_check_out' => $out?->format('Y-m-d H:i:s'),
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notifier->onceEach(
            array_merge(
                $this->notifier->roleUserIds((int) $employee->tenant_id, 'HR'),
                $this->notifier->roleUserIds((int) $employee->tenant_id, 'Owner'),
            ),
            'attendance-correction-'.$id.'-submitted',
            'Attendance correction requested',
            trim($employee->first_name.' '.$employee->last_name).' asked to correct '.$date->toDateString().'.',
            '/attendance',
        );

        return (int) $id;
    }

    public function cancel(User $actor, int $requestId): void
    {
        $employee = $this->ownEmployee($actor);
        $request = $this->lockOwn($employee, $requestId);

        if ((string) $request->status !== 'pending') {
            throw ValidationException::withMessages([
                'correction' => 'Only a pending request can be cancelled.',
            ]);
        }

        DB::table('attendance_correction_requests')->where('id', $request->id)->update([
            'status' => 'cancelled',
            'updated_at' => now(),
        ]);
    }

    public function approve(User $actor, int $requestId, ?string $note): void
    {
        $this->assertReviewer($actor);
        $note = trim((string) $note);
        if ($note === '') {
            $note = 'Approved';
        }

        DB::transaction(function () use ($actor, $requestId, $note): void {
            $request = $this->lockCompany($actor, $requestId);
            $this->assertPending($request);
            $employee = $this->employeeInCompany($actor, (int) $request->employee_id);
            $timezone = $this->timezone($employee);
            $date = Carbon::parse((string) $request->work_date, $timezone)->startOfDay();
            $in = $request->requested_check_in ? Carbon::parse((string) $request->requested_check_in, $timezone) : null;
            $out = $request->requested_check_out ? Carbon::parse((string) $request->requested_check_out, $timezone) : null;
            $this->assertTimes($employee, $date, (string) $request->type, $in, $out);

            $log = AttendanceLog::query()
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            $originalIn = $log?->check_in;
            $originalOut = $log?->check_out;
            $originalLate = $log?->late_minutes;
            [$nextIn, $nextOut] = $this->nextTimes($request->type, $originalIn, $originalOut, $in, $out);
            $this->assertOrder($nextIn, $nextOut, $timezone);

            if ($log === null) {
                $log = new AttendanceLog;
                $log->forceFill([
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'location_id' => $employee->location_id,
                    'work_date' => $date->toDateString(),
                    'work_mode' => $employee->work_mode ?: 'office',
                    'status' => 'present',
                    'payroll_status' => 'pending',
                    'late_minutes' => 0,
                ]);
            }

            $log->forceFill([
                'check_in' => $nextIn,
                'check_out' => $nextOut,
            ])->save();
            $this->attendance->recalculate($log);
            $log->refresh();

            $this->audit($request, $log, $originalIn, $originalOut, $originalLate, (int) $log->late_minutes, $note, 'approved', (int) $actor->id);
            DB::table('attendance_correction_requests')->where('id', $request->id)->update([
                'attendance_log_id' => $log->id,
                'status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_reason' => $note,
                'updated_at' => now(),
            ]);

            if ($employee->user_id) {
                $this->notifier->once(
                    (int) $employee->user_id,
                    'attendance-correction-'.$request->id.'-approved',
                    'Attendance correction approved',
                    'Your correction for '.$date->toDateString().' was approved.',
                    '/my-attendance',
                );
            }
        });
    }

    public function reject(User $actor, int $requestId, string $note): void
    {
        $this->assertReviewer($actor);
        $this->assertReason($note, 'review_reason');

        DB::transaction(function () use ($actor, $requestId, $note): void {
            $request = $this->lockCompany($actor, $requestId);
            $this->assertPending($request);
            $employee = $this->employeeInCompany($actor, (int) $request->employee_id);
            $log = $request->attendance_log_id
                ? AttendanceLog::query()->whereKey($request->attendance_log_id)->where('tenant_id', $actor->companyId())->first()
                : null;
            $originalIn = $log?->check_in;
            $originalOut = $log?->check_out;

            $this->audit($request, $log, $originalIn, $originalOut, $log?->late_minutes, $log?->late_minutes, trim($note), 'rejected', (int) $actor->id);
            DB::table('attendance_correction_requests')->where('id', $request->id)->update([
                'status' => 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_reason' => trim($note),
                'updated_at' => now(),
            ]);

            if ($log !== null) {
                $log->refresh();
                if ((string) $log->check_in !== (string) $originalIn || (string) $log->check_out !== (string) $originalOut) {
                    throw ValidationException::withMessages([
                        'correction' => 'The attendance record changed during review. Reject it again after checking the current times.',
                    ]);
                }
            }

            if ($employee->user_id) {
                $this->notifier->once(
                    (int) $employee->user_id,
                    'attendance-correction-'.$request->id.'-rejected',
                    'Attendance correction rejected',
                    'Your correction for '.$request->work_date.' was not approved.',
                    '/my-attendance',
                );
            }
        });
    }

    public function authorizeWork(User $actor, int $employeeId, string $date, string $kind, ?string $reason): void
    {
        $this->assertReviewer($actor);
        if (! in_array($kind, ['holiday', 'weekly_off'], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a holiday or a weekly off.']);
        }

        $employee = $this->employeeInCompany($actor, $employeeId);
        $timezone = $this->timezone($employee);
        $day = $this->date($date, $timezone);
        $holiday = AttendanceCalendar::holiday($employee, $employee->location_id ? (int) $employee->location_id : null, $day->toDateString());
        $schedule = WorkSchedule::forEmployee($employee, $day);

        if ($kind === 'holiday' && $holiday === null) {
            throw ValidationException::withMessages(['work_date' => 'That date is not a holiday on this employee\'s calendar.']);
        }

        if ($kind === 'weekly_off' && $schedule->expected) {
            throw ValidationException::withMessages(['work_date' => 'That date is not a weekly off.']);
        }

        $weekOff = DB::table('tenants')->where('id', $employee->tenant_id)->value('week_off');

        $match = [
            'employee_id' => $employee->id,
            'work_date' => $day->toDateString(),
            'kind' => $kind,
        ];
        $values = [
            'tenant_id' => $employee->tenant_id,
            'holiday_id' => $holiday->id ?? null,
            'reason' => trim((string) $reason) ?: null,
            'authorized_by' => $actor->id,
            'updated_at' => now(),
        ];
        if (DB::table('holiday_work_authorizations')->where($match)->exists()) {
            DB::table('holiday_work_authorizations')->where($match)->update($values);
        } else {
            DB::table('holiday_work_authorizations')->insert($match + $values + ['created_at' => now()]);
        }

        if ((string) DB::table('tenants')->where('id', $employee->tenant_id)->value('week_off') !== (string) $weekOff) {
            DB::table('tenants')->where('id', $employee->tenant_id)->update(['week_off' => $weekOff]);
        }

        if ($employee->user_id) {
            $label = $kind === 'holiday' ? ($holiday->name ?? 'a holiday') : 'a weekly off';
            $this->notifier->once(
                (int) $employee->user_id,
                'holiday-work-'.$employee->id.'-'.$day->toDateString().'-'.$kind,
                'Holiday work authorized',
                'You may clock in on '.$day->toDateString().' ('.$label.').',
                '/my-attendance',
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEmployee(User $actor): array
    {
        $employee = Employee::query()->where('user_id', $actor->id)->where('tenant_id', $actor->companyId())->first();

        if ($employee === null || ! Schema::hasTable('attendance_correction_requests')) {
            return [];
        }

        return $this->rows($actor->companyId() ?? 0, (int) $employee->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCompany(User $actor): array
    {
        if (! in_array($actor->applicationRole(), ['company_admin', 'hr'], true) || $actor->companyId() === null) {
            return [];
        }

        return $this->rows((int) $actor->companyId(), null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function authorizations(User $actor): array
    {
        if ($actor->companyId() === null || ! Schema::hasTable('holiday_work_authorizations')) {
            return [];
        }

        return DB::table('holiday_work_authorizations')
            ->join('employees', 'employees.id', '=', 'holiday_work_authorizations.employee_id')
            ->where('holiday_work_authorizations.tenant_id', $actor->companyId())
            ->orderByDesc('holiday_work_authorizations.id')
            ->limit(30)
            ->get([
                'holiday_work_authorizations.id',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code',
                'holiday_work_authorizations.work_date',
                'holiday_work_authorizations.kind',
                'holiday_work_authorizations.reason',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'employee' => trim($row->first_name.' '.$row->last_name),
                'code' => (string) $row->employee_code,
                'date' => substr((string) $row->work_date, 0, 10),
                'kind' => $row->kind === 'weekly_off' ? 'Weekly off' : 'Holiday',
                'reason' => $row->reason,
            ])
            ->all();
    }

    public function pendingCount(User $actor): int
    {
        if ($actor->companyId() === null || ! Schema::hasTable('attendance_correction_requests')) {
            return 0;
        }

        return DB::table('attendance_correction_requests')
            ->where('tenant_id', $actor->companyId())
            ->where('status', 'pending')
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $companyId, ?int $employeeId): array
    {
        if (! Schema::hasTable('attendance_correction_requests')) {
            return [];
        }

        $query = DB::table('attendance_correction_requests as requests')
            ->join('employees', 'employees.id', '=', 'requests.employee_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->leftJoin('attendance_logs', 'attendance_logs.id', '=', 'requests.attendance_log_id')
            ->where('requests.tenant_id', $companyId)
            ->orderByDesc('requests.id');

        if ($employeeId !== null) {
            $query->where('requests.employee_id', $employeeId);
        }

        return $query->limit(100)->get([
            'requests.id',
            'requests.work_date',
            'requests.type',
            'requests.reason',
            'requests.status',
            'requests.requested_check_in',
            'requests.requested_check_out',
            'requests.review_reason',
            'requests.created_at',
            'employees.first_name',
            'employees.last_name',
            'employees.employee_code',
            'departments.name as department',
            'attendance_logs.check_in as existing_check_in',
            'attendance_logs.check_out as existing_check_out',
        ])->map(fn ($row) => [
            'id' => (int) $row->id,
            'employee' => trim($row->first_name.' '.$row->last_name),
            'code' => (string) $row->employee_code,
            'department' => $row->department,
            'date' => substr((string) $row->work_date, 0, 10),
            'type' => $this->typeLabel((string) $row->type),
            'reason' => (string) $row->reason,
            'status' => ucfirst((string) $row->status),
            'statusValue' => (string) $row->status,
            'requestedIn' => $this->clock($row->requested_check_in),
            'requestedOut' => $this->clock($row->requested_check_out),
            'existingIn' => $this->clock($row->existing_check_in),
            'existingOut' => $this->clock($row->existing_check_out),
            'reviewReason' => $row->review_reason,
            'submitted' => $row->created_at ? Carbon::parse((string) $row->created_at)->format('d M Y') : null,
        ])->all();
    }

    private function ownEmployee(User $actor): Employee
    {
        $employee = Employee::query()->where('user_id', $actor->id)->where('tenant_id', $actor->companyId())->first();
        abort_if($employee === null || $employee->status !== 'active', 403);

        return $employee;
    }

    private function assertReviewer(User $actor): void
    {
        abort_unless($actor->companyId() !== null && in_array($actor->applicationRole(), ['company_admin', 'hr'], true), 403);
    }

    private function employeeInCompany(User $actor, int $employeeId): Employee
    {
        $employee = Employee::query()->whereKey($employeeId)->where('tenant_id', $actor->companyId())->first();
        abort_if($employee === null, 404);

        return $employee;
    }

    private function lockOwn(Employee $employee, int $requestId): object
    {
        $request = DB::table('attendance_correction_requests')
            ->where('id', $requestId)
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->first();
        abort_if($request === null, 404);

        return $request;
    }

    private function lockCompany(User $actor, int $requestId): object
    {
        $request = DB::table('attendance_correction_requests')
            ->where('id', $requestId)
            ->where('tenant_id', $actor->companyId())
            ->lockForUpdate()
            ->first();
        abort_if($request === null, 404);

        return $request;
    }

    private function assertPending(object $request): void
    {
        if ((string) $request->status !== 'pending') {
            throw ValidationException::withMessages([
                'correction' => 'This request is no longer pending.',
            ]);
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, ['missing_clock_in', 'missing_clock_out', 'incorrect_clock_in', 'incorrect_clock_out', 'other'], true)) {
            throw ValidationException::withMessages(['type' => 'Choose a correction type.']);
        }
    }

    private function assertReason(string $reason, string $key): void
    {
        if (strlen($reason) < 8) {
            throw ValidationException::withMessages([$key => 'Enter a reason of at least 8 characters.']);
        }
    }

    private function assertWindow(Carbon $date, string $timezone): void
    {
        $today = now()->timezone($timezone)->startOfDay();

        if ($date->greaterThan($today)) {
            throw ValidationException::withMessages(['work_date' => 'A future date cannot be corrected.']);
        }

        $limit = max(1, (int) config('attendance.correction_days', 14));

        if ($date->lessThan($today->copy()->subDays($limit))) {
            throw ValidationException::withMessages(['work_date' => 'This date is outside the allowed correction period.']);
        }
    }

    private function assertTimes(Employee $employee, Carbon $date, string $type, ?Carbon $in, ?Carbon $out): void
    {
        $needsIn = in_array($type, ['missing_clock_in', 'incorrect_clock_in'], true);
        $needsOut = in_array($type, ['missing_clock_out', 'incorrect_clock_out'], true);

        if ($needsIn && $in === null) {
            throw ValidationException::withMessages(['check_in' => 'Enter the requested clock-in time.']);
        }

        if ($needsOut && $out === null) {
            throw ValidationException::withMessages(['check_out' => 'Enter the requested clock-out time.']);
        }

        if ($type === 'other' && $in === null && $out === null) {
            throw ValidationException::withMessages(['check_in' => 'Enter the clock-in or clock-out time to correct.']);
        }

        if ($in !== null && $out !== null && $out->lessThanOrEqualTo($in)) {
            $schedule = WorkSchedule::forEmployee($employee, $date);
            if ($schedule->crossesMidnight()) {
                $out->addDay();
            }
        }

        if ($in !== null && $out !== null && $out->lessThanOrEqualTo($in)) {
            throw ValidationException::withMessages(['check_out' => 'Clock-out cannot be earlier than clock-in.']);
        }
    }

    private function assertOrder(?string $in, ?string $out, string $timezone): void
    {
        if ($in === null || $out === null) {
            return;
        }

        if (Carbon::parse($out, $timezone)->lessThanOrEqualTo(Carbon::parse($in, $timezone))) {
            throw ValidationException::withMessages(['check_out' => 'Clock-out cannot be earlier than clock-in.']);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function nextTimes(string $type, mixed $originalIn, mixed $originalOut, ?Carbon $in, ?Carbon $out): array
    {
        $nextIn = $originalIn ? (string) $originalIn : null;
        $nextOut = $originalOut ? (string) $originalOut : null;

        if (in_array($type, ['missing_clock_in', 'incorrect_clock_in'], true) || ($type === 'other' && $in !== null)) {
            $nextIn = $in?->format('Y-m-d H:i:s');
        }

        if (in_array($type, ['missing_clock_out', 'incorrect_clock_out'], true) || ($type === 'other' && $out !== null)) {
            $nextOut = $out?->format('Y-m-d H:i:s');
        }

        return [$nextIn, $nextOut];
    }

    private function audit(object $request, ?AttendanceLog $log, mixed $originalIn, mixed $originalOut, mixed $originalLate, mixed $correctedLate, string $note, string $decision, int $reviewerId): void
    {
        if (! Schema::hasTable('attendance_correction_audits')) {
            return;
        }

        DB::table('attendance_correction_audits')->insert([
            'tenant_id' => $request->tenant_id,
            'employee_id' => $request->employee_id,
            'attendance_log_id' => $log?->id,
            'request_id' => $request->id,
            'original_check_in' => $originalIn,
            'original_check_out' => $originalOut,
            'corrected_check_in' => $decision === 'approved' ? $log?->check_in : $originalIn,
            'corrected_check_out' => $decision === 'approved' ? $log?->check_out : $originalOut,
            'original_late_minutes' => $originalLate,
            'corrected_late_minutes' => $correctedLate,
            'changed_by' => $request->requested_by,
            'approved_by' => $reviewerId,
            'employee_reason' => $request->reason,
            'review_reason' => $note,
            'decision' => $decision,
            'created_at' => now(),
        ]);
    }

    private function date(string $value, string $timezone): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages(['work_date' => 'Choose a valid attendance date.']);
        }

        return Carbon::createFromFormat('Y-m-d', $value, $timezone)->startOfDay();
    }

    private function time(Carbon $date, mixed $value, string $timezone): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages(['check_in' => 'Enter a valid time.']);
        }

        return Carbon::parse($date->toDateString().' '.$value.':00', $timezone);
    }

    private function timezone(Employee $employee): string
    {
        $office = $employee->location_id
            ? DB::table('locations')->where('id', $employee->location_id)->where('tenant_id', $employee->tenant_id)->value('timezone')
            : null;

        if (! is_string($office) || ! in_array($office, timezone_identifiers_list(), true)) {
            $office = DB::table('tenants')->where('id', $employee->tenant_id)->value('timezone');
        }

        return is_string($office) && in_array($office, timezone_identifiers_list(), true)
            ? $office
            : (string) config('attendance.fallback_timezone', 'Asia/Kolkata');
    }

    private function clock(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value)->format('g:i A');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'missing_clock_in' => 'Missing Clock-in',
            'missing_clock_out' => 'Missing Clock-out',
            'incorrect_clock_in' => 'Incorrect Clock-in',
            'incorrect_clock_out' => 'Incorrect Clock-out',
            default => 'Other Attendance Correction',
        };
    }
}
