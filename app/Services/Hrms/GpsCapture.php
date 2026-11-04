<?php

namespace App\Services\Hrms;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use App\Support\OfficeVerification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GpsCapture
{
    public const NOTICE_VERSION = '2026-10-22.1';

    /**
     * Stored attendance points keep five decimal places, about 1.1 meters.
     * The office-radius check uses the browser reading before this rounding.
     */
    public const STORED_COORDINATE_DECIMALS = 5;

    public const MIN_RETENTION_DAYS = 1;

    public const MAX_RETENTION_DAYS = 3650;

    /**
     * The acknowledged version includes the retention period, so a changed period needs a new acknowledgement.
     */
    public function noticeVersion(): string
    {
        $days = $this->retentionDays();

        return self::NOTICE_VERSION.($days === null ? '.retention-invalid' : '.r'.$days);
    }

    public function retentionDays(): ?int
    {
        $value = config('hrms.gps_location_retention_days');

        if (! is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            return null;
        }

        $days = (int) $value;

        return $days >= self::MIN_RETENTION_DAYS && $days <= self::MAX_RETENTION_DAYS ? $days : null;
    }

    public function retentionLabel(): string
    {
        $days = $this->retentionDays();

        return $days === null ? 'Invalid retention setting. Cleanup is stopped' : $days.' days after capture';
    }

    /**
     * Deletes location records captured before the retention cutoff, one company at a time.
     * The cutoff is the application clock minus the retention period. It follows
     * config('app.timezone'), which defaults to UTC, and does not follow an office timezone.
     * Acknowledgements, view events, retention events, and attendance logs are left in place.
     * Each company with deletions receives an audit row holding only the count and cutoff.
     *
     * @return array{retentionDays: int, cutoff: string, deleted: array<int, int>}
     */
    public function prune(bool $dryRun = false): array
    {
        $days = $this->retentionDays();

        if ($days === null) {
            throw new \RuntimeException('GPS location retention must be a whole number of days from '.self::MIN_RETENTION_DAYS.' to '.self::MAX_RETENTION_DAYS.'. No records were deleted.');
        }

        if (! Schema::hasTable('attendance_location_records')) {
            throw new \RuntimeException('GPS location storage is not installed. No records were deleted.');
        }

        $cutoff = now()->subDays($days);
        $deleted = [];
        $tenants = DB::table('attendance_location_records')
            ->where('captured_at', '<', $cutoff)
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            $tenantId = (int) $tenantId;

            if ($dryRun) {
                $deleted[$tenantId] = DB::table('attendance_location_records')
                    ->where('tenant_id', $tenantId)
                    ->where('captured_at', '<', $cutoff)
                    ->count();

                continue;
            }

            $deleted[$tenantId] = DB::transaction(function () use ($tenantId, $cutoff, $days): int {
                $count = DB::table('attendance_location_records')
                    ->where('tenant_id', $tenantId)
                    ->where('captured_at', '<', $cutoff)
                    ->delete();

                if ($count > 0 && Schema::hasTable('attendance_location_retention_events')) {
                    DB::table('attendance_location_retention_events')->insert([
                        'tenant_id' => $tenantId,
                        'retention_days' => $days,
                        'cutoff_at' => $cutoff,
                        'deleted_count' => $count,
                        'created_at' => now(),
                    ]);
                }

                return $count;
            });
        }

