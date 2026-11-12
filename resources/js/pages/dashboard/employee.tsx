import { WorkplacePanels, type WorkplacePayload } from '@/components/hrms/workplace-panels';
import { greetingName, HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard } from '@/components/hrms/section-card';
import { TimeLogChart } from '@/components/hrms/time-log-chart';
import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { formatDuration, type MonthSummary, type TimeLogDay } from '@/lib/attendance-metrics';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
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

interface EssAction {
    label: string;
    href: string;
}

interface Ess {
    welcome: { name?: string | null; code?: string | null; designation?: string | null; department?: string | null; office?: string | null; status?: string | null };
    today: { status: string; checkIn: string | null; checkOut: string | null };
    attendance: MonthSummary | null;
    leaveBalances: { id: number; name: string; remaining: string; recorded: boolean }[];
    pendingLeave: number;
    payslip: { id: number; period: string } | null;
    holidays: { id: number; name: string; date: string }[];
    unreadAnnouncements: number;
    actions: EssAction[];
}

export default function EmployeeDashboard({ workspace, timing, history, leaveBoard, workplace, ess = null, notice }: HrmsPageProps & { timing?: TimingState; history?: History; leaveBoard?: LeaveBoard; workplace?: WorkplacePayload; ess?: Ess | null; notice?: string | null }) {
    const days = history?.days ?? [];
    const summary = history && days.length > 0 ? history.summary : null;
    const balances = leaveBoard?.balances ?? [];
    const upcoming = leaveBoard?.upcoming ?? [];
    const company = workspace.companyName ?? 'Your company';

    return (
        <HrmsLayout workspace={workspace} title="Dashboard">
            {notice && <p className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{notice}</p>}
            <PageIntro title={greetingName(workspace.userName)} subtitle={`${company} · ${workspace.designation} · ${workspace.officeName ?? 'No office assigned'}`} />
            <div className={`mb-4 grid items-stretch gap-4 ${timing ? 'xl:grid-cols-12' : ''}`}>
                {timing && (
                    <div className="xl:col-span-5">
                        <TimingCard className="h-full" timing={timing} />
                    </div>
                )}
                <div className={`min-w-0 ${timing ? 'xl:col-span-7' : ''}`}>
                    {ess ? (
                        <EmployeeWelcome className="mb-0 h-full" ess={ess} />
                    ) : (
                        <div className={`grid h-full gap-3 sm:grid-cols-2 ${timing ? '' : 'xl:grid-cols-4'}`}>
                            <MetricCard tone="blue" icon={CalendarDays} label="Expected Hours" value={summary ? formatDuration(summary.expectedMinutes) : '—'} />
                            <MetricCard tone="green" icon={CircleCheck} label="Actual Hours" value={summary ? formatDuration(summary.actualMinutes) : '—'} />
                            <MetricCard tone="orange" icon={Hourglass} label="Remaining" value={summary ? formatDuration(summary.remainingMinutes) : '—'} />
                            <MetricCard tone="red" icon={AlarmClock} label="Total Early Out" value={summary ? formatDuration(summary.earlyMinutes) : '—'} />
                        </div>
                    )}
                </div>
            </div>
            {ess && (
                <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard tone="blue" icon={CalendarDays} label="Expected Hours" value={summary ? formatDuration(summary.expectedMinutes) : '—'} />
                    <MetricCard tone="green" icon={CircleCheck} label="Actual Hours" value={summary ? formatDuration(summary.actualMinutes) : '—'} />
                    <MetricCard tone="orange" icon={Hourglass} label="Remaining" value={summary ? formatDuration(summary.remainingMinutes) : '—'} />
                    <MetricCard tone="red" icon={AlarmClock} label="Total Early Out" value={summary ? formatDuration(summary.earlyMinutes) : '—'} />
                </div>
            )}
            <div className="mt-4">
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
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
                <SectionCard title="My Attendance" extra={<Link href={route('hrms.my-attendance')} className="text-sm font-medium text-blue-700">Request correction</Link>}>
                    {summary ? (
                        <dl className="divide-y divide-slate-100 text-sm">
                            <Stat label="Present" value={String(summary.present)} />
                            <Stat label="Absent" value={String(summary.absent)} />
                            <Stat label="Late In" value={String(summary.late)} />
                            <Stat label="Early Out" value={String(summary.earlyOut)} tone="text-rose-600" />
                            <Stat label="Holiday" value={String(summary.holiday ?? 0)} />
                            <Stat label="Holiday Work" value={String(summary.holidayWork ?? 0)} />
                            <Stat label="Weekly Off" value={String(summary.weeklyOff ?? 0)} />
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
                <SectionCard title="My Payslips" extra={<Link className="text-sm text-blue-700" href="/payslips">Open</Link>}>
                    <p className="text-sm text-slate-500">Processed payslips for your employee record can be downloaded as PDFs. Other employees’ payslips stay unavailable.</p>
                    <Link className="mt-3 inline-flex text-sm font-medium text-blue-700" href="/payslips">View my payslips</Link>
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
            <WorkplacePanels workplace={workplace} />
        </HrmsLayout>
    );
}

function EmployeeWelcome({ ess, className = 'mb-4' }: { ess: Ess; className?: string }) {
    const welcome = ess.welcome;

    return (
        <section className={`grid gap-4 rounded-2xl border border-blue-100 bg-gradient-to-br from-white to-sky-50/50 p-4 shadow-sm sm:p-5 ${className}`.trim()}>
            <div>
                <p className="text-sm text-slate-500">Welcome</p>
                <h2 className="mt-1 text-xl font-semibold text-slate-950">{welcome.name || 'Employee'}</h2>
                <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    <Fact label="Employee ID" value={welcome.code || 'Not linked'} />
                    <Fact label="Designation" value={welcome.designation || 'Not assigned'} />
                    <Fact label="Department" value={welcome.department || 'Not assigned'} />
                    <Fact label="Office" value={welcome.office || 'Not assigned'} />
                    <Fact label="Status" value={welcome.status || 'Not linked'} />
                </dl>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Summary label="Today" value={ess.today.status} detail={[ess.today.checkIn ? `In ${ess.today.checkIn}` : 'No check-in', ess.today.checkOut ? `Out ${ess.today.checkOut}` : 'No check-out'].join(' · ')} />
                <Summary label="This month" value={ess.attendance ? `${ess.attendance.present} present` : 'No record'} detail={ess.attendance ? `${ess.attendance.absent} absent · ${ess.attendance.late} late` : 'Attendance has not been recorded.'} />
                <Summary label="Leave" value={`${ess.pendingLeave} pending`} detail={ess.leaveBalances[0] ? `${ess.leaveBalances[0].name}: ${ess.leaveBalances[0].recorded ? ess.leaveBalances[0].remaining : 'not recorded'}` : 'No leave balance is recorded.'} />
                <Summary label="Latest payslip" value={ess.payslip ? ess.payslip.period : 'None'} detail={ess.unreadAnnouncements > 0 ? `${ess.unreadAnnouncements} unread announcement${ess.unreadAnnouncements === 1 ? '' : 's'}` : 'No unread announcements'} />
            </div>
            {ess.holidays.length > 0 && (
                <p className="text-sm text-slate-600">Next holiday: {ess.holidays[0].name} on {ess.holidays[0].date}.</p>
            )}
            <div className="flex flex-wrap gap-2">
                {ess.actions.map((action) => (
                    <Link key={action.label} href={action.href} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:border-blue-200 hover:text-blue-700">{action.label}</Link>
                ))}
            </div>
        </section>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value}</dd>
        </div>
    );
}

function Summary({ label, value, detail }: { label: string; value: string; detail: string }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <p className="text-xs text-slate-500">{label}</p>
            <p className="mt-1 text-lg font-semibold text-slate-950">{value}</p>
            <p className="mt-1 text-xs text-slate-500">{detail}</p>
        </div>
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
