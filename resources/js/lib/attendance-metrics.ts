export interface TimeLogDay {
    day: number;
    dateLabel: string;
    weekday: string;
    expected: boolean;
    occurred: boolean;
    workMinutes: number;
    breakMinutes: number;
    missing: boolean;
    lateMinutes: number;
    earlyMinutes: number;
    clockIn: string | null;
    clockOut: string | null;
    clockInIp: string | null;
    clockOutIp: string | null;
    networkStatus: 'verified' | 'unverified' | 'not_recorded';
}

export interface MonthSummary {
    expectedMinutes: number;
    actualMinutes: number;
    remainingMinutes: number;
    earlyMinutes: number;
    present: number;
    absent: number;
    late: number;
    earlyOut: number;
}

export function formatDuration(totalMinutes: number): string {
    const minutes = Math.max(0, Math.round(totalMinutes));
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;

    return `${String(hours).padStart(2, '0')} H : ${String(mins).padStart(2, '0')} M`;
}

export function summarizeMonth(days: TimeLogDay[], expectedMinutesPerDay = 480): MonthSummary {
    const expectedMinutes = days.filter((day) => day.expected).length * expectedMinutesPerDay;
    const actualMinutes = days.reduce((sum, day) => sum + day.workMinutes, 0);

    return {
        expectedMinutes,
        actualMinutes,
        remainingMinutes: Math.max(expectedMinutes - actualMinutes, 0),
        earlyMinutes: days.reduce((sum, day) => sum + day.earlyMinutes, 0),
        present: days.filter((day) => day.occurred && day.expected && day.workMinutes > 0).length,
        absent: days.filter((day) => day.occurred && day.missing).length,
        late: days.filter((day) => day.lateMinutes > 0).length,
        earlyOut: days.filter((day) => day.earlyMinutes > 0).length,
    };
}
