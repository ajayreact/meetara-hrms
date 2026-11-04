import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { formatDuration } from '@/lib/attendance-metrics';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';
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
    clockInDistance: number | null;
    clockOutDistance: number | null;
    verification: string | null;
    networkStatus: 'verified' | 'not_recorded';
    status?: string;
    holidayName?: string | null;
    pending?: boolean;
}

interface History {
    month: string;
    label: string;
    months: { value: string; label: string }[];
    days: HistoryDay[];
    summary?: { present: number; absent: number; late: number; holiday: number; holidayWork: number; weeklyOff: number };
}

interface Correction {
    id: number;
    date: string;
    type: string;
    reason: string;
    status: string;
    statusValue: string;
    requestedIn: string | null;
    requestedOut: string | null;
    reviewReason: string | null;
    submitted: string | null;
}

export default function MyAttendancePage({ workspace, timing, history, corrections = [], notice }: HrmsPageProps & { timing: TimingState; history: History; corrections?: Correction[]; notice?: string | null }) {
    const days = history.days.filter((day) => day.occurred && (day.clockIn || day.missing || day.status === 'Holiday' || day.status === 'Weekly Off' || day.status === 'Holiday Work'));
    const [requestOpen, setRequestOpen] = useState(false);
    const form = useForm({ work_date: '', type: 'missing_clock_in', check_in: '', check_out: '', reason: '' });
    const [selectedDate, setSelectedDate] = useState(days[0]?.date ?? null);
    const selected = days.find((day) => day.date === selectedDate) ?? null;

    return (
        <HrmsLayout workspace={workspace} title="My Attendance">
            <PageIntro title="My Attendance" subtitle={`Saved attendance for ${workspace.companyName ?? 'your company'}.`} />
            {history.summary && (
                <div className="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Count label="Present" value={history.summary.present} />
                    <Count label="Absent" value={history.summary.absent} />
                    <Count label="Late" value={history.summary.late} />
                    <Count label="Holiday" value={history.summary.holiday} />
                    <Count label="Holiday Work" value={history.summary.holidayWork} />
                    <Count label="Weekly Off" value={history.summary.weeklyOff} />
                </div>
            )}
            <div className="grid items-start gap-4 xl:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]">
                <TimingCard timing={timing} notice={notice} />
                <div className="grid content-start gap-4">
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
                                            <td className="px-3 py-2">{day.dateLabel}{day.holidayName ? <span className="block text-xs text-sky-700">{day.holidayName}</span> : null}{day.pending ? <span className="block text-xs text-amber-700">Correction pending</span> : null}</td>
                                            <td className="px-3 py-2">{day.clockIn ?? '—'}</td>
                                            <td className="px-3 py-2">{day.clockOut ?? '—'}</td>
                                            <td className="px-3 py-2">{formatDuration(day.workMinutes)}</td>
                                            <td className="px-3 py-2"><StatusBadge value={day.status ?? (day.missing ? 'Missing' : day.earlyMinutes > 0 ? 'Early out' : day.lateMinutes > 0 ? 'Late' : 'Present')} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>
                <SectionCard title="Attendance correction request" extra={<button type="button" className="text-sm font-medium text-blue-700" onClick={() => setRequestOpen((open) => !open)}>{requestOpen ? 'Hide' : 'Request a correction'}</button>}>
                    <p className="text-sm text-slate-600">Ask HR to correct a missed or incorrect clock time. Submitting a request does not change the attendance record.</p>
                    {requestOpen && (
                        <form className="mt-3 grid gap-3" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.attendance.corrections.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setRequestOpen(false); } }); }}>
                            <label className="grid gap-1 text-sm">Date<input type="date" className="h-10 rounded-md border px-3" value={form.data.work_date} onChange={(event) => form.setData('work_date', event.target.value)} /></label>
                            <label className="grid gap-1 text-sm">Correction type
                                <select className="h-10 rounded-md border px-3" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                                    <option value="missing_clock_in">Missing Clock-in</option>
                                    <option value="missing_clock_out">Missing Clock-out</option>
                                    <option value="incorrect_clock_in">Incorrect Clock-in</option>
                                    <option value="incorrect_clock_out">Incorrect Clock-out</option>
                                    <option value="other">Other Attendance Correction</option>
                                </select>
                            </label>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="grid gap-1 text-sm">Requested clock-in<input type="time" className="h-10 rounded-md border px-3" value={form.data.check_in} onChange={(event) => form.setData('check_in', event.target.value)} /></label>
                                <label className="grid gap-1 text-sm">Requested clock-out<input type="time" className="h-10 rounded-md border px-3" value={form.data.check_out} onChange={(event) => form.setData('check_out', event.target.value)} /></label>
                            </div>
                            <label className="grid gap-1 text-sm">Reason<textarea className="min-h-20 rounded-md border px-3 py-2" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} /></label>
                            {Object.values(form.errors)[0] && <p className="text-sm text-rose-600">{Object.values(form.errors)[0]}</p>}
                            <button type="submit" className="h-10 rounded-md bg-blue-600 px-4 text-sm font-medium text-white" disabled={form.processing}>Submit request</button>
                        </form>
                    )}
                    <div className="mt-4 grid gap-2">
                        {corrections.length === 0 ? <p className="text-sm text-slate-500">No correction requests yet.</p> : corrections.map((item) => (
                            <div key={item.id} className="rounded-xl border border-slate-100 px-3 py-2 text-sm">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">{item.date} · {item.type}</span>
                                    <StatusBadge value={item.status} />
                                </div>
                                <p className="mt-1 text-slate-600">{item.reason}</p>
                                <p className="text-xs text-slate-500">Requested {item.requestedIn ?? '—'} to {item.requestedOut ?? '—'}{item.submitted ? ` · ${item.submitted}` : ''}</p>
                                {item.reviewReason && <p className="text-xs text-slate-500">HR: {item.reviewReason}</p>}
                                {item.statusValue === 'pending' && <button type="button" className="mt-2 text-xs font-medium text-rose-700" onClick={() => router.post(route('hrms.attendance.corrections.cancel', item.id), {}, { preserveScroll: true })}>Cancel request</button>}
                            </div>
                        ))}
                    </div>
                </SectionCard>
                <SectionCard title="Detail">
                    {selected ? (
                        <dl className="space-y-2 text-sm">
                            <Row label="Date" value={selected.dateLabel} />
                            <Row label="Clock in" value={selected.clockIn ?? '—'} />
                            <Row label="Clock out" value={selected.clockOut ?? '—'} />
                            <Row label="Break" value={formatDuration(selected.breakMinutes)} />
                            <Row label="Clock-in network" value={selected.clockInIp ? `${selected.clockInIp} · ${selected.networkStatus === 'verified' ? 'Verified' : 'Not recorded'}` : 'Not recorded'} />
                            <Row label="Clock-out network" value={selected.clockOutIp ?? 'Not recorded'} />
                            <Row label="Verification" value={selected.verification ?? 'Not recorded'} />
                            {selected.clockInDistance !== null && <Row label="Clock-in distance" value={`${selected.clockInDistance} m`} />}
                            {selected.clockOutDistance !== null && <Row label="Clock-out distance" value={`${selected.clockOutDistance} m`} />}
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

function Count({ label, value }: { label: string; value: number }) {
    return <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm"><p className="text-xs text-slate-500">{label}</p><p className="text-xl font-semibold">{value}</p></div>;
}

function Row({ label, value }: { label: string; value: string }) {
    return <div className="flex justify-between gap-3 border-b border-slate-100 py-2"><dt className="text-slate-500">{label}</dt><dd className="text-right font-medium">{value}</dd></div>;
}
