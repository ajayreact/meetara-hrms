import type { TimeLogDay } from '@/lib/attendance-metrics';
import { formatDuration } from '@/lib/attendance-metrics';
import { useState, type ReactNode } from 'react';

export function TimeLogChart({ days, monthLabel, toolbar }: { days: TimeLogDay[]; monthLabel: string; toolbar?: ReactNode }) {
    const [hover, setHover] = useState<number | null>(null);
    const hasData = days.some((day) => day.workMinutes > 0 || day.missing);
    const maxMinutes = 10 * 60;
    const chartHeight = 220;
    const active = hover === null ? null : days.find((day) => day.day === hover);

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-base font-semibold text-slate-800">My Timelogs – {monthLabel}</h2>
                <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    {toolbar}
                    <Legend color="bg-emerald-500" label="Work" />
                    <Legend color="bg-amber-400" label="Break" />
                    <Legend color="bg-rose-500" label="Missing" />
                </div>
            </div>
            {!hasData ? (
                <div className="flex h-56 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 text-sm text-slate-500">
                    No attendance data for this month.
                </div>
            ) : (
                <div className="relative">
                    <div className="flex gap-2">
                        <div className="flex h-[220px] flex-col justify-between pb-6 text-[11px] text-slate-400">
                            {[10, 8, 6, 4, 2, 0].map((hour) => (
                                <span key={hour}>{hour}</span>
                            ))}
                        </div>
                        <div className="relative min-w-0 flex-1 overflow-x-auto">
                            <div className="flex h-[248px] min-w-[640px] items-end gap-1 px-1">
                                {days.map((day) => {
                                    const workHeight = (day.workMinutes / maxMinutes) * chartHeight;
                                    const breakHeight = (day.breakMinutes / maxMinutes) * chartHeight;
                                    const missingHeight = day.missing ? 16 : 0;

                                    return (
                                        <button
                                            key={day.day}
                                            type="button"
                                            className="flex h-full flex-1 flex-col items-center justify-end"
                                            onMouseEnter={() => setHover(day.day)}
                                            onFocus={() => setHover(day.day)}
                                            onMouseLeave={() => setHover(null)}
                                            onBlur={() => setHover(null)}
                                            aria-label={`${day.dateLabel}, ${day.missing ? 'missing attendance' : formatDuration(day.workMinutes)}`}
                                        >
                                            <span className="flex h-[220px] w-full max-w-5 flex-col justify-end border-b border-slate-200">
                                                {day.breakMinutes > 0 && <span className="bg-amber-400" style={{ height: Math.max(breakHeight, 3) }} />}
                                                {day.workMinutes > 0 && <span className="rounded-t-sm bg-emerald-500" style={{ height: workHeight }} />}
                                                {missingHeight > 0 && <span className="rounded-sm bg-rose-500" style={{ height: missingHeight }} />}
                                            </span>
                                            <span className="mt-1 text-[11px] text-slate-400">{day.day}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                    {active && (
                        <div className="pointer-events-none absolute top-2 right-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-md">
                            <p className="font-medium text-slate-800">{active.dateLabel}</p>
                            <p className="text-slate-500">{active.missing ? 'Missing attendance' : `Work ${formatDuration(active.workMinutes)} · Break ${formatDuration(active.breakMinutes)}`}</p>
                        </div>
                    )}
                    <table className="sr-only">
                        <caption>Daily work and break minutes for {monthLabel}</caption>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Work</th>
                                <th>Break</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {days.map((day) => (
                                <tr key={day.day}>
                                    <td>{day.dateLabel}</td>
                                    <td>{formatDuration(day.workMinutes)}</td>
                                    <td>{formatDuration(day.breakMinutes)}</td>
                                    <td>{day.missing ? 'Missing' : day.workMinutes > 0 ? 'Worked' : 'No record'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function Legend({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={`size-2.5 rounded-full ${color}`} />
            {label}
        </span>
    );
}
