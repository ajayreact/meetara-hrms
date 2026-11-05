<?php

namespace App\Services\Hrms;

use App\Models\AttendanceBreak;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LocationNetwork;
use App\Models\Office;
use App\Models\User;
use App\Support\ClientAddress;
use App\Support\EmployeeOffice;
use App\Support\GeoDistance;
use App\Support\NetworkAddress;
use App\Support\OfficeVerification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AttendanceService
{
    public function __construct(
        private readonly ClientAddress $clientAddress,
        private readonly GpsCapture $gpsCapture,
    ) {}

    public function clockIn(User $actor, ?int $requestedOfficeId, Request $request): AttendanceLog
    {
        return DB::transaction(function () use ($actor, $requestedOfficeId, $request): AttendanceLog {
            $employee = $this->employee($actor);
            $office = $this->office($employee, $requestedOfficeId);
            $observed = $this->requireNetwork($request, $office, (int) $employee->tenant_id, 'Clock-in');
            $location = $this->requireLocation($request, $office, 'Clock-in');
            $local = $this->localNow($office);
            $shiftDate = $this->shiftDate($employee, $local);
            $this->assertNoOpenSession($employee, $local);

            $existing = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('tenant_id', $employee->tenant_id)
                ->whereDate('work_date', $shiftDate->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new AttendanceRejected('You already have an attendance record for this shift.');
            }

            $schedule = WorkSchedule::forEmployee($employee, $shiftDate);
            $status = $this->dayStatus($employee, $office, $shiftDate, $schedule);

            try {
                $log = new AttendanceLog;
                $log->forceFill([
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'location_id' => $office->id,
                    'work_date' => $shiftDate->toDateString(),
                    'check_in' => $local->format('Y-m-d H:i:s'),
                    'work_mode' => $employee->work_mode ?: 'office',
                    'check_in_ip' => $observed,
                    'verified_as' => $location['verified'] ? 'public_ip_and_gps' : 'public_ip',
                    'status' => $status,
                    ...$this->storedLocation('check_in', $location),
                    'late_minutes' => $schedule->lateMinutes($local),
                    'payroll_status' => 'pending',
                ])->save();
            } catch (QueryException) {
                throw new AttendanceRejected('You already have an active attendance session.');
            }

            $this->gpsCapture->record($actor, $employee, $log, 'clock_in', $request);

            return $log;
        });
    }

    public function startBreak(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $employee = $this->employee($actor);
            $log = $this->openLog($employee);

            if ($this->openBreak($log) !== null) {
                throw new AttendanceRejected('You are already on a break.');
            }

            $break = new AttendanceBreak;
            $break->forceFill([
                'tenant_id' => $employee->tenant_id,
                'attendance_log_id' => $log->id,
                'started_at' => $this->localNow($this->officeForLog($log, $employee))->format('Y-m-d H:i:s'),
            ])->save();
        });
    }

    public function endBreak(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $employee = $this->employee($actor);
            $log = $this->openLog($employee);
            $break = $this->openBreak($log);

            if ($break === null) {
                throw new AttendanceRejected('You do not have an active break.');
            }

            $break->forceFill([
                'ended_at' => $this->localNow($this->officeForLog($log, $employee))->format('Y-m-d H:i:s'),
            ])->save();
        });
    }

    public function clockOut(User $actor, Request $request): void
    {
        DB::transaction(function () use ($actor, $request): void {
            $employee = $this->employee($actor);
            $log = $this->openLog($employee);

            if ($this->openBreak($log) !== null) {
                throw new AttendanceRejected('End your break before clocking out.');
            }

            $office = $this->officeForLog($log, $employee);
            $observed = $this->requireNetwork($request, $office, (int) $employee->tenant_id, 'Clock-out');
            $location = $this->requireLocation($request, $office, 'Clock-out');
            $local = $this->localNow($office);
            $checkIn = (string) $log->check_in;
            $schedule = WorkSchedule::forEmployee($employee, Carbon::parse($checkIn, $this->timezone($office)));

            $log->forceFill([
                'check_out' => $local->format('Y-m-d H:i:s'),
                'check_out_ip' => $observed,
                'minutes' => $this->workMinutes($log, $checkIn, $local->format('Y-m-d H:i:s'), $this->timezone($office)),
                ...$this->storedLocation('check_out', $location),
            ])->save();

            if ((string) $log->check_in !== $checkIn) {
                throw new AttendanceRejected('Your office network could not be verified. Contact HR.');
            }

            $log->forceFill([
                'late_minutes' => $schedule->lateMinutes(Carbon::parse($checkIn, $this->timezone($office))),
            ])->save();

            $this->gpsCapture->record($actor, $employee, $log, 'clock_out', $request);
        });
    }

    public function recalculate(AttendanceLog $log): void
    {
        $employee = Employee::query()->whereKey($log->employee_id)->where('tenant_id', $log->tenant_id)->first();
        abort_if($employee === null, 404);
        $office = $this->officeForLog($log, $employee);
        $timezone = $this->timezone($office);
        $schedule = WorkSchedule::forEmployee($employee, Carbon::parse((string) $log->work_date, $timezone));
        $late = $log->check_in ? $schedule->lateMinutes(Carbon::parse((string) $log->check_in, $timezone)) : 0;
        $minutes = $log->check_in && $log->check_out
            ? $this->workMinutes($log, (string) $log->check_in, (string) $log->check_out, $timezone)
            : null;

        $log->forceFill([
            'late_minutes' => $late,
            'minutes' => $minutes,
        ])->save();
    }

    public function recover(User $actor, AttendanceLog $log, string $note): void
    {
        abort_unless($actor->can('correct', $log), 403);

        DB::transaction(function () use ($actor, $log, $note): void {
            $locked = AttendanceLog::query()
                ->whereKey($log->id)
                ->where('tenant_id', $actor->companyId())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->check_in === null) {
                throw new AttendanceRejected('You do not have an active attendance session.');
            }

            if ($locked->check_out !== null) {
                throw new AttendanceRejected('This attendance session is already closed.');
            }

            $employee = Employee::query()->whereKey($locked->employee_id)->where('tenant_id', $locked->tenant_id)->first();
            abort_if($employee === null, 404);
            $office = $this->officeForLog($locked, $employee);
            $checkIn = (string) $locked->check_in;
            $checkOut = $this->localNow($office)->format('Y-m-d H:i:s');

            $locked->forceFill([
                'check_out' => $checkOut,
                'status' => 'recovered',
                'recovery_note' => $note,
                'minutes' => $this->workMinutes($locked, $checkIn, $checkOut, $this->timezone($office)),
            ])->save();

            if ((string) $locked->fresh()->check_in !== $checkIn) {
                throw new AttendanceRejected('Your office network could not be verified. Contact HR.');
            }
        });
    }

    private function employee(User $actor): Employee
    {
        $companyId = $actor->companyId();

        if ($companyId === null) {
            throw new AttendanceRejected('Your office network could not be verified. Contact HR.');
        }

        $employee = Employee::query()
            ->where('user_id', $actor->id)
            ->where('tenant_id', $companyId)
            ->lockForUpdate()
            ->first();

        if ($employee === null || $employee->status !== 'active') {
            throw new AttendanceRejected('This account cannot record attendance.');
        }

        return $employee;
    }

    private function office(Employee $employee, ?int $requestedOfficeId): Office
    {
        $office = EmployeeOffice::resolve((int) $employee->tenant_id, $employee->location_id);

        if ($office === null) {
            throw new AttendanceRejected($employee->location_id === null
                ? 'You are not assigned to an office. Contact HR.'
                : 'Your office network could not be verified. Contact HR.');
        }

        if ($requestedOfficeId !== null && $requestedOfficeId !== (int) $office->id) {
            throw new AttendanceRejected('You are not authorized to attend that office.');
        }

        if ((string) $office->status !== 'active') {
            throw new AttendanceRejected('Your assigned office is inactive. Contact your administrator.');
        }

        return $office;
    }

    private function requireNetwork(Request $request, Office $office, int $companyId, string $action): string
    {
        $observed = $this->clientAddress->observe($request);

        if (! $observed['ok'] || $observed['ip'] === null) {
            $this->logNetworkDenial($request, $office, $companyId, null, 'address_unresolved', $action);
            $invalidProxy = str_contains($observed['reason'], 'invalid');

            throw new AttendanceRejected($invalidProxy
                ? $action.' was blocked because the trusted-proxy configuration is invalid.'
                : $action.' was blocked because a forwarded address arrived from an untrusted connection.');
        }

        $cidrs = LocationNetwork::query()
            ->where('tenant_id', $companyId)
            ->where('location_id', $office->id)
            ->pluck('cidr')
            ->all();

        $failure = NetworkAddress::denialReason($observed['ip'], $cidrs);

        if ($failure !== null) {
            $this->logNetworkDenial($request, $office, $companyId, $observed['ip'], $failure, $action);

            throw new AttendanceRejected($this->networkDenialMessage($failure, $observed['ip'], $action));
        }

        return $observed['ip'];
    }

    private function networkDenialMessage(string $failure, string $ip, string $action): string
    {
        if ($failure !== 'address_not_public') {
            return 'You are not connected through an approved office network. '.$action.' is blocked.';
        }

        $loopback = $ip === '::1' || str_starts_with($ip, '127.');

        return $loopback
            ? $action.' was blocked because this connection is localhost. The server does not see the office public address.'
            : $action.' was blocked because this connection is a private network address. The server does not see the office public address.';
    }

    private function logNetworkDenial(Request $request, Office $office, int $companyId, ?string $resolvedIp, string $reason, string $action): void
    {
        Log::warning($action.' was denied by the approved office network check.', [
            'reason' => $reason,
            'resolved_ip' => $resolvedIp,
            ...$this->clientAddress->context($request),
            'tenant_id' => $companyId,
            'office_id' => $office->id,
        ]);
    }

    /**
     * Browser coordinates are checked here. A submitted verification flag, distance, or IP is ignored.
     *
     * @return array{verified: bool, latitude: float|null, longitude: float|null, distance: int|null}
     */
    private function requireLocation(Request $request, Office $office, string $action): array
    {
        $empty = ['verified' => false, 'latitude' => null, 'longitude' => null, 'distance' => null];

        if (! OfficeVerification::columnsAvailable() || ! $office->gps_verification_enabled) {
            return $empty;
        }

        if (! OfficeVerification::coordinatesValid($office->latitude, $office->longitude)) {
            throw new AttendanceRejected('GPS verification is enabled, but this office has no saved location. '.$action.' is blocked.');
        }

        if (! OfficeVerification::radiusValid($office->verification_radius_meters)) {
            throw new AttendanceRejected('This office location radius is not valid. '.$action.' is blocked.');
        }

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');

        if (! OfficeVerification::coordinatesValid($latitude, $longitude)) {
            throw new AttendanceRejected('A browser location inside the office radius is required. '.$action.' is blocked.');
        }

        $distance = GeoDistance::meters(
            (float) $office->latitude,
            (float) $office->longitude,
            (float) $latitude,
            (float) $longitude,
        );

        if ($distance > (int) $office->verification_radius_meters) {
            throw new AttendanceRejected('You are outside the configured office location. '.$action.' is blocked.');
        }

        return [
            'verified' => true,
            'latitude' => round((float) $latitude, GpsCapture::STORED_COORDINATE_DECIMALS),
            'longitude' => round((float) $longitude, GpsCapture::STORED_COORDINATE_DECIMALS),
            'distance' => (int) round($distance),
        ];
    }

    /**
     * @param  array{verified: bool, latitude: float|null, longitude: float|null, distance: int|null}  $location
     * @return array<string, float|int|null>
     */
    private function storedLocation(string $prefix, array $location): array
    {
        if (! Schema::hasColumn('attendance_logs', $prefix.'_latitude')) {
            return [];
        }

        return [
            $prefix.'_latitude' => $location['latitude'],
            $prefix.'_longitude' => $location['longitude'],
            $prefix.'_distance_meters' => $location['distance'],
        ];
    }

    private function assertNoOpenSession(Employee $employee, CarbonInterface $local): void
    {
        $open = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('tenant_id', $employee->tenant_id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->lockForUpdate()
            ->first();

        if ($open === null) {
            return;
        }

        $workDate = $open->work_date?->toDateString();

        if ($workDate === $local->toDateString()) {
            throw new AttendanceRejected('You already have an active attendance session.');
        }

        throw new AttendanceRejected('An earlier attendance session is still open. Contact HR.');
    }

    private function openLog(Employee $employee): AttendanceLog
    {
        $log = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('tenant_id', $employee->tenant_id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->orderByDesc('work_date')
            ->lockForUpdate()
            ->first();

        if ($log === null) {
            throw new AttendanceRejected('You do not have an active attendance session.');
        }

        return $log;
    }

    private function openBreak(AttendanceLog $log): ?AttendanceBreak
    {
        return AttendanceBreak::query()
            ->where('attendance_log_id', $log->id)
            ->where('tenant_id', $log->tenant_id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();
    }

    private function officeForLog(AttendanceLog $log, Employee $employee): Office
    {
        $officeId = $log->location_id ?: $employee->location_id;
        $office = $officeId
            ? Office::query()->whereKey($officeId)->where('tenant_id', $employee->tenant_id)->first()
            : EmployeeOffice::resolve((int) $employee->tenant_id, null);

        if ($office === null) {
            throw new AttendanceRejected('Your office network could not be verified. Contact HR.');
        }

        return $office;
    }

    private function dayStatus(Employee $employee, Office $office, CarbonInterface $local, WorkSchedule $schedule): string
    {
        $date = $local->toDateString();
        $holiday = AttendanceCalendar::holiday($employee, (int) $office->id, $date);

        if ($holiday !== null) {
            if (! AttendanceCalendar::authorized((int) $employee->tenant_id, (int) $employee->id, $date, 'holiday')) {
                throw new AttendanceRejected('This date is the '.$holiday->name.' holiday. Clock-in is closed unless HR authorizes holiday work.');
            }

            return 'holiday_work';
        }

        if (! $schedule->expected && ! AttendanceCalendar::authorized((int) $employee->tenant_id, (int) $employee->id, $date, 'weekly_off')) {
            throw new AttendanceRejected('This date is a weekly off. Clock-in is closed unless HR authorizes weekly-off work.');
        }

        return 'present';
    }

    private function shiftDate(Employee $employee, CarbonInterface $local): CarbonInterface
    {
        $schedule = WorkSchedule::forEmployee($employee, $local);
        $shiftDate = $schedule->attendanceDate($local);

        if ($shiftDate === null) {
            throw new AttendanceRejected($schedule->outsideWindowMessage());
        }

        return $shiftDate;
    }

    private function localNow(Office $office): Carbon
    {
        return now()->timezone($this->timezone($office));
    }

    private function timezone(Office $office): string
    {
        $timezone = $office->timezone;

        if (! is_string($timezone) || $timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = DB::table('tenants')->where('id', $office->tenant_id)->value('timezone');
        }

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('attendance.fallback_timezone', 'Asia/Kolkata');
    }

    private function workMinutes(AttendanceLog $log, string $checkIn, string $checkOut, string $timezone): int
    {
        $in = Carbon::parse($checkIn, $timezone);
        $out = Carbon::parse($checkOut, $timezone);
        $breakSeconds = AttendanceBreak::query()
            ->where('attendance_log_id', $log->id)
            ->whereNotNull('ended_at')
            ->get()
            ->sum(function (AttendanceBreak $break) use ($timezone): int {
                $start = Carbon::parse((string) $break->started_at, $timezone);
                $end = Carbon::parse((string) $break->ended_at, $timezone);

                return max(0, $end->getTimestamp() - $start->getTimestamp());
            });

        return (int) max(0, floor((($out->getTimestamp() - $in->getTimestamp()) - $breakSeconds) / 60));
    }
}
