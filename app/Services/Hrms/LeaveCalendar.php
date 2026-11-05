<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class LeaveCalendar
{
    public function workingDays(Employee $employee, Carbon $start, Carbon $end): float
    {
        $weekOff = $this->weekOff((int) $employee->tenant_id);
        $holidays = $this->holidayDates($employee, $start, $end);
        $days = 0.0;
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $name = strtolower($cursor->format('l'));

            if (! in_array($name, $weekOff, true) && ! in_array($cursor->toDateString(), $holidays, true)) {
                $days += 1;
            }

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return list<string>
     */
    public function weekOff(int $companyId): array
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

    /**
     * @return list<string>
     */
    private function holidayDates(Employee $employee, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('holidays')) {
            return [];
        }

        $calendar = strtolower((string) $employee->holiday_calendar);
        $query = Holiday::query()
            ->inCompany((int) $employee->tenant_id)
            ->where('calendar', $calendar)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($inner) use ($employee): void {
                $inner->whereNull('location_id');

                if ($employee->location_id) {
                    $inner->orWhere('location_id', $employee->location_id);
                }
            });

        $rows = (clone $query)->get(['date', 'location_id', 'status']);
        $dates = $rows
            ->filter(fn (Holiday $holiday): bool => ! Schema::hasColumn('holidays', 'status') || $holiday->status === 'active')
            ->map(fn (Holiday $holiday): string => $holiday->date?->toDateString() ?? '')
            ->filter()
            ->all();
        $covered = $rows
            ->filter(fn (Holiday $holiday): bool => $holiday->location_id === null)
            ->map(fn (Holiday $holiday): string => $holiday->date?->toDateString() ?? '')
            ->filter()
            ->all();

        foreach (PublicHolidays::dates($calendar, $start->toDateString(), $end->toDateString()) as $date) {
            if (! in_array($date, $covered, true)) {
                $dates[] = $date;
            }
        }

        return array_values(array_unique($dates));
    }
}
