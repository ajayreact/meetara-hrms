import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { formatDuration } from '@/lib/attendance-metrics';
import type { HrmsPageProps } from '@/types/hrms';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface HistoryDay {
    day: number;
    date: string;
    dateLabel: string;
    occurred: boolean;
    expected: boolean;
    workMinutes: number;
    breakMinutes: number;
    missing: boolean;
    lateMinutes: number;
    earlyMinutes: number;
    clockIn: string | null;
    clockOut: string | null;
    clockInIp: string | null;
    clockOutIp: string | null;
    networkStatus: 'verified' | 'not_recorded';
}

interface History {
    month: string;
    label: string;
    months: { value: string; label: string }[];
    days: HistoryDay[];
}

export default function MyAttendancePage({ workspace, timing, history, notice }: HrmsPageProps & { timing: TimingState; history: History; notice?: string | null }) {
    const days = history.days.filter((day) => day.occurred && (day.clockIn || day.missing));
    const [selectedDate, setSelectedDate] = useState(days[0]?.date ?? null);
    const selected = days.find((day) => day.date === selectedDate) ?? null;

    return (
        <HrmsLayout workspace={workspace} title="My Attendance">
            <PageIntro title="My Attendance" subtitle={`Saved attendance for ${workspace.companyName ?? 'your company'}.`} />
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                <SectionCard
                    title="History"
                    extra={(
                        <select
                            className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                            value={history.month}
                            aria-label="Month"
                            onChange={(event) => router.get(route('hrms.my-attendance'), { month: event.target.value }, { preserveState: true, preserveScroll: true })}
                        >
                            {history.months.map((month) => <option key={month.value} value={month.value}>{month.label}</option>)}
                        </select>
                    )}
                >
                    {days.length === 0 ? (
                        <p className="text-sm text-slate-500">No attendance history is available.</p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full min-w-[32rem] text-left text-sm">
                                <thead className="bg-blue-50 text-xs text-slate-600">
                                    <tr>
                                        <th className="px-3 py-3 font-medium">Date</th>
                                        <th className="px-3 py-3 font-medium">In</th>
                                        <th className="px-3 py-3 font-medium">Out</th>
                                        <th className="px-3 py-3 font-medium">Work</th>
                                        <th className="px-3 py-3 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {days.map((day) => (
                                        <tr key={day.date} className={`cursor-pointer border-t border-slate-100 hover:bg-blue-50/60 ${selectedDate === day.date ? 'bg-blue-50' : ''}`} onClick={() => setSelectedDate(day.date)}>
                                            <td className="px-3 py-2">{day.dateLabel}</td>
                                            <td className="px-3 py-2">{day.clockIn ?? '—'}</td>
                                            <td className="px-3 py-2">{day.clockOut ?? '—'}</td>
                                            <td className="px-3 py-2">{formatDuration(day.workMinutes)}</td>
                                            <td className="px-3 py-2"><StatusBadge value={day.missing ? 'Missing' : day.earlyMinutes > 0 ? 'Early out' : day.lateMinutes > 0 ? 'Late' : 'Present'} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>
                <div className="grid content-start gap-4">
                <TimingCard timing={timing} notice={notice} />
                <SectionCard title="Detail">
                    {selected ? (
                        <dl className="space-y-2 text-sm">
                            <Row label="Date" value={selected.dateLabel} />
                            <Row label="Clock in" value={selected.clockIn ?? '—'} />
                            <Row label="Clock out" value={selected.clockOut ?? '—'} />
                            <Row label="Break" value={formatDuration(selected.breakMinutes)} />
                            <Row label="Clock-in network" value={selected.clockInIp ? `${selected.clockInIp} · ${selected.networkStatus === 'verified' ? 'Verified' : 'Not recorded'}` : 'Not recorded'} />
                            <Row label="Clock-out network" value={selected.clockOutIp ?? 'Not recorded'} />
                            <Row label="Late" value={selected.lateMinutes ? `${selected.lateMinutes} min` : 'No'} />
                            <Row label="Early out" value={selected.earlyMinutes ? `${selected.earlyMinutes} min` : 'No'} />
                        </dl>
                    ) : (
                        <p className="text-sm text-slate-500">Select a day.</p>
                    )}
                </SectionCard>
                </div>
            </div>
        </HrmsLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return <div className="flex justify-between gap-3 border-b border-slate-100 py-2"><dt className="text-slate-500">{label}</dt><dd className="text-right font-medium">{value}</dd></div>;
}
