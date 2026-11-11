<?php

namespace App\Services\Hrms;

use App\Models\AttendanceBreak;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use App\Support\EmployeeOffice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceDirectory
{
    /**
     * @return array<string, mixed>
     */
    public function timing(User $user): array
    {
        $empty = [
            'state' => 'unavailable',
            'clockIn' => null,
            'breakIn' => null,
            'breakSeconds' => 0,
            'officeName' => null,
            'officeNow' => now()->toIso8601String(),
            'canClockIn' => false,
            'canStartBreak' => false,
            'canEndBreak' => false,
            'canClockOut' => false,
            'gpsRequired' => false,
            'gpsCapture' => false,
            'gpsNoticePending' => false,
            'shiftNotice' => null,
        ];

        $employee = $this->employee($user);

        if ($employee === null || ! Schema::hasTable('attendance_logs')) {
            return $empty;
        }

        $office = EmployeeOffice::resolve((int) $employee->tenant_id, $employee->location_id);
        $timezone = $this->timezone($office, (int) $employee->tenant_id);
        $now = now()->timezone($timezone);
        $schedule = WorkSchedule::forEmployee($employee, $now);
        $shiftDate = $schedule->attendanceDate($now);
        $log = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('tenant_id', $employee->tenant_id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->orderByDesc('work_date')
            ->first();

        if ($log === null && $shiftDate !== null) {
            $log = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('tenant_id', $employee->tenant_id)
                ->whereDate('work_date', $shiftDate->toDateString())
                ->first();
        }

        $outsideWindow = $log === null && $schedule->crossesMidnight() && $shiftDate === null;
        $shiftNotice = $outsideWindow ? $schedule->outsideWindowMessage() : null;
        $attendance = app(AttendanceService::class);
        $earlierShift = $log !== null ? $attendance->earlierShiftDate($employee, $log, $timezone) : null;

        if ($log !== null && $earlierShift !== null && $shiftDate !== null && $shiftDate->toDateString() > $earlierShift) {
            if ($log->check_out === null) {
                $shiftNotice = $attendance->earlierShiftCanMove($employee, $log, $timezone)
                    ? 'Clock out closes the previous shift. You can clock in for tonight after that.'
                    : 'Clock out closes this session. Tonight\'s clock-in needs HR because the previous shift already has a record.';
            } elseif ($attendance->earlierShiftCanMove($employee, $log, $timezone)) {
                $shiftNotice = 'The earlier session stays on the previous shift. Clock in starts tonight\'s shift.';
                $log = null;
            } else {
                $shiftNotice = 'The previous shift already has an attendance record, so tonight\'s clock-in cannot start. Contact HR.';
            }
        }

        $openBreak = $log ? $this->openBreak($log) : null;
        $state = 'out';

        if ($log !== null && $log->check_out !== null) {
            $state = 'closed';
        } elseif ($openBreak !== null) {
            $state = 'break';
        } elseif ($log !== null && $log->check_in !== null) {
            $state = 'in';
        }

        $breakSeconds = 0;

        if ($openBreak !== null) {
            $breakSeconds = max(0, $now->getTimestamp() - Carbon::parse((string) $openBreak->started_at, $timezone)->getTimestamp());
        }

        return [
            'state' => $state,
            'clockIn' => $this->clockLabel($log?->check_in, $timezone, $log?->work_date?->toDateString()),
            'breakIn' => $openBreak ? Carbon::parse((string) $openBreak->started_at, $timezone)->format('g:i A') : null,
            'breakSeconds' => $breakSeconds,
            'officeName' => $office?->name,
            'officeNow' => $now->toIso8601String(),
            'canClockIn' => $state === 'out' && $employee->status === 'active' && ! $outsideWindow,
            'canStartBreak' => $state === 'in',
            'canEndBreak' => $state === 'break',
            'canClockOut' => $state === 'in',
            'gpsRequired' => (bool) $office?->gps_verification_enabled,
            'gpsCapture' => app(GpsCapture::class)->shouldRequest($user),
            'gpsNoticePending' => app(GpsCapture::class)->noticePending($user),
            'shiftNotice' => $shiftNotice,
        ];
    }

    /**
     * @return array{month: string, label: string, months: list<array{value: string, label: string}>, days: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function month(User $user, ?string $month): array
    {
        $timezone = $this->timezone(null, (int) ($user->companyId() ?? 0));
        $selected = $this->monthStart($month, $timezone);
        $employee = $this->employee($user);
        $days = $employee ? $this->days($employee, $selected) : [];

        return [
            'month' => $selected->format('Y-m'),
            'label' => $selected->format('M Y'),
            'months' => $this->monthChoices($timezone),
            'days' => $days,
            'summary' => $this->summarize($days),
        ];
    }

    /**
     * @return array{date: string, offices: list<array{id: int, name: string}>, rows: list<array<string, mixed>>, counts: array{showing: int, present: int, review: int, pendingCorrections: int}}
     */
    public function roster(User $user, ?string $date, ?int $officeId, string $status, string $search): array
    {
        $companyId = $user->companyId();
        $timezone = $this->timezone(null, (int) ($companyId ?? 0));
        $day = $this->date($date, $timezone);
        $offices = $companyId === null ? [] : Office::query()->inCompany($companyId)->orderBy('name')->get(['id', 'name'])->map(fn (Office $office) => [
            'id' => (int) $office->id,
            'name' => (string) $office->name,
        ])->all();

        if ($companyId === null || $user->cannot('viewAny', AttendanceLog::class)) {
            return ['date' => $day->toDateString(), 'offices' => [], 'rows' => [], 'counts' => ['showing' => 0, 'present' => 0, 'review' => 0, 'pendingCorrections' => 0]];
        }

        $people = $this->scopedEmployees($user, $officeId, $search);
        $officeMap = Office::query()->inCompany($companyId)->get()->keyBy('id');
        $logs = AttendanceLog::query()
            ->inCompany($companyId)
            ->whereDate('work_date', $day->toDateString())
            ->whereIn('employee_id', $people->pluck('id'))
            ->with('breaks')
            ->get()
            ->keyBy('employee_id');

        $rows = [];

        foreach ($people as $person) {
            $log = $logs->get($person->id);
            $office = $officeMap->get($log->location_id ?? $person->location_id);
            $row = $this->row($person, $log, $office, $day);

            if ($status !== '' && $status !== 'All statuses' && $row['status'] !== $status) {
                continue;
            }

            if ($log === null && ! $row['expected'] && ! in_array($row['status'], ['Weekly Off', 'Holiday'], true)) {
                continue;
            }

            $rows[] = $row;
        }

        return [
            'date' => $day->toDateString(),
            'offices' => $offices,
            'rows' => $rows,
            'counts' => [
                'showing' => count($rows),
                'present' => count(array_filter($rows, fn (array $row) => in_array($row['status'], ['Present', 'Late', 'On break', 'Early out', 'Holiday Work'], true))),
                'pendingCorrections' => in_array($user->applicationRole(), ['company_admin', 'hr'], true)
                    ? app(AttendanceCorrection::class)->pendingCount($user)
                    : 0,
                'review' => count(array_filter($rows, fn (array $row) => $row['late'] || $row['early'] || in_array($row['status'], ['Absent', 'Recovered'], true))),
            ],
        ];
    }

    public function export(User $user, ?string $date, ?int $officeId, string $status, string $search): string
    {
        $roster = $this->roster($user, $date, $officeId, $status, $search);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Date', 'Employee', 'Office', 'Clock in', 'Clock out', 'Break', 'Work', 'Status']);

        foreach ($roster['rows'] as $row) {
            fputcsv($handle, [
                $this->csv($row['date']),
                $this->csv($row['name']),
                $this->csv($row['office'] ?? ''),
                $this->csv($row['clockIn'] ?? ''),
                $this->csv($row['clockOut'] ?? ''),
                $this->csv($this->spreadsheetCell($row['breakLabel'])),
                $this->csv($this->spreadsheetCell($row['workLabel'])),
                $this->csv($row['status']),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : "\xEF\xBB\xBF".$csv;
    }

    public function exportMonth(User $user, string $month, ?int $officeId, string $search): string
    {
        $companyId = (int) ($user->companyId() ?? 0);
        $timezone = $this->timezone(null, $companyId);
        $start = Carbon::createFromFormat('!Y-m-d', $month.'-01', $timezone);
        abort_if($start === false || $start->format('Y-m') !== $month, 404);
        $start = $start->startOfDay();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Employee code', 'Employee', 'Office', 'Date', 'Clock in', 'Clock out', 'Break', 'Work', 'Status']);

        if ($user->can('viewAny', AttendanceLog::class)) {
            $offices = Office::query()->inCompany($companyId)->get()->keyBy('id');

            foreach ($this->scopedEmployees($user, $officeId, $search) as $person) {
                $office = $offices->get($person->location_id);
                $name = trim($person->first_name.' '.$person->last_name);

                foreach ($this->days($person, $start->copy()) as $day) {
                    $status = (string) $day['status'];

                    if (! $day['occurred'] && $status === 'Absent') {
                        $status = 'Not started';
                    }

                    fputcsv($handle, [
                        $this->csv((string) $person->employee_code),
                        $this->csv($name),
                        $this->csv($office instanceof Office ? (string) $office->name : ''),
                        $this->csv((string) $day['date']),
                        $this->csv((string) ($day['clockIn'] ?? '')),
                        $this->csv((string) ($day['clockOut'] ?? '')),
                        $this->csv($this->spreadsheetCell($day['breakMinutes'] > 0 ? $this->duration((int) $day['breakMinutes']) : '—')),
                        $this->csv($this->spreadsheetCell($day['workMinutes'] > 0 ? $this->duration((int) $day['workMinutes']) : '—')),
                        $this->csv($status),
                    ]);
                }
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : "\xEF\xBB\xBF".$csv;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    private function scopedEmployees(User $user, ?int $officeId, string $search)
    {
        $employees = Employee::query()->inCompany((int) $user->companyId())->orderBy('first_name')->orderBy('last_name');

        if ($user->applicationRole() === 'team_lead') {
            $actor = $this->employee($user);
            $employees->where(function ($query) use ($actor): void {
                $query->where('id', $actor?->id ?? 0)->orWhere('manager_id', $actor?->id ?? 0);
            });
        }

        if ($officeId !== null) {
            $employees->where('location_id', $officeId);
        }

        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $employees->where(function ($query) use ($term): void {
                $query->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('employee_code', 'like', $term);
            });
        }

        return $employees->limit(200)->get();
    }

    private function days(Employee $employee, Carbon $month): array
    {
        $office = EmployeeOffice::resolve((int) $employee->tenant_id, $employee->location_id);
        $timezone = $this->timezone($office, (int) $employee->tenant_id);
        $now = now()->timezone($timezone);
        $today = $now->copy()->startOfDay();
        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('tenant_id', $employee->tenant_id)
            ->whereBetween('work_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->with('breaks')
            ->get()
            ->keyBy(fn (AttendanceLog $log) => $log->work_date?->toDateString());
        $days = [];
        $cursor = $month->copy();

        while ($cursor->month === $month->month) {
            $schedule = WorkSchedule::forEmployee($employee, $cursor);
            $occurred = $cursor->lessThanOrEqualTo($today);
            $log = $logs->get($cursor->toDateString());
            $metrics = $log ? $this->metrics($log, $timezone, $schedule) : ['work' => 0, 'break' => 0, 'early' => 0];
            $holiday = AttendanceCalendar::holiday($employee, $office?->id, $cursor->toDateString());
            $openBreak = $log ? $this->openBreak($log) !== null : false;
            $late = (int) ($log->late_minutes ?? 0) > 0;
            $status = AttendanceCalendar::label(
                $log?->status,
                $openBreak,
                $late,
                $metrics['early'] > 0,
                $schedule->expected,
                $holiday->name ?? null,
                $log?->check_in !== null,
            );
            if ($log === null && $status === 'Absent' && $schedule->shiftStillOpen($now, $cursor)) {
                $status = 'Not started';
            }
            $days[] = [
                'day' => $cursor->day,
                'date' => $cursor->toDateString(),
                'dateLabel' => $cursor->format('D, M j'),
                'weekday' => $cursor->format('D'),
                'expected' => $schedule->expected,
                'occurred' => $occurred,
                'workMinutes' => $metrics['work'],
                'breakMinutes' => $metrics['break'],
                'missing' => $occurred && $status === 'Absent' && $cursor->lt($today),
                'status' => $status,
                'holidayName' => $holiday->name ?? null,
                'pending' => false,
                'expectedMinutes' => $schedule->expected ? $schedule->expectedMinutes : 0,
                'lateMinutes' => (int) ($log->late_minutes ?? 0),
                'earlyMinutes' => $metrics['early'],
                'clockIn' => $this->clockLabel($log?->check_in, $timezone, $cursor->toDateString()),
                'clockOut' => $this->clockLabel($log?->check_out, $timezone, $cursor->toDateString()),
                'clockInIp' => $log?->check_in_ip,
                'clockOutIp' => $log?->check_out_ip,
                'clockInDistance' => $this->distanceMeters($log, 'check_in_distance_meters'),
                'clockOutDistance' => $this->distanceMeters($log, 'check_out_distance_meters'),
                'verification' => $this->verificationLabel($log?->verified_as),
                'networkStatus' => $this->networkVerified($log?->verified_as) ? 'verified' : 'not_recorded',
            ];
            $cursor->addDay();
        }

        if (Schema::hasTable('attendance_correction_requests')) {
            $pending = DB::table('attendance_correction_requests')
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->pluck('work_date')
                ->map(fn ($date) => substr((string) $date, 0, 10))
                ->all();

            foreach ($days as $index => $day) {
                $days[$index]['pending'] = in_array($day['date'], $pending, true);
            }
        }

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<string, int>
     */
    private function summarize(array $days): array
    {
        $actual = 0;
        $early = 0;
        $present = 0;
        $absent = 0;
        $late = 0;
        $earlyOut = 0;
        $holiday = 0;
        $holidayWork = 0;
        $weeklyOff = 0;

        foreach ($days as $day) {
            $actual += $day['workMinutes'];
            $early += $day['earlyMinutes'];
            if (! $day['occurred']) {
                continue;
            }

            if (($day['status'] ?? '') === 'Holiday') {
                $holiday++;
            } elseif (($day['status'] ?? '') === 'Weekly Off') {
                $weeklyOff++;
            } elseif (($day['status'] ?? '') === 'Holiday Work') {
                $holidayWork++;
            } else {
                if ($day['workMinutes'] > 0) {
                    $present++;
                }
                if ($day['missing']) {
                    $absent++;
                }
                if ($day['lateMinutes'] > 0) {
                    $late++;
                }
                if ($day['earlyMinutes'] > 0) {
                    $earlyOut++;
                }
            }
        }

        return [
            'expectedMinutes' => $this->expectedFromSchedule($days),
            'actualMinutes' => $actual,
            'remainingMinutes' => max($this->expectedFromSchedule($days) - $actual, 0),
            'earlyMinutes' => $early,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'earlyOut' => $earlyOut,
            'holiday' => $holiday,
            'holidayWork' => $holidayWork,
            'weeklyOff' => $weeklyOff,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function expectedFromSchedule(array $days): int
    {
        $total = 0;

        foreach ($days as $day) {
            if ($day['occurred'] && $day['expected'] && ! in_array($day['status'] ?? '', ['Holiday', 'Weekly Off'], true)) {
                $total += (int) ($day['expectedMinutes'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Employee $person, ?AttendanceLog $log, ?Office $office, Carbon $day): array
    {
        $timezone = $this->timezone($office, (int) $person->tenant_id);
        $schedule = WorkSchedule::forEmployee($person, $day->copy()->timezone($timezone));
        $metrics = $log ? $this->metrics($log, $timezone, $schedule) : ['work' => 0, 'break' => 0, 'early' => 0];
        $openBreak = $log ? $this->openBreak($log) : null;
        $late = (int) ($log->late_minutes ?? 0) > 0;
        $early = $metrics['early'] > 0;
        $holiday = AttendanceCalendar::holiday($person, $office?->id, $day->toDateString());
        $status = AttendanceCalendar::label(
            $log?->status,
            $openBreak !== null,
            $late,
            $early,
            $schedule->expected,
            $holiday->name ?? null,
            $log?->check_in !== null,
        );

        if ($log === null && $status === 'Absent' && $schedule->shiftStillOpen(now()->timezone($timezone), $day->copy()->timezone($timezone))) {
            $status = 'Not started';
        }

        return [
            'id' => $log?->id,
            'name' => trim($person->first_name.' '.$person->last_name),
            'office' => $office && (int) $office->tenant_id === (int) $person->tenant_id ? $office->name : null,
            'date' => $day->toDateString(),
            'clockIn' => $this->clockLabel($log?->check_in, $timezone, $day->toDateString()),
            'clockOut' => $this->clockLabel($log?->check_out, $timezone, $day->toDateString()),
            'breakLabel' => $metrics['break'] > 0 ? $this->duration($metrics['break']) : '—',
            'workLabel' => $metrics['work'] > 0 ? $this->duration($metrics['work']) : '—',
            'late' => $late,
            'early' => $early,
            'status' => $status,
            'holidayName' => $holiday->name ?? null,
            'clockInIp' => $log?->check_in_ip,
            'clockOutIp' => $log?->check_out_ip,
            'clockInDistance' => $this->distanceMeters($log, 'check_in_distance_meters'),
            'clockOutDistance' => $this->distanceMeters($log, 'check_out_distance_meters'),
            'verification' => $this->verificationLabel($log?->verified_as),
            'networkStatus' => $this->networkVerified($log?->verified_as) ? 'Verified' : 'Not recorded',
            'recoverable' => $log !== null && $log->check_out === null,
            'expected' => $schedule->expected,
        ];
    }

    /**
     * @return array{work: int, break: int, early: int}
     */
    private function metrics(AttendanceLog $log, string $timezone, WorkSchedule $schedule): array
    {
        $breakMinutes = (int) floor($log->breaks->sum(function (AttendanceBreak $break) use ($timezone, $log): int {
            if ($break->ended_at === null) {
                $end = $log->check_out
                    ? Carbon::parse((string) $log->check_out, $timezone)
                    : now()->timezone($timezone);
            } else {
                $end = Carbon::parse((string) $break->ended_at, $timezone);
            }

            $start = Carbon::parse((string) $break->started_at, $timezone);

            return max(0, $end->getTimestamp() - $start->getTimestamp());
        }) / 60);

        if ($log->check_in === null) {
            return ['work' => 0, 'break' => $breakMinutes, 'early' => 0];
        }

        $in = Carbon::parse((string) $log->check_in, $timezone);
        $out = $log->check_out ? Carbon::parse((string) $log->check_out, $timezone) : now()->timezone($timezone);
        $work = (int) max(0, floor((($out->getTimestamp() - $in->getTimestamp()) / 60) - $breakMinutes));

        return [
            'work' => $log->minutes !== null && $log->check_out !== null ? (int) $log->minutes : $work,
            'break' => $breakMinutes,
            'early' => $log->check_out ? $schedule->earlyMinutes($out, $in) : 0,
        ];
    }

    private function networkVerified(mixed $verifiedAs): bool
    {
        return in_array($verifiedAs, ['public_ip', 'public_ip_and_gps'], true);
    }

    private function verificationLabel(mixed $verifiedAs): ?string
    {
        return match ($verifiedAs) {
            'public_ip' => 'Approved public network',
            'public_ip_and_gps' => 'Approved public network and office GPS',
            default => null,
        };
    }

    private function distanceMeters(?AttendanceLog $log, string $column): ?int
    {
        if ($log === null || ! Schema::hasColumn('attendance_logs', $column)) {
            return null;
        }

        $value = $log->getAttribute($column);

        return $value === null ? null : (int) $value;
    }

    private function openBreak(AttendanceLog $log): ?AttendanceBreak
    {
        if ($log->relationLoaded('breaks')) {
            return $log->breaks->first(fn (AttendanceBreak $break) => $break->ended_at === null);
        }

        return AttendanceBreak::query()->where('attendance_log_id', $log->id)->whereNull('ended_at')->first();
    }

    private function employee(User $user): ?Employee
    {
        $companyId = $user->companyId();

        if ($companyId === null || ! Schema::hasTable('employees')) {
            return null;
        }

        return Employee::query()->where('user_id', $user->id)->where('tenant_id', $companyId)->first();
    }

    private function timezone(?Office $office, int $companyId): string
    {
        $timezone = $office?->timezone;

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = $companyId > 0 ? DB::table('tenants')->where('id', $companyId)->value('timezone') : null;
        }

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('attendance.fallback_timezone', 'Asia/Kolkata');
    }

    private function monthStart(?string $month, string $timezone): Carbon
    {
        if (! is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return now()->timezone($timezone)->startOfMonth();
        }

        return Carbon::createFromFormat('Y-m-d', $month.'-01', $timezone)->startOfMonth();
    }

    private function date(?string $date, string $timezone): Carbon
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return now()->timezone($timezone)->startOfDay();
        }

        return Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function monthChoices(string $timezone): array
    {
        $cursor = now()->timezone($timezone)->startOfMonth();
        $choices = [];

        for ($index = 0; $index < 4; $index++) {
            $choices[] = ['value' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')];
            $cursor->subMonth();
        }

        return $choices;
    }

    private function clockLabel(mixed $value, string $timezone, ?string $workDate): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parsed = Carbon::parse($value, $timezone);
        $label = $parsed->format('g:i A');

        if ($workDate !== null && $parsed->toDateString() !== $workDate) {
            $label .= ', '.$parsed->format('D j M');
        }

        return $label;
    }

    private function duration(int $minutes): string
    {
        return sprintf('%02d H : %02d M', intdiv($minutes, 60), $minutes % 60);
    }

    private function spreadsheetCell(string $value): string
    {
        return $value === '—' ? '' : $value;
    }

    private function csv(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
