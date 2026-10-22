export const INDIA_TIME_ZONE = 'Asia/Kolkata';

export const US_TIME_ZONES = [
    { label: 'New York', timeZone: 'America/New_York' },
    { label: 'Detroit, Michigan', timeZone: 'America/Detroit' },
    { label: 'Dallas, Texas', timeZone: 'America/Chicago' },
] as const;

export interface ZoneTime {
    label: string;
    clock: string;
    abbreviation: string;
    date: string | null;
}

export function indiaToday(now = new Date()): string {
    return formatDate(now, INDIA_TIME_ZONE);
}

export function nextDate(date: string): string {
    return formatDate(new Date(zonedInstant(date, '12:00', INDIA_TIME_ZONE).getTime() + 24 * 60 * 60 * 1000), INDIA_TIME_ZONE);
}

export function zoneTimes(indiaDate: string, time: string): ZoneTime[] {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(indiaDate) || !/^\d{2}:\d{2}$/.test(time)) {
        return [];
    }

    const instant = zonedInstant(indiaDate, time, INDIA_TIME_ZONE);

    return US_TIME_ZONES.map((zone) => {
        const parts = partsIn(instant, zone.timeZone);
        const localDate = formatDate(instant, zone.timeZone);

        return {
            label: zone.label,
            clock: `${parts.hour}:${parts.minute} ${parts.dayPeriod}`,
            abbreviation: parts.timeZoneName,
            date: localDate === indiaDate ? null : localDateLabel(parts),
        };
    });
}

function zonedInstant(date: string, time: string, timeZone: string): Date {
    const [year, month, day] = date.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);
    let utc = Date.UTC(year, month - 1, day, hour, minute);
    const desired = utc;
    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });

    for (let attempt = 0; attempt < 3; attempt += 1) {
        const parts = partsIn(new Date(utc), timeZone, formatter);
        const shown = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour) % 24, Number(parts.minute));
        utc += desired - shown;
    }

    return new Date(utc);
}

function partsIn(instant: Date, timeZone: string, formatter?: Intl.DateTimeFormat): Record<string, string> {
    const format = formatter ?? new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZoneName: 'short',
    });

    return Object.fromEntries(format.formatToParts(instant).filter((part) => part.type !== 'literal').map((part) => [part.type, part.value]));
}

function formatDate(instant: Date, timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(instant);
}

function localDateLabel(parts: Record<string, string>): string {
    return `${parts.month} ${parts.day}, ${parts.year}`;
}
