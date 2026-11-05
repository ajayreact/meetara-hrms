<?php

namespace App\Services\Hrms;

use Carbon\Carbon;

class PublicHolidays
{
    /**
     * India dates are the Central Government gazetted holidays for Delhi/New Delhi.
     * Offices outside Delhi also close for these days. A date that falls on a weekly
     * off stays on that date, because no substitute day is granted.
     * United States dates are the federal holidays, on the observed weekday when the
     * statutory date falls on a Saturday or Sunday.
     *
     * @return list<array{name: string, date: string}>
     */
    public static function forCalendar(string $calendar, int $year): array
    {
        $rows = $calendar === 'usa' ? self::unitedStates() : self::india();

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => (int) substr($row['date'], 0, 4) === $year,
        ));
    }

    public static function name(string $calendar, string $date): ?string
    {
        $year = (int) substr($date, 0, 4);

        foreach (self::forCalendar($calendar, $year) as $row) {
            if ($row['date'] === $date) {
                return $row['name'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function dates(string $calendar, string $start, string $end): array
    {
        $dates = [];
        $cursor = Carbon::parse($start)->startOfYear();
        $last = Carbon::parse($end)->endOfYear();

        while ($cursor->lessThanOrEqualTo($last)) {
            foreach (self::forCalendar($calendar, (int) $cursor->year) as $row) {
                if ($row['date'] >= $start && $row['date'] <= $end) {
                    $dates[] = $row['date'];
                }
            }

            $cursor->addYear();
        }

        return array_values(array_unique($dates));
    }

    /**
     * @return list<array{name: string, date: string}>
     */
    private static function india(): array
    {
        return [
            ['name' => 'Republic Day', 'date' => '2026-01-26'],
            ['name' => 'Holi', 'date' => '2026-03-04'],
            ['name' => 'Id-ul-Fitr', 'date' => '2026-03-21'],
            ['name' => 'Ram Navami', 'date' => '2026-03-26'],
            ['name' => 'Mahavir Jayanti', 'date' => '2026-03-31'],
            ['name' => 'Good Friday', 'date' => '2026-04-03'],
            ['name' => 'Dr. B.R. Ambedkar Jayanti', 'date' => '2026-04-14'],
            ['name' => 'Buddha Purnima', 'date' => '2026-05-01'],
            ['name' => 'Id-ul-Zuha (Bakrid)', 'date' => '2026-05-27'],
            ['name' => 'Muharram', 'date' => '2026-06-26'],
            ['name' => 'Independence Day', 'date' => '2026-08-15'],
            ['name' => 'Milad-un-Nabi', 'date' => '2026-08-26'],
            ['name' => 'Janmashtami', 'date' => '2026-09-04'],
            ['name' => 'Mahatma Gandhi\'s Birthday', 'date' => '2026-10-02'],
            ['name' => 'Dussehra', 'date' => '2026-10-20'],
            ['name' => 'Diwali', 'date' => '2026-11-08'],
            ['name' => 'Guru Nanak\'s Birthday', 'date' => '2026-11-24'],
            ['name' => 'Christmas Day', 'date' => '2026-12-25'],
            ['name' => 'Republic Day', 'date' => '2027-01-26'],
            ['name' => 'Id-ul-Fitr', 'date' => '2027-03-10'],
            ['name' => 'Holi', 'date' => '2027-03-23'],
            ['name' => 'Good Friday', 'date' => '2027-03-26'],
            ['name' => 'Ram Navami', 'date' => '2027-04-15'],
            ['name' => 'Mahavir Jayanti', 'date' => '2027-04-19'],
            ['name' => 'Id-ul-Zuha (Bakrid)', 'date' => '2027-05-17'],
            ['name' => 'Buddha Purnima', 'date' => '2027-05-20'],
            ['name' => 'Muharram', 'date' => '2027-06-16'],
            ['name' => 'Independence Day and Milad-un-Nabi', 'date' => '2027-08-15'],
            ['name' => 'Janmashtami', 'date' => '2027-08-25'],
            ['name' => 'Mahatma Gandhi\'s Birthday', 'date' => '2027-10-02'],
            ['name' => 'Dussehra', 'date' => '2027-10-09'],
            ['name' => 'Diwali', 'date' => '2027-10-29'],
            ['name' => 'Guru Nanak\'s Birthday', 'date' => '2027-11-14'],
            ['name' => 'Christmas Day', 'date' => '2027-12-25'],
        ];
    }

    /**
     * @return list<array{name: string, date: string}>
     */
    private static function unitedStates(): array
    {
        return [
            ['name' => 'New Year\'s Day', 'date' => '2025-01-01'],
            ['name' => 'Birthday of Martin Luther King, Jr.', 'date' => '2025-01-20'],
            ['name' => 'Washington\'s Birthday', 'date' => '2025-02-17'],
            ['name' => 'Memorial Day', 'date' => '2025-05-26'],
            ['name' => 'Juneteenth National Independence Day', 'date' => '2025-06-19'],
            ['name' => 'Independence Day', 'date' => '2025-07-04'],
            ['name' => 'Labor Day', 'date' => '2025-09-01'],
            ['name' => 'Columbus Day', 'date' => '2025-10-13'],
            ['name' => 'Veterans Day', 'date' => '2025-11-11'],
            ['name' => 'Thanksgiving Day', 'date' => '2025-11-27'],
            ['name' => 'Christmas Day', 'date' => '2025-12-25'],
            ['name' => 'New Year\'s Day', 'date' => '2026-01-01'],
            ['name' => 'Birthday of Martin Luther King, Jr.', 'date' => '2026-01-19'],
            ['name' => 'Washington\'s Birthday', 'date' => '2026-02-16'],
            ['name' => 'Memorial Day', 'date' => '2026-05-25'],
            ['name' => 'Juneteenth National Independence Day', 'date' => '2026-06-19'],
            ['name' => 'Independence Day', 'date' => '2026-07-03'],
            ['name' => 'Labor Day', 'date' => '2026-09-07'],
            ['name' => 'Columbus Day', 'date' => '2026-10-12'],
            ['name' => 'Veterans Day', 'date' => '2026-11-11'],
            ['name' => 'Thanksgiving Day', 'date' => '2026-11-26'],
            ['name' => 'Christmas Day', 'date' => '2026-12-25'],
            ['name' => 'New Year\'s Day', 'date' => '2027-01-01'],
            ['name' => 'Birthday of Martin Luther King, Jr.', 'date' => '2027-01-18'],
            ['name' => 'Washington\'s Birthday', 'date' => '2027-02-15'],
            ['name' => 'Memorial Day', 'date' => '2027-05-31'],
            ['name' => 'Juneteenth National Independence Day', 'date' => '2027-06-18'],
            ['name' => 'Independence Day', 'date' => '2027-07-05'],
            ['name' => 'Labor Day', 'date' => '2027-09-06'],
            ['name' => 'Columbus Day', 'date' => '2027-10-11'],
            ['name' => 'Veterans Day', 'date' => '2027-11-11'],
            ['name' => 'Thanksgiving Day', 'date' => '2027-11-25'],
            ['name' => 'Christmas Day', 'date' => '2027-12-24'],
        ];
    }
}
