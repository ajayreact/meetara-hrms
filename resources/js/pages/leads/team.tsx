import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { LeadFollowUpDialog, phoneLabel, reminderLabel, reminderTone, type LeadFollowUpTarget } from '@/components/hrms/lead-follow-up-dialog';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { Contact, UserRound, Users } from 'lucide-react';
import { useEffect, useState } from 'react';

interface TeamLeadRow {
    id: number;
    name: string;
    email: string;
    phone: string;
    state: string;
    status: string;
    statusLabel: string;
    followUpOn: string | null;
    remarks: string;
    reminder: 'none' | 'due_today' | 'overdue' | 'upcoming';
    canFollowUp: boolean;
    assigneeName: string;
}

interface EmployeeSummary {
    id: number;
    name: string;
    active: boolean;
    total: number;
    statuses: { label: string; count: number }[];
    followUp?: { overdue: number; today: number; upcoming: number };
}

interface AttentionLead {
    id: number;
    name: string;
    assigneeName: string;
    statusLabel: string;
    followUpOn: string;
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

interface DirectReport {
    id: number;
    name: string;
}

type Section = 'pending' | 'retained' | 'distributed';

interface TeamPageProps extends HrmsPageProps {
    summary: { pending: number; retained: number; distributed: number; myTotal: number; teamTotal: number };
    section: Section;
    myPipeline?: { value: string; label: string; count: number }[];
    employees: EmployeeSummary[];
    reports: DirectReport[];
    attention: { queue: 'overdue' | 'today' | 'upcoming'; overdue: number; today: number; upcoming: number; leads: AttentionLead[] };
    recentFollowUps: RecentFollowUp[];
    leads: TeamLeadRow[];
    matching: number;
    page: number;
    pages: number;
    selectedIds: number[] | null;
    statuses?: { value: string; label: string }[];
    notice?: string | null;
    error?: string | null;
}

const sectionCopy: Record<Section, string> = {
    pending: 'Not yet distributed',
    retained: 'My Leads',
    distributed: 'Distributed to Employees',
};

export default function TeamLeadPage({
    workspace,
    summary,
    section,
    myPipeline = [],
    employees,
    reports,
    attention,
    recentFollowUps,
    leads,
    matching,
    page,
    pages,
    selectedIds,
    statuses = [],
    notice,
    error,
}: TeamPageProps) {
    const selectionKey = selectedIds ? selectedIds.join(',') : null;
    const [selected, setSelected] = useState<number[]>(selectedIds ?? []);
    const [reportId, setReportId] = useState(reports[0]?.id ?? 0);
    const [editing, setEditing] = useState<LeadFollowUpTarget | null>(null);
    const pageIds = leads.map((lead) => lead.id);
    const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const canSelect = section !== 'retained';

    useEffect(() => {
        if (selectionKey !== null) {
            setSelected(selectionKey === '' ? [] : selectionKey.split(',').map((id) => Number(id)));
        }
    }, [selectionKey]);

    function visit(next: { section?: Section; page?: number; selection?: string }) {
        const nextSection = next.section ?? section;
        router.get('/my-leads', {
            section: nextSection === 'pending' ? undefined : nextSection,
            page: next.page && next.page > 1 ? next.page : undefined,
            selection: next.selection,
        }, { preserveState: true, preserveScroll: true });
    }

    function toggle(id: number) {
        setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
    }

    function togglePage() {
        setSelected((current) => (allOnPage ? current.filter((id) => !pageIds.includes(id)) : [...new Set([...current, ...pageIds])]));
    }

    return (
        <HrmsLayout workspace={workspace} title="My leads">
            <PageIntro
                title="My leads"
                subtitle="Team Total = My Leads + Distributed + Not yet distributed for your team only. Retain or distribute from the allocation table. Updating status or follow-up never changes ownership."
                actions={<Link href="/my-leads/reports" className="text-sm font-medium text-blue-700">Lead report</Link>}
            />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard tone="blue" icon={UserRound} label="My Total Leads" value={String(summary.myTotal)} hint="Retained by you" href="/my-leads?section=retained" action="Open my leads" />
                <MetricCard tone="green" icon={Users} label="Team Total Leads" value={String(summary.teamTotal)} hint="My + distributed + pending" href="/my-leads" action="Open team leads" />
                <MetricCard tone="green" icon={Contact} label="Distributed to Employees" value={String(summary.distributed)} hint="With your reports" href="/my-leads?section=distributed" action="View distributed" />
                <MetricCard tone="orange" icon={Users} label="Not yet distributed" value={String(summary.pending)} hint="Available to allocate" href="/my-leads" action="Open pool" />
            </div>

            <SectionCard title={sectionCopy[section]}>
                <div className="mb-4 flex flex-wrap gap-2">
                    {(Object.keys(sectionCopy) as Section[]).map((item) => (
                        <Button key={item} type="button" variant={section === item ? 'default' : 'outline'} onClick={() => { setSelected([]); visit({ section: item, page: 1 }); }}>{sectionCopy[item]}</Button>
                    ))}
                </div>
                {canSelect && (
                    <div className="mb-3 flex flex-wrap items-center gap-3 text-sm text-slate-600">
                        <span>{selected.length} selected</span>
                        <Button type="button" variant="outline" onClick={() => visit({ selection: 'matching' })}>Select all in this section</Button>
                        <Button type="button" variant="ghost" onClick={() => setSelected([])}>Clear selection</Button>
                        {section === 'pending' && selected.length > 0 && (
                            <Button type="button" onClick={() => router.post('/my-leads/retain/review', { ids: selected })}>Retain selected</Button>
                        )}
                        {section === 'pending' && selected.length > 0 && reports.length > 0 && (
                            <>
                                <select aria-label="Employee to receive leads" className="h-10 rounded-md border border-slate-200 bg-white px-2 text-sm" value={reportId} onChange={(event) => setReportId(Number(event.target.value))}>
                                    {reports.map((report) => <option key={report.id} value={report.id}>{report.name}</option>)}
                                </select>
                                <Button type="button" variant="outline" onClick={() => router.post('/my-leads/distribute/review', { ids: selected, employee_id: reportId })}>Distribute selected</Button>
                            </>
                        )}
                        {section === 'distributed' && selected.length > 0 && (
                            <Button type="button" onClick={() => router.post('/my-leads/recall/review', { ids: selected })}>Take back</Button>
                        )}
                        <span>{matching} in this section · page {page} of {pages}</span>
                    </div>
                )}
                {leads.length === 0 ? (
                    <p className="text-sm text-slate-500">No leads in this section.</p>
                ) : (
                    <DataTable fit>
                        <thead>
                            <tr>
                                {canSelect && (
                                    <th className={`${headCell} w-10`}>
                                        <input aria-label="Select all leads on this page" type="checkbox" checked={allOnPage} onChange={togglePage} />
                                    </th>
                                )}
                                <th className={headCell}>Name</th>
                                <th className={headCell}>Email</th>
                                <th className={headCell}>Phone</th>
                                <th className={headCell}>State</th>
                                <th className={headCell}>Status</th>
                                <th className={headCell}>Call on</th>
                                <th className={headCell}>Assignee</th>
                                <th className={headCell}>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {leads.map((lead) => (
                                <tr key={lead.id} className={bodyRow}>
                                    {canSelect && (
                                        <td className={bodyCell}>
                                            <input aria-label={`Select ${lead.name}`} type="checkbox" checked={selected.includes(lead.id)} onChange={() => toggle(lead.id)} />
                                        </td>
                                    )}
                                    <td className={`${bodyCell} max-w-0 truncate font-medium`} title={lead.name}><Link href={`/my-leads/${lead.id}`} className="text-blue-700 hover:underline">{lead.name}</Link></td>
                                    <td className={`${bodyCell} max-w-0 truncate`} title={lead.email || undefined}>{lead.email || '—'}</td>
                                    <td className={`${bodyCell} whitespace-nowrap`}>{phoneLabel(lead.phone)}</td>
                                    <td className={`${bodyCell} whitespace-nowrap`}>{lead.state || '—'}</td>
                                    <td className={bodyCell}><StatusBadge value={lead.statusLabel} /></td>
                                    <td className={bodyCell}>
                                        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${reminderTone(lead.reminder)}`}>{reminderLabel(lead.reminder, lead.followUpOn)}</span>
                                    </td>
                                    <td className={`${bodyCell} max-w-0 truncate`} title={lead.assigneeName || undefined}>{lead.assigneeName || '—'}</td>
                                    <td className={bodyCell}>
                                        {lead.canFollowUp ? (
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
                                        ) : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
                <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    {page > 1 && <Button type="button" variant="outline" onClick={() => visit({ page: page - 1 })}>Previous</Button>}
                    {page < pages && <Button type="button" variant="outline" onClick={() => visit({ page: page + 1 })}>Next</Button>}
                </div>
            </SectionCard>

            {myPipeline.some((row) => row.count > 0) && (
                <SectionCard className="mt-4" title="My pipeline">
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        {myPipeline.filter((row) => row.count > 0).map((row) => (
                            <div key={row.value} className="flex items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2">
                                <StatusBadge value={row.label} />
                                <span className="text-sm font-semibold tabular-nums text-slate-950">{row.count}</span>
                            </div>
                        ))}
                    </div>
                </SectionCard>
            )}

            <div className="mt-4">
                <SectionCard title="Follow-ups">
                    <div className="mb-3 flex flex-wrap gap-2">
                        {([
                            ['overdue', `Overdue ${attention.overdue}`],
                            ['today', `Due today ${attention.today}`],
                            ['upcoming', `Upcoming ${attention.upcoming}`],
                        ] as const).map(([queue, label]) => (
                            <Button key={queue} type="button" variant={attention.queue === queue ? 'default' : 'outline'} onClick={() => router.get('/my-leads', { attention: queue, section: section === 'pending' ? undefined : section }, { preserveScroll: true })}>{label}</Button>
                        ))}
                    </div>
                    {attention.leads.length === 0 ? (
                        <p className="text-sm text-slate-500">No follow-ups in this group.</p>
                    ) : (
                        <ul className="grid gap-2 text-sm">
                            {attention.leads.map((lead) => (
                                <li key={lead.id}>
                                    <Link href={`/my-leads/${lead.id}`} className="font-medium text-blue-700 hover:underline">{lead.name}</Link>
                                    <span className="text-slate-500"> · {lead.assigneeName || 'Retained'} · {lead.statusLabel} · {lead.followUpOn}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {recentFollowUps.length > 0 && (
                        <ul className="mt-4 grid gap-2 border-t border-slate-100 pt-3 text-sm text-slate-600">
                            {recentFollowUps.map((item) => (
                                <li key={item.id}>
                                    <Link href={`/my-leads/${item.leadId}`} className="font-medium text-blue-700 hover:underline">{item.leadName}</Link>
                                    <span> · {item.assigneeName || 'Retained'} · {item.statusLabel}{item.followUpOn ? ` · ${item.followUpOn}` : ''}</span>
                                    <span className="block text-slate-500">{item.body || 'Follow-up'}{item.actor ? ` · ${item.actor}` : ''}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <div className="mt-4">
                <SectionCard title="Distributed to Employees">
                    {employees.length === 0 ? (
                        <p className="text-sm text-slate-500">No employees report to you yet.</p>
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2">
                            {employees.map((employee) => (
                                <div key={employee.id} className="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                    <div className="font-medium text-slate-900"><Link href={`/my-leads/reports/${employee.id}`} className="text-blue-700 hover:underline">{employee.name}</Link></div>
                                    <div className="text-slate-500">{employee.active ? 'Active' : 'Inactive'} · {employee.total} {employee.total === 1 ? 'lead' : 'leads'}</div>
                                    {employee.followUp && <div className="text-slate-500">Overdue {employee.followUp.overdue} · Due today {employee.followUp.today} · Upcoming {employee.followUp.upcoming}</div>}
                                    <div className="mt-1 text-slate-600">
                                        {employee.statuses.length === 0 ? 'No distributed leads' : employee.statuses.map((status) => `${status.label} ${status.count}`).join(' · ')}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </SectionCard>
            </div>

            <LeadFollowUpDialog
                open={editing !== null}
                lead={editing}
                statuses={statuses}
                url={editing ? `/my-leads/${editing.id}/follow-ups` : ''}
                onClose={() => setEditing(null)}
            />
        </HrmsLayout>
    );
}
