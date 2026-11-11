import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { CalendarClock, CalendarDays, Clock3 } from 'lucide-react';

interface MineLead {
    id: number;
    name: string;
    email: string;
    phone: string;
    linkedin: string;
    university: string;
    graduationTerm: string;
    experience: string;
    status: string;
    statusLabel: string;
    followUpOn: string | null;
    timing: string;
}

interface MineProps extends HrmsPageProps {
    queue: 'all' | 'overdue' | 'today' | 'upcoming';
    counts: { overdue: number; today: number; upcoming: number };
    leads: MineLead[];
    matching: number;
    page: number;
    pages: number;
    notice?: string | null;
    error?: string | null;
}

const queues = [
    ['all', 'All'],
    ['overdue', 'Overdue'],
    ['today', 'Due today'],
    ['upcoming', 'Upcoming'],
] as const;

export default function MyAssignedLeadsPage({ workspace, queue, counts, leads, matching, page, pages, notice, error }: MineProps) {
    return (
        <HrmsLayout workspace={workspace} title="My leads">
            <PageIntro title="My leads" subtitle="Leads assigned to you. Update the status, follow-up date and remarks. You cannot reassign a lead." actions={<Link href="/leads/mine/report" className="text-sm font-medium text-blue-700">My report</Link>} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <MetricCard tone="red" icon={Clock3} label="Overdue" value={String(counts.overdue)} />
                <MetricCard tone="orange" icon={CalendarDays} label="Due today" value={String(counts.today)} />
                <MetricCard tone="blue" icon={CalendarClock} label="Upcoming" value={String(counts.upcoming)} />
            </div>
            <SectionCard title="Assigned leads">
                <div className="mb-4 flex flex-wrap gap-2">
                    {queues.map(([value, label]) => (
                        <Button key={value} type="button" variant={queue === value ? 'default' : 'outline'} onClick={() => router.get('/leads/mine', value === 'all' ? {} : { queue: value })}>{label}</Button>
                    ))}
                </div>
                {leads.length === 0 ? (
                    <p className="text-sm text-slate-500">No leads in this list.</p>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                {['Name', 'Email', 'Phone', 'LinkedIn', 'University', 'Graduation term', 'Experience', 'Status', 'Follow-up'].map((heading) => (
                                    <th key={heading} className={headCell}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {leads.map((lead) => (
                                <tr key={lead.id} className={bodyRow}>
                                    <td className={`${bodyCell} font-medium`}><Link href={`/leads/mine/${lead.id}`} className="text-blue-700 hover:underline">{lead.name}</Link></td>
                                    <td className={bodyCell}>{lead.email || '—'}</td>
                                    <td className={bodyCell}>{lead.phone || '—'}</td>
                                    <td className={bodyCell}>{lead.linkedin ? <a href={lead.linkedin} className="text-blue-700 hover:underline">Profile</a> : '—'}</td>
                                    <td className={bodyCell}>{lead.university || '—'}</td>
                                    <td className={bodyCell}>{lead.graduationTerm || '—'}</td>
                                    <td className={bodyCell}>{lead.experience || '—'}</td>
                                    <td className={bodyCell}><StatusBadge value={lead.statusLabel} /></td>
                                    <td className={bodyCell}>{lead.followUpOn || '—'}{lead.timing === 'overdue' ? ' · Overdue' : lead.timing === 'today' ? ' · Due today' : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
                <p className="mt-3 text-sm text-slate-500">{matching} leads · page {page} of {pages}</p>
                <div className="mt-3 flex gap-2">
                    {page > 1 && <Button type="button" variant="outline" onClick={() => router.get('/leads/mine', { queue: queue === 'all' ? undefined : queue, page: page - 1 })}>Previous</Button>}
                    {page < pages && <Button type="button" variant="outline" onClick={() => router.get('/leads/mine', { queue: queue === 'all' ? undefined : queue, page: page + 1 })}>Next</Button>}
                </div>
            </SectionCard>
        </HrmsLayout>
    );
}
