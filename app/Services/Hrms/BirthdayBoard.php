<?php

namespace App\Services\Hrms;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BirthdayBoard
{
    /**
     * Month and day only. The birth year is not returned.
     *
     * @return array{today: list<array<string, mixed>>, upcoming: list<array<string, mixed>>}
     */
    public function upcoming(User $actor): array
    {
        $empty = ['today' => [], 'upcoming' => []];
        $companyId = $actor->companyId();

        if ($companyId === null || ! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'date_of_birth')) {
            return $empty;
        }

        if (! in_array($actor->applicationRole(), ['company_admin', 'hr', 'team_lead', 'employee', 'accounts'], true)) {
            return $empty;
        }

        $zone = $this->zone($companyId);
        $today = Carbon::now($zone)->startOfDay();
        $limit = $today->copy()->addDays(7);
        $rows = [];

        foreach (DB::table('employees')->where('tenant_id', $companyId)->where('status', 'active')->whereNotNull('date_of_birth')->get(['id', 'first_name', 'last_name', 'date_of_birth']) as $employee) {
            $born = Carbon::parse($employee->date_of_birth, $zone)->startOfDay();
            $next = $this->nextOccurrence($born, $today);

            if ($next->lt($today) || $next->gt($limit)) {
                continue;
            }

            $name = trim($employee->first_name.' '.$employee->last_name);
            $rows[] = [
                'id' => (int) $employee->id,
                'name' => $name,
                'initials' => $this->initials($name),
                'label' => $next->isSameDay($today) ? 'Today' : $next->format('F j'),
                'sort' => $next->timestamp,
                'today' => $next->isSameDay($today),
            ];
        }

        usort($rows, fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return [
            'today' => array_values(array_map(fn (array $row) => $this->present($row), array_filter($rows, fn (array $row): bool => $row['today']))),
            'upcoming' => array_values(array_map(fn (array $row) => $this->present($row), array_filter($rows, fn (array $row): bool => ! $row['today']))),
        ];
    }

    private function nextOccurrence(Carbon $born, Carbon $today): Carbon
    {
        $month = $born->month;
        $day = $born->day;
        $year = $today->year;

        if ($month === 2 && $day === 29 && ! Carbon::create($year, 1, 1, 0, 0, 0, $today->timezone)->isLeapYear()) {
            $day = 28;
        }

        $next = Carbon::create($year, $month, $day, 0, 0, 0, $today->timezone)->startOfDay();

        if ($next->lt($today)) {
            $year++;
            $day = $born->day;

            if ($month === 2 && $day === 29 && ! Carbon::create($year, 1, 1, 0, 0, 0, $today->timezone)->isLeapYear()) {
                $day = 28;
            }

            $next = Carbon::create($year, $month, $day, 0, 0, 0, $today->timezone)->startOfDay();
        }

        return $next;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        unset($row['sort'], $row['today']);

        return $row;
    }

    private function zone(int $companyId): string
    {
        $zone = (string) DB::table('tenants')->where('id', $companyId)->value('timezone');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Asia/Kolkata';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        return $letters === '' ? '?' : $letters;
    }
}
