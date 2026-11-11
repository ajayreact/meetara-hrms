import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { CalendarClock, CalendarDays, Clock3 } from 'lucide-react';

interface FollowUpLead {
    id: number;
    name: string;
    assigneeName: string;
    statusLabel: string;
    followUpOn: string | null;
}

interface RecentFollowUp {
    id: number;
    leadId: number;
    leadName: string;
    assigneeName: string;
    body: string;
    statusLabel: string;
    followUpOn: string | null;
    actor: string;
    at: string;
}

interface FollowUpsProps extends HrmsPageProps {
    queue: 'overdue' | 'today' | 'upcoming';
    counts: { overdue: number; today: number; upcoming: number };
    leads: FollowUpLead[];
    recentFollowUps: RecentFollowUp[];
    notice?: string | null;
    error?: string | null;
}

export default function CompanyFollowUpsPage({ workspace, queue, counts, leads, recentFollowUps, notice, error }: FollowUpsProps) {
    return (
        <HrmsLayout workspace={workspace} title="Follow-ups">
            <PageIntro title="Follow-ups" subtitle="Company-wide follow-up dates. Open a lead to record a new activity." actions={<Link href="/leads" className="text-sm font-medium text-blue-700">Back to leads</Link>} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <MetricCard tone="red" icon={Clock3} label="Overdue" value={String(counts.overdue)} />
                <MetricCard tone="orange" icon={CalendarDays} label="Due today" value={String(counts.today)} />
                <MetricCard tone="blue" icon={CalendarClock} label="Upcoming" value={String(counts.upcoming)} />
            </div>
            <SectionCard title="Leads">
                <div className="mb-4 flex flex-wrap gap-2">
                    {([
                        ['overdue', 'Overdue'],
                        ['today', 'Due today'],
                        ['upcoming', 'Upcoming'],
                    ] as const).map(([value, label]) => (
                        <Button key={value} type="button" variant={queue === value ? 'default' : 'outline'} onClick={() => router.get('/leads/follow-ups', { queue: value })}>{label}</Button>
                    ))}
                </div>
                {leads.length === 0 ? (
                    <p className="text-sm text-slate-500">No follow-ups in this group.</p>
                ) : (
                    <ul className="grid gap-2 text-sm">
                        {leads.map((lead) => (
                            <li key={lead.id}>
                                <Link href={`/leads/${lead.id}`} className="font-medium text-blue-700 hover:underline">{lead.name}</Link>
                                <span className="text-slate-500"> · {lead.assigneeName || 'Unassigned'} · {lead.statusLabel} · {lead.followUpOn}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            <div className="mt-4">
                <SectionCard title="Recent activity">
                    {recentFollowUps.length === 0 ? (
                        <p className="text-sm text-slate-500">No follow-up activity yet.</p>
                    ) : (
                        <ul className="grid gap-3 text-sm">
                            {recentFollowUps.map((item) => (
                                <li key={item.id}>
                                    <Link href={`/leads/${item.leadId}`} className="font-medium text-blue-700 hover:underline">{item.leadName}</Link>
                                    <span className="text-slate-600"> · {item.statusLabel}{item.followUpOn ? ` · ${item.followUpOn}` : ''}</span>
                                    <span className="mt-1 block text-slate-500">{item.body || 'Follow-up'}{item.actor ? ` · ${item.actor}` : ''}{item.at ? ` · ${item.at}` : ''}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
