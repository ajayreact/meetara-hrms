import { greetingName, HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PlatformEmpty } from '@/components/hrms/platform-mark';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link } from '@inertiajs/react';
import { Building2, ChevronRight, CircleMinus, LifeBuoy, ScrollText, Settings, ShieldCheck, Users, type LucideIcon } from 'lucide-react';

interface AuditEvent {
    source: string;
    action: string;
    company: string;
    actor?: string;
    at: string;
}

interface Platform {
    companies: number;
    active: number;
    inactive: number;
    employees: number;
    events: AuditEvent[];
}

const shortcuts = [
    { href: '/companies', label: 'Company directory', detail: 'Status, headcount, and the Company Admin', icon: Building2, tile: 'bg-blue-50 text-blue-700 hover:bg-blue-100' },
    { href: '/audit', label: 'Event history', detail: 'Workflow events without pay amounts', icon: ScrollText, tile: 'bg-teal-50 text-teal-700 hover:bg-teal-100' },
    { href: '/company/settings', label: 'Administration notes', detail: 'What can and cannot be changed here', icon: Settings, tile: 'bg-amber-50 text-amber-700 hover:bg-amber-100' },
    { href: '/help-desk', label: 'Ticket desk', detail: 'Requests stay inside each company', icon: LifeBuoy, tile: 'bg-rose-50 text-rose-700 hover:bg-rose-100' },
];

const fills = {
    blue: 'bg-gradient-to-br from-blue-500 to-blue-700',
    green: 'bg-gradient-to-br from-emerald-500 to-emerald-700',
    amber: 'bg-gradient-to-br from-amber-400 to-amber-600',
    violet: 'bg-gradient-to-br from-violet-500 to-violet-700',
};

const sourceTones: Record<string, string> = {
    Leave: 'bg-sky-100 text-sky-800',
    Payroll: 'bg-emerald-100 text-emerald-800',
    Salary: 'bg-teal-100 text-teal-800',
    Access: 'bg-violet-100 text-violet-800',
    'Help desk': 'bg-rose-100 text-rose-800',
    Overtime: 'bg-blue-100 text-blue-800',
    'Payroll settings': 'bg-amber-100 text-amber-800',
    Company: 'bg-indigo-100 text-indigo-800',
};

export default function SuperAdminDashboard({ workspace, platform }: HrmsPageProps & { platform?: Platform }) {
    const board = platform ?? { companies: 0, active: 0, inactive: 0, employees: 0, events: [] };

    return (
        <HrmsLayout workspace={workspace} title="Platform">
            <PageIntro title={greetingName(workspace.userName)} subtitle="Counts come from saved companies and workflow events. Billing is not connected." />
            <div className="grid w-full grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <PlatformMetric tone="blue" icon={Building2} label="Companies" value={String(board.companies)} />
                <PlatformMetric tone="green" icon={ShieldCheck} label="Active" value={String(board.active)} />
                <PlatformMetric tone="amber" icon={CircleMinus} label="Not active" value={String(board.inactive)} />
                <PlatformMetric tone="violet" icon={Users} label="Employees" value={String(board.employees)} />
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(280px,0.8fr)]">
                <SectionCard divided title="Recent audit" extra={<Link href="/audit" className="rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">View</Link>}>
                    {board.events.length === 0 ? (
                        <PlatformEmpty>No audit events yet.</PlatformEmpty>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {board.events.slice(0, 8).map((entry) => (
                                <li key={`${entry.source}-${entry.at}-${entry.company}`} className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-3">
                                    <div className="min-w-0">
                                        <p className="flex flex-wrap items-center gap-2">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${sourceTones[entry.source] ?? 'bg-slate-200 text-slate-700'}`}>{entry.source}</span>
                                            <span className="font-medium text-slate-950">{entry.action}</span>
                                        </p>
                                        <p className="mt-1 text-slate-600">{entry.company}{entry.actor ? ` · ${entry.actor}` : ''}</p>
                                    </div>
                                    <time className="text-xs whitespace-nowrap text-slate-500">{entry.at}</time>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
                <SectionCard divided title="Platform areas">
                    <ul className="grid grid-cols-1 gap-2">
                        {shortcuts.map((item) => {
                            const Icon = item.icon;

                            return (
                                <li key={item.href}>
                                    <Link href={item.href} className={`flex h-full items-center gap-3 rounded-xl px-3 py-3 ${item.tile}`}>
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/80">
                                            <Icon className="platform-mark size-[18px]" aria-hidden="true" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-medium text-slate-950">{item.label}</span>
                                            <span className="block text-xs text-slate-500">{item.detail}</span>
                                        </span>
                                        <ChevronRight className="size-4 shrink-0 text-slate-400" aria-hidden="true" />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function PlatformMetric({ label, value, icon: Icon, tone }: { label: string; value: string; icon: LucideIcon; tone: keyof typeof fills }) {
    return (
        <article className={`overflow-hidden rounded-xl p-5 text-white shadow-sm ${fills[tone]}`}>
            <div className="flex items-center justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-xs font-medium tracking-wide text-white/80 uppercase">{label}</p>
                    <p className="mt-2 text-[28px] leading-none font-semibold tracking-tight">{value}</p>
                </div>
                <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/20">
                    <Icon className="platform-mark size-5" aria-hidden="true" />
                </span>
            </div>
        </article>
    );
}
