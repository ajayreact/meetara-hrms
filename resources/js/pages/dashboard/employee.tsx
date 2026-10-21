import { greetingName, HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard } from '@/components/hrms/section-card';
import { TimeLogChart } from '@/components/hrms/time-log-chart';
import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { formatDuration, type MonthSummary, type TimeLogDay } from '@/lib/attendance-metrics';
import type { HrmsPageProps } from '@/types/hrms';
import { router } from '@inertiajs/react';
import { AlarmClock, CalendarDays, CircleCheck, Hourglass } from 'lucide-react';

interface History {
    month: string;
    label: string;
    months: { value: string; label: string }[];
    days: TimeLogDay[];
    summary: MonthSummary;
}

interface LeaveBoard {
    balances: { id: number; code: string; name: string; booked: string; remaining: string; recorded: boolean }[];
    upcoming: { id: number; name: string; date: string; weekday: string }[];
}

export default function EmployeeDashboard({ workspace, timing, history, leaveBoard }: HrmsPageProps & { timing?: TimingState; history?: History; leaveBoard?: LeaveBoard }) {
    const days = history?.days ?? [];
    const summary = history && days.length > 0 ? history.summary : null;
    const balances = leaveBoard?.balances ?? [];
    const upcoming = leaveBoard?.upcoming ?? [];
    const company = workspace.companyName ?? 'Your company';

    return (
        <HrmsLayout workspace={workspace} title="Dashboard">
            <PageIntro title={greetingName(workspace.userName)} subtitle={`${company} · ${workspace.designation} · ${workspace.officeName ?? 'No office assigned'}`} />
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard tone="blue" icon={CalendarDays} label="Expected Hours" value={summary ? formatDuration(summary.expectedMinutes) : '—'} />
                <MetricCard tone="green" icon={CircleCheck} label="Actual Hours" value={summary ? formatDuration(summary.actualMinutes) : '—'} />
                <MetricCard tone="orange" icon={Hourglass} label="Remaining" value={summary ? formatDuration(summary.remainingMinutes) : '—'} />
                <MetricCard tone="red" icon={AlarmClock} label="Total Early Out" value={summary ? formatDuration(summary.earlyMinutes) : '—'} />
            </div>
            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                <TimeLogChart
                    days={days}
                    monthLabel={history?.label ?? 'This month'}
                    toolbar={(
                        <label className="text-sm text-slate-500">
                            <span className="sr-only">Month</span>
                            <select className="h-9 rounded-lg border border-slate-200 bg-white px-3" value={history?.month ?? ''} onChange={(event) => router.get(route('dashboard'), { month: event.target.value }, { preserveScroll: true })} aria-label="Month">
                                {(history?.months ?? []).map((item) => (
                                    <option key={item.value} value={item.value}>{item.label}</option>
                                ))}
                            </select>
                        </label>
                    )}
                />
                {timing ? <TimingCard timing={timing} /> : null}
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <SectionCard title="My Attendance" extra={<span className="text-sm text-slate-400">{history?.label ?? 'This month'}</span>}>
                    {summary ? (
                        <dl className="divide-y divide-slate-100 text-sm">
                            <Stat label="Present" value={String(summary.present)} />
                            <Stat label="Absent" value={String(summary.absent)} />
                            <Stat label="Late In" value={String(summary.late)} />
                            <Stat label="Early Out" value={String(summary.earlyOut)} tone="text-rose-600" />
                        </dl>
                    ) : (
                        <p className="text-sm text-slate-500">No attendance data for this month.</p>
                    )}
                </SectionCard>
                <SectionCard title="My Leave" extra={<span className="text-sm text-slate-400">Balance</span>}>
                    {balances.length === 0 ? (
                        <p className="text-sm text-slate-500">No leave balances are recorded.</p>
                    ) : (
                        <ul className="space-y-3">
                            {balances.map((leave) => (
                                <li key={leave.id} className="flex items-center justify-between gap-3 text-sm">
                                    <span className="flex items-center gap-2">
                                        <span className="flex size-8 items-center justify-center rounded-lg bg-blue-50 text-xs font-semibold text-blue-700">{leave.code}</span>
                                        <span>
                                            <span className="block font-medium">{leave.name}</span>
                                            <span className="text-xs text-slate-500">{leave.recorded ? `Booked ${leave.booked}` : 'No balance recorded'}</span>
                                        </span>
                                    </span>
                                    <span className="font-semibold">{leave.recorded ? leave.remaining : '—'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
                <SectionCard title="Upcoming Holiday" extra={<span className="text-sm text-slate-400">{upcoming.length}</span>}>
                    {upcoming.length === 0 ? (
                        <p className="text-sm text-slate-500">No upcoming holidays for {workspace.officeRegion ?? 'this calendar'}.</p>
                    ) : (
                        <ul className="space-y-3">
                            {upcoming.map((holiday) => (
                                <li key={holiday.id} className="flex items-center gap-3 text-sm">
                                    <span className="w-12 rounded-lg bg-blue-50 px-1 py-1 text-center text-[11px] font-semibold text-blue-700">
                                        {holiday.date.slice(8, 10)}
                                        <span className="block text-[10px] font-medium text-blue-500">{holiday.date.slice(5, 7)}</span>
                                    </span>
                                    <span>
                                        <span className="block font-medium">{holiday.name}</span>
                                        <span className="text-xs text-slate-500">{holiday.weekday}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function Stat({ label, value, tone = 'text-slate-800' }: { label: string; value: string; tone?: string }) {
    return (
        <div className="flex items-center justify-between py-2.5">
            <dt className="text-slate-500">{label}</dt>
            <dd className={`font-semibold ${tone}`}>{value}</dd>
        </div>
    );
}
