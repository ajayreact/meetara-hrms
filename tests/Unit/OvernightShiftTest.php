<?php

namespace Tests\Unit;

use App\Services\Hrms\WorkSchedule;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class OvernightShiftTest extends TestCase
{
    public function test_same_day_and_overnight_lengths_reject_an_identical_time(): void
    {
        $this->assertSame(540, WorkSchedule::minutesBetween('09:30', '18:30'));
        $this->assertSame(540, WorkSchedule::minutesBetween('18:30', '03:30'));
        $this->assertSame(1439, WorkSchedule::minutesBetween('00:00', '23:59'));
        $this->assertNull(WorkSchedule::minutesBetween('18:30', '18:30'));
        $this->assertNull(WorkSchedule::minutesBetween('9:30', '18:30'));
    }

    public function test_lateness_and_early_leave_follow_the_shift_across_midnight(): void
    {
        $overnight = new WorkSchedule(true, '18:30', '03:30', 10, 540);
        $start = Carbon::parse('2026-10-16 18:30:00', 'Asia/Kolkata');

        $this->assertTrue($overnight->crossesMidnight());
        $this->assertSame(0, $overnight->lateMinutes($start->copy()->addMinutes(10)));
        $this->assertSame(5, $overnight->lateMinutes($start->copy()->addMinutes(15)));
        $this->assertSame(10, $overnight->earlyMinutes(Carbon::parse('2026-10-17 03:20:00', 'Asia/Kolkata'), $start));
        $this->assertSame(0, $overnight->earlyMinutes(Carbon::parse('2026-10-17 03:30:00', 'Asia/Kolkata'), $start));
        $this->assertSame(270, $overnight->earlyMinutes(Carbon::parse('2026-10-16 23:00:00', 'Asia/Kolkata'), $start));

        $day = new WorkSchedule(true, '09:30', '18:30', 10, 540);
        $morning = Carbon::parse('2026-10-16 09:30:00', 'Asia/Kolkata');

        $this->assertFalse($day->crossesMidnight());
        $this->assertSame(90, $day->earlyMinutes($morning->copy()->setTime(17, 0), $morning));
        $this->assertSame(0, $day->earlyMinutes($morning->copy()->setTime(18, 30), $morning));
        $this->assertSame(0, $day->earlyMinutes(Carbon::parse('2026-10-17 00:10:00', 'Asia/Kolkata'), $morning));
    }

    public function test_us_conversions_follow_iana_rules_across_standard_time_daylight_time_and_date_boundaries(): void
    {
        $this->assertZone('2026-01-15 18:30:00', 'America/New_York', '2026-01-15', '8:00 AM', 'EST');
        $this->assertZone('2026-01-15 18:30:00', 'America/Detroit', '2026-01-15', '8:00 AM', 'EST');
        $this->assertZone('2026-01-15 18:30:00', 'America/Chicago', '2026-01-15', '7:00 AM', 'CST');
        $this->assertZone('2026-01-16 03:30:00', 'America/New_York', '2026-01-15', '5:00 PM', 'EST');
        $this->assertZone('2026-01-16 03:30:00', 'America/Detroit', '2026-01-15', '5:00 PM', 'EST');
        $this->assertZone('2026-01-16 03:30:00', 'America/Chicago', '2026-01-15', '4:00 PM', 'CST');

        $this->assertZone('2026-07-15 18:30:00', 'America/New_York', '2026-07-15', '9:00 AM', 'EDT');
        $this->assertZone('2026-07-15 18:30:00', 'America/Detroit', '2026-07-15', '9:00 AM', 'EDT');
        $this->assertZone('2026-07-15 18:30:00', 'America/Chicago', '2026-07-15', '8:00 AM', 'CDT');
        $this->assertZone('2026-07-16 03:30:00', 'America/New_York', '2026-07-15', '6:00 PM', 'EDT');
        $this->assertZone('2026-07-16 03:30:00', 'America/Chicago', '2026-07-15', '5:00 PM', 'CDT');

        $this->assertZone('2026-03-07 18:30:00', 'America/New_York', '2026-03-07', '8:00 AM', 'EST');
        $this->assertZone('2026-03-08 18:30:00', 'America/New_York', '2026-03-08', '9:00 AM', 'EDT');
        $this->assertZone('2026-03-08 18:30:00', 'America/Chicago', '2026-03-08', '8:00 AM', 'CDT');
        $this->assertZone('2026-11-01 18:30:00', 'America/New_York', '2026-11-01', '8:00 AM', 'EST');
        $this->assertZone('2026-11-01 18:30:00', 'America/Chicago', '2026-11-01', '7:00 AM', 'CST');

        $this->assertZone('2027-01-01 03:30:00', 'America/New_York', '2026-12-31', '5:00 PM', 'EST');
        $this->assertZone('2028-02-29 03:30:00', 'America/Chicago', '2028-02-28', '4:00 PM', 'CST');
    }

    private function assertZone(string $indiaTime, string $zone, string $date, string $clock, string $abbreviation): void
    {
        $local = Carbon::parse($indiaTime, 'Asia/Kolkata')->timezone($zone);

        $this->assertSame($date, $local->toDateString(), $zone.' '.$indiaTime);
        $this->assertSame($clock, $local->format('g:i A'), $zone.' '.$indiaTime);
        $this->assertSame($abbreviation, $local->format('T'), $zone.' '.$indiaTime);
    }
}
