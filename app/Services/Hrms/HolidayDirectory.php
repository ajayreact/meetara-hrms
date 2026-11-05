<?php

namespace App\Services\Hrms;

use App\Models\Holiday;
use App\Models\Office;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class HolidayDirectory
{
    /**
     * @return array<string, mixed>
     */
    public function page(User $user, string $calendar, int $year): array
    {
        $companyId = $user->companyId();
        $calendar = in_array($calendar, ['india', 'usa'], true) ? $calendar : 'india';

        if ($companyId === null) {
            return ['holidays' => [], 'calendar' => $calendar, 'year' => $year, 'canManage' => false, 'offices' => []];
        }

        $query = Holiday::query()
            ->inCompany($companyId)
            ->where('calendar', $calendar)
            ->whereYear('date', $year)
            ->orderBy('date');

        $canManage = in_array($user->applicationRole(), ['company_admin', 'hr'], true);
        $stored = $query->get();
        $covered = $stored
            ->filter(fn (Holiday $holiday): bool => $holiday->location_id === null)
            ->map(fn (Holiday $holiday): string => $holiday->date?->toDateString() ?? '')
            ->filter()
            ->all();
        $visible = $stored
            ->filter(fn (Holiday $holiday): bool => $canManage || ! Schema::hasColumn('holidays', 'status') || $holiday->status === 'active')
            ->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'date' => $holiday->date?->toDateString(),
                'weekday' => $holiday->date?->format('l'),
                'calendar' => $holiday->calendar === 'usa' ? 'United States' : 'India',
                'officeId' => $holiday->location_id,
                'status' => Schema::hasColumn('holidays', 'status') ? (string) $holiday->status : 'active',
                'standard' => false,
            ])
            ->all();

        foreach (PublicHolidays::forCalendar($calendar, $year) as $row) {
            if (in_array($row['date'], $covered, true)) {
                continue;
            }

            $date = Carbon::parse($row['date']);
            $visible[] = [
                'id' => 'standard-'.$calendar.'-'.$row['date'],
                'name' => $row['name'],
                'date' => $row['date'],
                'weekday' => $date->format('l'),
                'calendar' => $calendar === 'usa' ? 'United States' : 'India',
                'officeId' => null,
                'status' => 'active',
                'standard' => true,
            ];
        }

        usort($visible, fn (array $left, array $right): int => strcmp((string) $left['date'], (string) $right['date']));

        return [
            'holidays' => $visible,
            'calendar' => $calendar,
            'year' => $year,
            'canManage' => $canManage,
            'offices' => Office::query()->inCompany($companyId)->orderBy('name')->get(['id', 'name'])->map(fn (Office $office) => [
                'id' => $office->id,
                'name' => $office->name,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, array $input, ?Holiday $holiday = null): Holiday
    {
        $companyId = (int) $actor->companyId();
        $officeId = $input['location_id'] ?? null;

        if ($officeId !== null && ! Office::query()->inCompany($companyId)->whereKey($officeId)->exists()) {
            throw new LeaveRejected('Choose an office in your company.');
        }

        $date = Carbon::parse($input['date'])->toDateString();
        $duplicate = Holiday::query()
            ->inCompany($companyId)
            ->where('calendar', $input['calendar'])
            ->whereDate('date', $date)
            ->when($officeId === null, fn ($query) => $query->whereNull('location_id'), fn ($query) => $query->where('location_id', $officeId))
            ->when($holiday !== null, fn ($query) => $query->whereKeyNot($holiday->id))
            ->exists();

        if ($duplicate) {
            throw new LeaveRejected('That holiday is already recorded for this calendar.');
        }

        $record = $holiday ?? new Holiday;
        $record->forceFill([
            'tenant_id' => $companyId,
            'name' => $input['name'],
            'date' => $date,
            'calendar' => $input['calendar'],
            'location_id' => $officeId,
            'status' => $input['status'] ?? $holiday?->status ?? 'active',
        ])->save();

        return $record;
    }
}