        return ['retentionDays' => $days, 'cutoff' => $cutoff->toDateTimeString(), 'deleted' => $deleted];
    }

    public function ready(): bool
    {
        return Schema::hasTable('company_attendance_settings')
            && Schema::hasTable('gps_attendance_acknowledgements')
            && Schema::hasTable('attendance_location_records');
    }

    public function enabled(int $companyId): bool
    {
        if (! $this->ready()) {
            return false;
        }

        return (bool) DB::table('company_attendance_settings')
            ->where('tenant_id', $companyId)
            ->value('gps_capture_enabled');
    }

    /**
     * Capture also needs a valid retention period, so a bad setting stops collection instead of keeping points indefinitely.
     */
    public function captureAllowed(int $companyId): bool
    {
        return $this->retentionDays() !== null && $this->enabled($companyId);
    }

    public function notice(): string
    {
        $days = $this->retentionDays();
        $retention = $days === null
            ? ' The retention setting is invalid, so locations are not collected until it is corrected.'
            : ' Saved points are deleted automatically '.$days.' days after capture.';

        return 'When this setting is on, the browser location you share at clock-in or clock-out can be saved. It is requested only during that action, after you acknowledge this notice. Saved coordinates are rounded to about one meter. It is shown to you and to people in your company who can already open your attendance. It is not continuous tracking. A browser location can be inaccurate or spoofed, and it does not replace the approved office network check. Head of Operations and HR can open company attendance locations. A team lead can open locations only for direct reports. Accounts and platform administrators do not receive these coordinates.'.$retention;
    }

    public function setEnabled(User $actor, bool $enabled): void
    {
        abort_unless($actor->applicationRole() === 'company_admin' && $actor->companyId() !== null, 403);
        abort_unless($this->ready(), 404);

        $companyId = (int) $actor->companyId();
        $existing = DB::table('company_attendance_settings')->where('tenant_id', $companyId)->first();

        if ($existing === null) {
            DB::table('company_attendance_settings')->insert([
                'tenant_id' => $companyId,
                'gps_capture_enabled' => $enabled,
                'notice_version' => $this->noticeVersion(),
                'updated_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('company_attendance_settings')->where('tenant_id', $companyId)->update([
                'gps_capture_enabled' => $enabled,
                'notice_version' => $this->noticeVersion(),
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('company_attendance_setting_events')) {
            DB::table('company_attendance_setting_events')->insert([
                'tenant_id' => $companyId,
                'actor_id' => $actor->id,
                'action' => $enabled ? 'enabled' : 'disabled',
                'created_at' => now(),
            ]);
        }
    }

    public function acknowledge(User $actor): void
    {
        $employee = $this->employee($actor);
        abort_if($employee === null, 404);
        abort_unless($this->captureAllowed((int) $employee->tenant_id), 403);

        $exists = DB::table('gps_attendance_acknowledgements')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('notice_version', $this->noticeVersion())
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('gps_attendance_acknowledgements')->insert([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'user_id' => $actor->id,
            'notice_version' => $this->noticeVersion(),
            'acknowledged_at' => now(),
        ]);
    }

    public function shouldRequest(User $actor): bool
    {
        $employee = $this->employee($actor);

        return $employee !== null
            && $this->captureAllowed((int) $employee->tenant_id)
            && $this->acknowledgementId($employee) !== null;
    }

    public function noticePending(User $actor): bool
    {
        $employee = $this->employee($actor);

        return $employee !== null
            && $this->captureAllowed((int) $employee->tenant_id)
            && $this->acknowledgementId($employee) === null;
    }

    public function record(User $actor, Employee $employee, AttendanceLog $log, string $event, Request $request): void
    {
        if (! in_array($event, ['clock_in', 'clock_out'], true) || ! $this->ready()) {
            return;
        }

        if ((int) $log->tenant_id !== (int) $employee->tenant_id || (int) $log->employee_id !== (int) $employee->id) {
            return;
        }

        if ((int) $actor->id !== (int) $employee->user_id || ! $this->captureAllowed((int) $employee->tenant_id)) {
            return;
        }

        $acknowledgementId = $this->acknowledgementId($employee);

        if ($acknowledgementId === null) {
            return;
        }

        $exists = DB::table('attendance_location_records')
            ->where('attendance_log_id', $log->id)
            ->where('event_type', $event)
            ->exists();

        if ($exists) {
            return;
        }

        $parsed = $this->parse($request);

        DB::table('attendance_location_records')->insert([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'attendance_log_id' => $log->id,
            'event_type' => $event,
            'latitude' => $parsed['status'] === 'recorded' ? $parsed['latitude'] : null,
            'longitude' => $parsed['status'] === 'recorded' ? $parsed['longitude'] : null,
            'accuracy_meters' => $parsed['status'] === 'recorded' ? $parsed['accuracy'] : null,
            'status' => $parsed['status'],
            'acknowledgement_id' => $acknowledgementId,
            'captured_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function page(User $actor, ?string $from, ?string $to, ?int $employeeId): array
    {
        $role = $actor->applicationRole();
        $companyId = $actor->companyId();
        $precise = in_array($role, ['company_admin', 'hr', 'team_lead', 'employee'], true) && $companyId !== null;
        $range = $this->range($from, $to);
        $employee = $precise ? $this->employee($actor) : null;
        $acknowledgementId = $employee !== null ? $this->acknowledgementId($employee) : null;

        return [
            'ready' => $this->ready(),
            'captureEnabled' => $companyId !== null && $this->enabled($companyId),
            'canManageCapture' => $role === 'company_admin',
            'canViewPreciseLocations' => $precise,
            'notice' => $this->notice(),
            'noticeVersion' => $this->noticeVersion(),
            'retention' => $this->retentionLabel(),
            'acknowledged' => $acknowledgementId !== null,
            'canAcknowledge' => $employee !== null && $companyId !== null && $this->captureAllowed($companyId) && $acknowledgementId === null,
            'acknowledgements' => $precise ? $this->acknowledgements($actor) : [],
            'offices' => $this->offices($actor),
            'filters' => [
                'from' => $range[0]->toDateString(),
                'to' => $range[1]->toDateString(),
                'employee' => $precise ? (string) ($this->scopedEmployeeId($actor, $employeeId) ?? '') : '',
            ],
            'employees' => $precise ? $this->employeeChoices($actor) : [],
            'points' => $precise ? $this->points($actor, $range[0], $range[1], $employeeId) : [],
        ];
    }

    /**
     * @return array{latitude: ?float, longitude: ?float, accuracy: ?int, status: string}
     */
    private function parse(Request $request): array
    {
        $result = (string) $request->input('capture_result', '');
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $valid = OfficeVerification::coordinatesValid($latitude, $longitude);
        $accuracy = $request->input('accuracy_meters');
        $accuracyValue = is_numeric($accuracy) ? (int) $accuracy : null;

        if ($accuracyValue !== null && ($accuracyValue < 0 || $accuracyValue > 100000)) {
            $accuracyValue = null;
        }

        if (in_array($result, ['denied', 'unavailable'], true)) {
            return ['latitude' => null, 'longitude' => null, 'accuracy' => null, 'status' => $result];
        }

        if (! $valid || ($result !== '' && $result !== 'recorded')) {
            return ['latitude' => null, 'longitude' => null, 'accuracy' => null, 'status' => 'invalid'];
        }

        return [
            'latitude' => round((float) $latitude, self::STORED_COORDINATE_DECIMALS),
            'longitude' => round((float) $longitude, self::STORED_COORDINATE_DECIMALS),
            'accuracy' => $accuracyValue,
            'status' => 'recorded',
        ];
    }

    private function acknowledgementId(Employee $employee): ?int
    {
        if (! $this->ready()) {
            return null;
        }

        $id = DB::table('gps_attendance_acknowledgements')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('notice_version', $this->noticeVersion())
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function employee(User $actor): ?Employee
    {
        $companyId = $actor->companyId();

        if ($companyId === null) {
            return null;
        }

        return Employee::query()->where('user_id', $actor->id)->where('tenant_id', $companyId)->first();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(?string $from, ?string $to): array
    {
        $end = $this->day($to) ?? now()->startOfDay();
        $start = $this->day($from) ?? $end->copy()->subDays(6);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > 31) {
            $start = $end->copy()->subDays(31);
        }

        return [$start, $end];
    }

    private function day(?string $value): ?Carbon
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offices(User $actor): array
    {
        if (! Schema::hasTable('locations') || ! OfficeVerification::columnsAvailable()) {
            return [];
        }

        $query = Office::query()->orderBy('name');

        if ($actor->applicationRole() === 'super_admin') {
            $query->where('status', 'active');
        } elseif ($actor->companyId() !== null) {
            $query->where('tenant_id', $actor->companyId());
        } else {
            return [];
        }

        return $query->get()->map(function (Office $office): array {
            $valid = OfficeVerification::coordinatesValid($office->latitude, $office->longitude);
            $active = (string) $office->status !== 'inactive';
            $label = is_string($office->map_label) && $office->map_label !== '' ? $office->map_label : (string) $office->name;

            return [
                'id' => (int) $office->id,
                'name' => (string) $office->name,
                'mapLabel' => $label,
                'address' => $office->address,
                'status' => $active ? 'Active' : 'Inactive',
                'onMap' => $active && $valid,
                'latitude' => $active && $valid ? (float) $office->latitude : null,
                'longitude' => $active && $valid ? (float) $office->longitude : null,
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function points(User $actor, Carbon $from, Carbon $to, ?int $employeeId): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null || ! $this->ready()) {
            return [];
        }

        if ($employeeId !== null && $actor->applicationRole() === 'employee' && $employeeId !== $this->employee($actor)?->id) {
            abort(404);
        }

        $scoped = $this->scopedEmployeeId($actor, $employeeId);

        if ($employeeId !== null && $scoped === null) {
            abort(404);
        }

        $allowed = $this->allowedEmployeeIds($actor);

        if ($allowed === []) {
            return [];
        }

        $rows = DB::table('attendance_location_records')
            ->where('tenant_id', $companyId)
            ->whereIn('employee_id', $allowed)
            ->when($scoped !== null, fn ($query) => $query->where('employee_id', $scoped))
            ->whereDate('captured_at', '>=', $from->toDateString())
            ->whereDate('captured_at', '<=', $to->toDateString())
            ->orderBy('captured_at')
            ->get();

        $names = Employee::query()->where('tenant_id', $companyId)->whereIn('id', $rows->pluck('employee_id')->all())->get()->mapWithKeys(fn (Employee $employee) => [
            $employee->id => trim($employee->first_name.' '.$employee->last_name),
        ]);
        $revealedIds = [];
        $points = $rows->map(function ($row) use ($names, &$revealedIds): array {
            $recorded = (string) $row->status === 'recorded'
                && OfficeVerification::coordinatesValid($row->latitude, $row->longitude);

            if ($recorded) {
                $revealedIds[] = (int) $row->employee_id;
            }

            return [
                'id' => (int) $row->id,
                'employee' => (string) ($names[$row->employee_id] ?? 'Employee'),
                'event' => (string) $row->event_type === 'clock_out' ? 'Clock-out' : 'Clock-in',
                'at' => (string) $row->captured_at,
                'status' => $recorded ? 'Recorded' : $this->statusLabel((string) $row->status),
                'accuracyMeters' => $recorded && $row->accuracy_meters !== null ? (int) $row->accuracy_meters : null,
                'latitude' => $recorded ? (float) $row->latitude : null,
                'longitude' => $recorded ? (float) $row->longitude : null,
            ];
        })->all();

        if ($revealedIds !== [] && Schema::hasTable('attendance_location_view_events')) {
            DB::table('attendance_location_view_events')->insert([
                'tenant_id' => $companyId,
                'actor_id' => $actor->id,
                'subject' => self::viewAuditSubject($actor->applicationRole(), $revealedIds, $from->toDateString(), $to->toDateString()),
                'created_at' => now(),
            ]);
        }

        return $points;
    }

    /**
     * @return list<int>
     */
    private function allowedEmployeeIds(User $actor): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null) {
            return [];
        }

        $query = Employee::query()->where('tenant_id', $companyId);

        if ($actor->applicationRole() === 'employee') {
            $query->where('user_id', $actor->id);
        } elseif ($actor->applicationRole() === 'team_lead') {
            $actorEmployee = $this->employee($actor);

            if ($actorEmployee === null) {
                return [];
            }

            $query->where(function ($inner) use ($actorEmployee): void {
                $inner->where('id', $actorEmployee->id)->orWhere('manager_id', $actorEmployee->id);
            });
        } elseif (! in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            return [];
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function scopedEmployeeId(User $actor, ?int $employeeId): ?int
    {
        if ($actor->applicationRole() === 'employee') {
            return $this->employee($actor)?->id;
        }

        if ($employeeId === null) {
            return null;
        }

        return in_array($employeeId, $this->allowedEmployeeIds($actor), true) ? $employeeId : null;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function employeeChoices(User $actor): array
    {
        if (! in_array($actor->applicationRole(), ['company_admin', 'hr', 'team_lead'], true)) {
            return [];
        }

        $ids = $this->allowedEmployeeIds($actor);

        return Employee::query()->whereIn('id', $ids)->orderBy('first_name')->get()->map(fn (Employee $employee) => [
            'id' => (int) $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name),
        ])->all();
    }

    /**
     * @return list<array{employee: string, version: string, at: string}>
     */
    private function acknowledgements(User $actor): array
    {
        if (! $this->ready()) {
            return [];
        }

        $ids = $this->allowedEmployeeIds($actor);

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('gps_attendance_acknowledgements')
            ->where('tenant_id', $actor->companyId())
            ->whereIn('employee_id', $ids)
            ->orderByDesc('acknowledged_at')
            ->get();
        $names = Employee::query()->where('tenant_id', $actor->companyId())->whereIn('id', $rows->pluck('employee_id')->all())->get()->mapWithKeys(fn (Employee $employee) => [
            $employee->id => trim($employee->first_name.' '.$employee->last_name),
        ]);

        return $rows->map(fn ($row) => [
            'employee' => (string) ($names[$row->employee_id] ?? 'Employee'),
            'version' => (string) $row->notice_version,
            'at' => (string) $row->acknowledged_at,
        ])->all();
    }

    /**
     * Fits the existing 40-character subject column. s, t, and c mean self, team, and company.
     * One revealed employee is stored as e{id}. More than one is stored as n{count}, without a list of ids.
     * The dates are the requested range as YYYYMMDD. A row is written only when coordinates were returned.
     *
     * @param  list<int>  $employeeIds
     */
    public static function viewAuditSubject(string $role, array $employeeIds, string $from, string $to): string
    {
        $scope = match ($role) {
            'employee' => 's',
            'team_lead' => 't',
            default => 'c',
        };
        $range = str_replace('-', '', $from).':'.str_replace('-', '', $to);
        $ids = array_values(array_unique(array_map(intval(...), $employeeIds)));
        sort($ids);

        if (count($ids) === 1) {
            $candidate = $scope.':e'.$ids[0].':'.$range;

            if (strlen($candidate) <= 40) {
                return $candidate;
            }
        }

        $summary = $scope.':n'.count($ids).':'.$range;

        return strlen($summary) <= 40 ? $summary : $scope;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'denied' => 'Permission denied',
            'unavailable' => 'Unavailable',
            'invalid' => 'Invalid',
            default => 'Not recorded',
        };
    }
}
