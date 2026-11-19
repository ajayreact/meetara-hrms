import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { LeadFollowUpDialog, phoneLabel, reminderLabel, reminderTone, type LeadFollowUpTarget } from '@/components/hrms/lead-follow-up-dialog';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { LeadPagination, LeadSearchField } from '@/components/hrms/lead-listing';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { CalendarClock, CalendarDays, Clock3 } from 'lucide-react';
import { useState } from 'react';

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
    remarks: string;
    source?: string;
    timing: string;
    reminder: 'none' | 'due_today' | 'overdue' | 'upcoming';
}

interface MineProps extends HrmsPageProps {
    queue: 'all' | 'overdue' | 'today' | 'upcoming';
    q?: string;
    counts: { overdue: number; today: number; upcoming: number };
    leads: MineLead[];
    matching: number;
    page: number;
    pages: number;
    perPage?: number;
    statuses?: { value: string; label: string }[];
    notice?: string | null;
    error?: string | null;
}

const queues = [
    ['all', 'All'],
    ['overdue', 'Overdue'],
    ['today', 'Due today'],
    ['upcoming', 'Upcoming'],
] as const;

export default function MyAssignedLeadsPage({ workspace, queue, q = '', counts, leads, matching, page, pages, perPage = 25, statuses = [], notice, error }: MineProps) {
    const [editing, setEditing] = useState<LeadFollowUpTarget | null>(null);
    const [searching, setSearching] = useState(false);

    function visit(next: { queue?: MineProps['queue']; page?: number; perPage?: number; q?: string }) {
        const nextQueue = next.queue ?? queue;
        const search = (next.q !== undefined ? next.q : q).trim();
        const size = next.perPage ?? perPage;
        router.get('/leads/mine', {
            queue: nextQueue === 'all' ? undefined : nextQueue,
            page: next.page && next.page > 1 ? next.page : undefined,
            per_page: size !== 25 ? size : undefined,
            q: search !== '' ? search : undefined,
        }, { preserveState: true, preserveScroll: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    }

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
                        <Button key={value} type="button" variant={queue === value ? 'default' : 'outline'} onClick={() => visit({ queue: value, page: 1 })}>{label}</Button>
                    ))}
                </div>
                <div className="mb-4">
                    <LeadSearchField applied={q} onSearch={(value) => visit({ q: value, page: 1 })} />
                </div>
                {searching && <p className="mb-3 text-sm text-slate-500">Searching…</p>}
                {leads.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">{searching ? 'Searching…' : 'No leads in this list.'}</p>
                ) : (
                    <DataTable fit>
                        <thead>
                            <tr>
                                <th className={headCell}>Name</th>
                                <th className={headCell}>Email</th>
                                <th className={headCell}>Phone</th>
                                <th className={headCell}>Source</th>
                                <th className={headCell}>University</th>
                                <th className={headCell}>Graduation term</th>
                                <th className={headCell}>Status</th>
                                <th className={headCell}>Call on</th>
                                <th className={headCell}>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {leads.map((lead) => (
                                <tr key={lead.id} className={bodyRow}>
                                    <td className={`${bodyCell} max-w-0 truncate font-medium`} title={lead.name}><Link href={`/leads/mine/${lead.id}`} className="text-blue-700 hover:underline">{lead.name}</Link></td>
                                    <td className={`${bodyCell} max-w-0 truncate`} title={lead.email || undefined}>{lead.email || '—'}</td>
                                    <td className={`${bodyCell} whitespace-nowrap`}>{phoneLabel(lead.phone)}</td>
                                    <td className={`${bodyCell} max-w-0 truncate`} title={lead.source || undefined}>{lead.source || '—'}</td>
                                    <td className={`${bodyCell} max-w-0 truncate`} title={lead.university || undefined}>{lead.university || '—'}</td>
                                    <td className={`${bodyCell} whitespace-nowrap`}>{lead.graduationTerm || '—'}</td>
                                    <td className={bodyCell}><StatusBadge value={lead.statusLabel} /></td>
                                    <td className={bodyCell}>
                                        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${reminderTone(lead.reminder)}`}>{reminderLabel(lead.reminder, lead.followUpOn)}</span>
                                    </td>
                                    <td className={bodyCell}>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing({
                                                id: lead.id,
                                                name: lead.name,
                                                status: lead.status,
                                                followUpOn: lead.followUpOn,
                                                remarks: lead.remarks,
                                            })}
                                        >
                                            Update
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
                <LeadPagination page={page} pages={pages} matching={matching} perPage={perPage} onPage={(next) => visit({ page: next })} onPerPage={(size) => visit({ perPage: size, page: 1 })} />
            </SectionCard>
            <LeadFollowUpDialog
                open={editing !== null}
                lead={editing}
                statuses={statuses}
                url={editing ? `/leads/mine/${editing.id}/follow-ups` : ''}
                onClose={() => setEditing(null)}
            />
        </HrmsLayout>
    );
}
