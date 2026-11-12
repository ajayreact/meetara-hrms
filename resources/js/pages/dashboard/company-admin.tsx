import { OfferApprovals, type OfferApprovalState } from '@/components/hrms/offer-approvals';
import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { WorkplacePanels, type WorkplacePayload } from '@/components/hrms/workplace-panels';
import { HrmsLayout } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Building2, CalendarDays, CalendarRange, Globe, Receipt, Users, Wifi, type LucideIcon } from 'lucide-react';
import { Link } from '@inertiajs/react';

interface OfficeCard {
    id: number;
    name: string;
    timezone: string;
    status: string;
}

interface PayrollBoard {
    runs: number;
    review: number;
    approved: number;
    processed: number;
    outstanding: number;
}

const roles: Array<{ title: string; detail: string; tone: string }> = [
    { title: 'Head of Operations', detail: 'Can manage offices, shifts, and roles. Prepares calculated payroll, approves it, and records payments.', tone: 'border-blue-100 bg-blue-50/80' },
    { title: 'HR & Assistant Operations', detail: 'Can maintain employees, attendance, leave, and salary imports.', tone: 'border-emerald-100 bg-emerald-50/80' },
    { title: 'Company Manager', detail: 'Can be assigned as the reporting manager for employees.', tone: 'border-amber-100 bg-amber-50/80' },
    { title: 'Team Lead', detail: 'Sees the assigned team.', tone: 'border-sky-100 bg-sky-50/80' },
];

const shortcuts: Array<{ href: string; label: string; icon: LucideIcon; tone: string }> = [
    { href: '/employees', label: 'Employees', icon: Users, tone: 'bg-blue-600 text-white shadow-blue-600/25' },
    { href: '/offices', label: 'Offices', icon: Building2, tone: 'bg-emerald-600 text-white shadow-emerald-600/25' },
    { href: '/payroll/runs', label: 'Payroll runs', icon: Receipt, tone: 'bg-slate-800 text-white shadow-slate-800/25' },
    { href: '/holidays', label: 'Holidays', icon: CalendarDays, tone: 'bg-amber-500 text-white shadow-amber-500/25' },
    { href: '/leave', label: 'Leave', icon: CalendarRange, tone: 'bg-sky-600 text-white shadow-sky-600/25' },
];

function dateZone(zones: string[], officeName: string | null, role: string): string {
    const recorded = zones.filter((zone) => zone.includes('/'));

    if (recorded.length === 1) {
        return recorded[0];
    }

    return officeName ?? role;
}

