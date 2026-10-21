<?php

namespace App\Services\Hrms;

use App\Models\AttendanceBreak;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
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
        ];

        $employee = $this->employee($user);

        if ($employee === null || ! Schema::hasTable('attendance_logs')) {
            return $empty;
        }

        $office = Office::query()->whereKey($employee->location_id)->where('tenant_id', $employee->tenant_id)->first();
        $timezone = $this->timezone($office, (int) $employee->tenant_id);
        $now = now()->timezone($timezone);
        $log = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('tenant_id', $employee->tenant_id)
            ->whereDate('work_date', $now->toDateString())
            ->first();
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
            'clockIn' => $log?->check_in ? Carbon::parse((string) $log->check_in, $timezone)->format('g:i A') : null,
            'breakIn' => $openBreak ? Carbon::parse((string) $openBreak->started_at, $timezone)->format('g:i A') : null,
            'breakSeconds' => $breakSeconds,
            'officeName' => $office?->name,
            'officeNow' => $now->toIso8601String(),
            'canClockIn' => $state === 'out' && $employee->status === 'active',
            'canStartBreak' => $state === 'in',
            'canEndBreak' => $state === 'break',
            'canClockOut' => $state === 'in',
            'gpsRequired' => (bool) $office?->gps_verification_enabled,
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
     * @return array{date: string, offices: list<array{id: int, name: string}>, rows: list<array<string, mixed>>, counts: array{showing: int, present: int, review: int}}
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
            return ['date' => $day->toDateString(), 'offices' => [], 'rows' => [], 'counts' => ['showing' => 0, 'present' => 0, 'review' => 0]];
        }

        $employees = Employee::query()->inCompany($companyId)->orderBy('first_name')->orderBy('last_name');

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

        $people = $employees->limit(200)->get();
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

            if ($log === null && ! $row['expected']) {
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
                'present' => count(array_filter($rows, fn (array $row) => in_array($row['status'], ['Present', 'Late', 'On break', 'Early out'], true))),
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
                $this->csv($row['breakLabel']),
                $this->csv($row['workLabel']),
                $this->csv($row['status']),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function days(Employee $employee, Carbon $month): array
    {
        $office = Office::query()->whereKey($employee->location_id)->where('tenant_id', $employee->tenant_id)->first();
        $timezone = $this->timezone($office, (int) $employee->tenant_id);
        $today = now()->timezone($timezone)->startOfDay();
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
            $days[] = [
                'day' => $cursor->day,
                'date' => $cursor->toDateString(),
                'dateLabel' => $cursor->format('D, M j'),
                'weekday' => $cursor->format('D'),
                'expected' => $schedule->expected,
                'occurred' => $occurred,
                'workMinutes' => $metrics['work'],
                'breakMinutes' => $metrics['break'],
                'missing' => $occurred && $schedule->expected && $cursor->lt($today) && ($log === null || $log->check_in === null),
                'expectedMinutes' => $schedule->expected ? $schedule->expectedMinutes : 0,
                'lateMinutes' => (int) ($log->late_minutes ?? 0),
                'earlyMinutes' => $metrics['early'],
                'clockIn' => $log?->check_in ? Carbon::parse((string) $log->check_in, $timezone)->format('g:i A') : null,
                'clockOut' => $log?->check_out ? Carbon::parse((string) $log->check_out, $timezone)->format('g:i A') : null,
                'clockInIp' => $log?->check_in_ip,
                'clockOutIp' => $log?->check_out_ip,
                'networkStatus' => $log?->verified_as === 'public_ip' ? 'verified' : 'not_recorded',
            ];
            $cursor->addDay();
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

        foreach ($days as $day) {
            $actual += $day['workMinutes'];
            $early += $day['earlyMinutes'];
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

        return [
            'expectedMinutes' => $this->expectedFromSchedule($days),
            'actualMinutes' => $actual,
            'remainingMinutes' => max($this->expectedFromSchedule($days) - $actual, 0),
            'earlyMinutes' => $early,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'earlyOut' => $earlyOut,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function expectedFromSchedule(array $days): int
    {
        $total = 0;

        foreach ($days as $day) {
            if ($day['occurred'] && $day['expected']) {
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
        $status = 'Absent';

        if ($log?->status === 'recovered') {
            $status = 'Recovered';
        } elseif ($openBreak !== null) {
            $status = 'On break';
        } elseif ($early) {
            $status = 'Early out';
        } elseif ($late) {
            $status = 'Late';
        } elseif ($log?->check_in !== null) {
            $status = 'Present';
        }

        return [
            'id' => $log?->id,
            'name' => trim($person->first_name.' '.$person->last_name),
            'office' => $office && (int) $office->tenant_id === (int) $person->tenant_id ? $office->name : null,
            'date' => $day->toDateString(),
            'clockIn' => $log?->check_in ? Carbon::parse((string) $log->check_in, $timezone)->format('g:i A') : null,
            'clockOut' => $log?->check_out ? Carbon::parse((string) $log->check_out, $timezone)->format('g:i A') : null,
            'breakLabel' => $metrics['break'] > 0 ? $this->duration($metrics['break']) : '—',
            'workLabel' => $metrics['work'] > 0 ? $this->duration($metrics['work']) : '—',
            'late' => $late,
            'early' => $early,
            'status' => $status,
            'clockInIp' => $log?->check_in_ip,
            'clockOutIp' => $log?->check_out_ip,
            'networkStatus' => $log?->verified_as === 'public_ip' ? 'Verified' : 'Not recorded',
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
            'early' => $log->check_out ? $schedule->earlyMinutes($out) : 0,
        ];
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

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
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

    private function duration(int $minutes): string
    {
        return sprintf('%02d H : %02d M', intdiv($minutes, 60), $minutes % 60);
    }

    private function csv(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
