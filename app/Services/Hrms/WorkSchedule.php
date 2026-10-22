<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class WorkSchedule
{
    public function __construct(
        public bool $expected,
        public ?string $start,
        public ?string $end,
        public int $grace,
        public int $expectedMinutes,
    ) {}

    public static function forEmployee(Employee $employee, CarbonInterface $local): self
    {
        $shift = self::shift($employee, $local);
        $weekOff = self::weekOff((int) $employee->tenant_id);
        $day = strtolower($local->format('l'));
        $start = self::clock($shift?->start_time);
        $end = self::clock($shift?->end_time);
        $span = self::minutesBetween($start ?? '', $end ?? '');

        return new self(
            expected: ! in_array($day, $weekOff, true),
            start: $start,
            end: $end,
            grace: (int) ($shift->grace_minutes ?? 0),
            expectedMinutes: (int) ($shift->expected_minutes ?? $span ?? 0),
        );
    }

    public function lateMinutes(CarbonInterface $clockIn): int
    {
        if (! $this->expected || $this->start === null) {
            return 0;
        }

        $deadline = $clockIn->copy()->setTimeFromTimeString($this->start)->addMinutes($this->grace);

        if ($clockIn->lessThanOrEqualTo($deadline)) {
            return 0;
        }

        return (int) floor(($clockIn->getTimestamp() - $deadline->getTimestamp()) / 60);
    }

    public function crossesMidnight(): bool
    {
        return $this->start !== null && $this->end !== null && $this->end < $this->start;
    }

    public function earlyMinutes(CarbonInterface $clockOut, CarbonInterface $shiftStart): int
    {
        if (! $this->expected || $this->end === null) {
            return 0;
        }

        $end = $shiftStart->copy()->setTimeFromTimeString($this->end);

        if ($this->crossesMidnight()) {
            $end->addDay();
        }

        if ($clockOut->greaterThanOrEqualTo($end)) {
            return 0;
        }

        return (int) floor(($end->getTimestamp() - $clockOut->getTimestamp()) / 60);
    }

    /**
     * Minutes from start to end. An earlier end is the next day. Identical times are not a shift.
     */
    public static function minutesBetween(?string $start, ?string $end): ?int
    {
        if (! is_string($start) || ! is_string($end) || ! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end) || $start === $end) {
            return null;
        }

        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $span = (($endHour * 60) + $endMinute) - (($startHour * 60) + $startMinute);

        return $span < 0 ? $span + 1440 : $span;
    }

    private static function shift(Employee $employee, CarbonInterface $local): ?Shift
    {
        if (! Schema::hasTable('shifts')) {
            return null;
        }

        if (Schema::hasTable('shift_assignments')) {
            $assignment = $employee->getConnection()->table('shift_assignments')
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->where('effective_from', '<=', $local->toDateString())
                ->where(function ($query) use ($local): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>=', $local->toDateString());
                })
                ->orderByDesc('effective_from')
                ->first();

            if ($assignment !== null) {
                $shift = Shift::query()->inCompany((int) $employee->tenant_id)->whereKey($assignment->shift_id)->first();

                if ($shift !== null) {
                    return $shift;
                }
            }
        }

        if (Schema::hasColumn('employees', 'shift_id') && $employee->shift_id) {
            $shift = Shift::query()->inCompany((int) $employee->tenant_id)->whereKey($employee->shift_id)->first();

            if ($shift !== null) {
                return $shift;
            }
        }

        return Shift::query()->inCompany((int) $employee->tenant_id)->where('is_default', true)->first();
    }

    /**
     * @return list<string>
     */
    private static function weekOff(int $companyId): array
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'week_off')) {
            return [];
        }

        $days = Company::query()->whereKey($companyId)->value('week_off');

        if (is_string($days)) {
            $days = json_decode($days, true);
        }

        if (! is_array($days)) {
            return [];
        }

        return array_values(array_map(fn ($day) => strtolower((string) $day), $days));
    }

    private static function clock(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 5);
    }
}
