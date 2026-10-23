<?php

namespace App\Services\Hrms;

use App\Models\AttendanceBreak;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LocationNetwork;
use App\Models\Office;
use App\Models\User;
use App\Support\ClientAddress;
use App\Support\GeoDistance;
use App\Support\NetworkAddress;
use App\Support\OfficeVerification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            $observed = $this->requireNetwork($request, $office, (int) $employee->tenant_id);
            $gpsVerified = $this->requireLocation($request, $office);
            $local = $this->localNow($office);
            $this->assertNoOpenSession($employee, $local);

            $existing = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('tenant_id', $employee->tenant_id)
                ->whereDate('work_date', $local->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new AttendanceRejected('You already have an attendance record for today.');
            }

            $schedule = WorkSchedule::forEmployee($employee, $local);

            try {
                $log = new AttendanceLog;
                $log->forceFill([
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'location_id' => $office->id,
                    'work_date' => $local->toDateString(),
                    'check_in' => $local->format('Y-m-d H:i:s'),
                    'work_mode' => $employee->work_mode ?: 'office',
                    'check_in_ip' => $observed,
                    'verified_as' => $gpsVerified ? 'public_ip_and_gps' : 'public_ip',
                    'status' => 'present',
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
            $local = $this->localNow($office);
            $observed = $this->clientAddress->observe($request);
            $checkIn = (string) $log->check_in;
            $schedule = WorkSchedule::forEmployee($employee, Carbon::parse($checkIn, $this->timezone($office)));

            $log->forceFill([
                'check_out' => $local->format('Y-m-d H:i:s'),
                'check_out_ip' => $observed['ok'] ? $observed['ip'] : null,
                'minutes' => $this->workMinutes($log, $checkIn, $local->format('Y-m-d H:i:s'), $this->timezone($office)),
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
        if ($employee->location_id === null) {
            throw new AttendanceRejected('Your office network could not be verified. Contact HR.');
        }

        if ($requestedOfficeId !== null && $requestedOfficeId !== (int) $employee->location_id) {
            throw new AttendanceRejected('You are not authorized to attend that office.');
        }

        $office = Office::query()->whereKey($employee->location_id)->where('tenant_id', $employee->tenant_id)->first();

        if ($office === null) {
            throw new AttendanceRejected('Your office network could not be verified. Contact HR.');
        }

        if ((string) $office->status !== 'active') {
            throw new AttendanceRejected('Your assigned office is inactive. Contact your administrator.');
        }

        return $office;
    }

    private function requireNetwork(Request $request, Office $office, int $companyId): string
    {
        $observed = $this->clientAddress->observe($request);

        if (! $observed['ok'] || $observed['ip'] === null) {
            $this->logNetworkDenial($request, $office, $companyId, null, 'address_unresolved');
            $invalidProxy = str_contains($observed['reason'], 'invalid');

            throw new AttendanceRejected($invalidProxy
                ? 'Clock-in was blocked because the trusted-proxy configuration is invalid.'
                : 'Clock-in was blocked because a forwarded address arrived from an untrusted connection.');
        }

        $cidrs = LocationNetwork::query()
            ->where('tenant_id', $companyId)
            ->where('location_id', $office->id)
            ->pluck('cidr')
            ->all();

        $failure = NetworkAddress::denialReason($observed['ip'], $cidrs);

        if ($failure !== null) {
            $this->logNetworkDenial($request, $office, $companyId, $observed['ip'], $failure);

            throw new AttendanceRejected($this->networkDenialMessage($failure, $observed['ip']));
        }

        return $observed['ip'];
    }

    private function networkDenialMessage(string $failure, string $ip): string
    {
        if ($failure !== 'address_not_public') {
            return 'You are not connected through an approved office network. Clock-in is blocked.';
        }

        $loopback = $ip === '::1' || str_starts_with($ip, '127.');

        return $loopback
            ? 'Clock-in was blocked because this connection is localhost. The server does not see the office public address.'
            : 'Clock-in was blocked because this connection is a private network address. The server does not see the office public address.';
    }

    private function logNetworkDenial(Request $request, Office $office, int $companyId, ?string $resolvedIp, string $reason): void
    {
        Log::warning('Clock-in was denied by the approved office network check.', [
            'reason' => $reason,
            'resolved_ip' => $resolvedIp,
            ...$this->clientAddress->context($request),
            'tenant_id' => $companyId,
            'office_id' => $office->id,
        ]);
    }

    private function requireLocation(Request $request, Office $office): bool
    {
        if (! OfficeVerification::columnsAvailable() || ! $office->gps_verification_enabled) {
            return false;
        }

        if (! OfficeVerification::coordinatesValid($office->latitude, $office->longitude)) {
            throw new AttendanceRejected('GPS verification is enabled, but this office has no saved location. Clock-in is blocked.');
        }

        if (! OfficeVerification::radiusValid($office->verification_radius_meters)) {
            throw new AttendanceRejected('This office location radius is not valid. Clock-in is blocked.');
        }

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');

        if (! OfficeVerification::coordinatesValid($latitude, $longitude)) {
            throw new AttendanceRejected('A browser location inside the office radius is required. Clock-in is blocked.');
        }

        $distance = GeoDistance::meters(
            (float) $office->latitude,
            (float) $office->longitude,
            (float) $latitude,
            (float) $longitude,
        );

        if ($distance > (int) $office->verification_radius_meters) {
            throw new AttendanceRejected('You are outside the configured office location. Clock-in is blocked.');
        }

        return true;
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
        $office = Office::query()->whereKey($officeId)->where('tenant_id', $employee->tenant_id)->first();

        if ($office === null) {
            throw new AttendanceRejected('Your office network could not be verified. Contact HR.');
        }

        return $office;
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

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
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