export default function CompanyAdminDashboard({ workspace, timing, companyStats, payrollBoard, workplace, offerApprovals = null }: HrmsPageProps & {
    timing?: TimingState;
    offerApprovals?: OfferApprovalState | null;
    workplace?: WorkplacePayload;
    companyStats?: { headcount: number; offices: number; pendingNetworks: number; officeList: OfficeCard[] };
    payrollBoard?: PayrollBoard;
}) {
    const offices = companyStats?.officeList ?? [];
    const runs = payrollBoard?.runs ?? 0;
    const pendingNetworks = companyStats?.pendingNetworks ?? 0;
    const activeOffices = offices.filter((office) => office.status === 'Active').length;
    const zones = [...new Set(offices.map((office) => office.timezone))];
    const now = new Date();
    const hello = now.getHours() < 12 ? 'Good morning' : now.getHours() < 17 ? 'Good afternoon' : 'Good evening';
    const first = workspace.userName.trim().split(/\s+/)[0] || workspace.userName;
    const today = now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const todayStamp = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    const company = workspace.companyName ?? 'your company';
    const metrics = (
        <>
            <MetricCard tone="blue" icon={Users} label="Employees" value={String(companyStats?.headcount ?? 0)} hint="Active employees" badge={(companyStats?.headcount ?? 0) > 0 ? 'Active' : undefined} href="/employees" action="View employees" />
            <MetricCard tone="green" icon={Building2} label="Offices" value={String(companyStats?.offices ?? 0)} hint="Recorded offices" badge={offices.length > 0 && activeOffices === offices.length ? 'Active' : undefined} href="/offices" action="Manage offices" />
            <MetricCard tone="orange" icon={Wifi} label="Networks pending" value={String(pendingNetworks)} hint="No approved public network" badge={pendingNetworks > 0 ? 'Pending' : undefined} href="/offices" action="Review networks" />
            <MetricCard tone="blue" icon={Receipt} label="Payroll runs" value={String(runs)} hint={runs === 0 ? 'No runs yet' : 'Saved runs'} href="/payroll" action="Open payroll" />
        </>
    );

    return (
        <HrmsLayout workspace={workspace} title="Dashboard">
            <section className="relative mb-4 overflow-hidden rounded-2xl border border-blue-200/70 bg-[linear-gradient(135deg,#1d4ed8_0%,#2563eb_42%,#0ea5e9_100%)] px-5 py-5 text-white shadow-[0_18px_40px_-24px_rgba(37,99,235,0.8)]">
                <div className="pointer-events-none absolute -top-12 right-10 size-44 rounded-full bg-sky-300/30 blur-2xl" aria-hidden="true" />
                <div className="pointer-events-none absolute right-0 -bottom-10 size-36 rounded-full bg-white/15 blur-2xl" aria-hidden="true" />
                <div className="relative flex flex-wrap items-center justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-sm text-blue-100">{hello},</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-white">{first}</h1>
                        <p className="mt-1 text-sm text-blue-50/95">Here&apos;s what&apos;s happening at {company} today.</p>
                    </div>
                    <div className="rounded-xl border border-white/25 bg-white/15 px-4 py-2.5 backdrop-blur-sm">
                        <div className="flex items-center gap-2 text-sm font-medium text-white">
                            <CalendarDays className="size-4 text-sky-100" aria-hidden="true" />
                            <time dateTime={todayStamp}>{today}</time>
                        </div>
                        <p className="mt-0.5 pl-6 text-xs text-blue-100">{dateZone(zones, workspace.officeName, workspace.roleLabel)}</p>
                    </div>
                </div>
            </section>
            <div className={`mb-4 grid items-stretch gap-4 ${timing ? 'xl:grid-cols-12' : ''}`}>
                {timing && (
                    <div className="xl:col-span-5">
                        <TimingCard className="h-full" timing={timing} />
                    </div>
                )}
                <div className={`min-w-0 ${timing ? 'xl:col-span-7' : ''}`}>
                    {offerApprovals ? (
                        <OfferApprovals className="h-full" approvals={offerApprovals} />
                    ) : (
                        <div className={`grid h-full gap-3 sm:grid-cols-2 ${timing ? '' : 'xl:grid-cols-4'}`}>{metrics}</div>
                    )}
                </div>
            </div>
            {offerApprovals && <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{metrics}</div>}
            <div className="grid items-stretch gap-4 xl:grid-cols-12">
                <SectionCard className="flex h-full flex-col xl:col-span-7" title="Office overview" extra={<Link className="inline-flex items-center gap-1 text-sm font-medium text-blue-700 hover:underline" href="/offices">Manage offices</Link>}>
                    {offices.length === 0 ? (
                        <p className="text-sm text-slate-500">No offices are recorded yet.</p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {offices.map((office) => (
                                <li key={office.id} className="grid items-center gap-3 rounded-xl border border-blue-100 bg-gradient-to-r from-blue-50/90 to-sky-50/60 p-3 sm:grid-cols-[4.5rem_minmax(0,1fr)]">
                                    <span className="flex size-[4.5rem] items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm shadow-blue-600/30" aria-hidden="true">
                                        <Building2 className="size-7" />
                                    </span>
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="text-base font-semibold text-slate-950">{office.name}</p>
                                            <StatusBadge value={office.status} />
                                        </div>
                                        <p className="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                                            <Globe className="size-3.5 shrink-0" aria-hidden="true" />
                                            {office.timezone}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {pendingNetworks > 0 && (
                        <p className="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">
                            <Wifi className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            <span>{pendingNetworks === 1 ? '1 office has no approved public network. Clock-in there stays blocked until a network is saved.' : `${pendingNetworks} offices have no approved public network. Clock-in at those offices stays blocked until a network is saved.`}</span>
                        </p>
                    )}
                </SectionCard>
                <SectionCard className="flex h-full flex-col xl:col-span-5" title="Payroll status" extra={<Link className="inline-flex items-center gap-1 text-sm font-medium text-blue-700 hover:underline" href="/payroll">Open payroll</Link>}>
                    {runs === 0 ? (
                        <div className="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                            <span className="mb-3 flex size-12 items-center justify-center rounded-2xl bg-slate-800 text-white shadow-sm shadow-slate-800/30" aria-hidden="true">
                                <Receipt className="size-5" />
                            </span>
                            <p className="text-sm font-semibold text-slate-900">No payroll runs yet</p>
                            <p className="mx-auto mt-1 max-w-xs text-sm leading-5 text-slate-500">A run is opened for one company and month. Opening this page does not create one.</p>
                            <Button asChild className="mt-4"><Link href="/payroll/runs">Open payroll runs</Link></Button>
                        </div>
                    ) : (
                        <ul className="grid gap-2 text-sm text-slate-700">
                            <li className="flex justify-between rounded-xl bg-amber-50 px-3 py-2"><span>Awaiting review</span><span className="font-semibold text-amber-900">{payrollBoard?.review ?? 0}</span></li>
                            <li className="flex justify-between rounded-xl bg-emerald-50 px-3 py-2"><span>Approved</span><span className="font-semibold text-emerald-800">{payrollBoard?.approved ?? 0}</span></li>
                            <li className="flex justify-between rounded-xl bg-blue-50 px-3 py-2"><span>Processed</span><span className="font-semibold text-blue-800">{payrollBoard?.processed ?? 0}</span></li>
                            <li className="flex justify-between rounded-xl bg-rose-50 px-3 py-2"><span>Outstanding payments</span><span className="font-semibold text-rose-800">{payrollBoard?.outstanding ?? 0}</span></li>
                        </ul>
                    )}
                </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Roles and responsibilities">
                    <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        {roles.map((role) => (
                            <li key={role.title} className={`rounded-xl border px-3 py-2.5 ${role.tone}`}>
                                <p className="text-sm font-medium text-slate-950">{role.title}</p>
                                <p className="mt-0.5 text-xs leading-5 text-slate-600">{role.detail}</p>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Quick actions">
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                        {shortcuts.map((item) => (
                            <li key={item.href}>
                                <Link href={item.href} className="flex items-center gap-2.5 rounded-xl border border-slate-100 bg-slate-50/80 px-2.5 py-2 text-sm font-medium text-slate-800 transition-colors hover:border-blue-100 hover:bg-white focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none">
                                    <span className={`flex size-9 shrink-0 items-center justify-center rounded-xl shadow-sm ${item.tone}`}>
                                        <item.icon className="size-4" aria-hidden="true" />
                                    </span>
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </div>
            <WorkplacePanels workplace={workplace} />
        </HrmsLayout>
    );
}
