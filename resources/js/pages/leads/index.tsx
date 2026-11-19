import { bodyCell, bodyRow, DataTable, headCell, selectedRow } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { LeadFollowUpDialog, phoneLabel, reminderLabel, reminderTone, type LeadFollowUpTarget } from '@/components/hrms/lead-follow-up-dialog';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { LeadPagination, LeadSearchField } from '@/components/hrms/lead-listing';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { Contact, UserRound, Users } from 'lucide-react';
import { useEffect, useState } from 'react';

interface LeadRow {
    id: number;
    name: string;
    email: string;
    phone: string;
    state: string;
    university: string;
    graduationTerm: string;
    status: string;
    statusLabel: string;
    followUpOn: string | null;
    remarks: string;
    reminder: 'none' | 'due_today' | 'overdue' | 'upcoming';
    batchId: number | null;
    batchName: string;
    source?: string;
    unassigned: boolean;
    heldBy: string;
}

interface Filters {
    q: string;
    state: string;
    university: string;
    term: string;
    status: string;
    pool: 'all' | 'unassigned';
    source?: string;
}

interface LeadIndexProps extends HrmsPageProps {
    summary: { total: number; unassigned: number; imported: number };
    filters: Filters;
    options: { states: string[]; universities: string[]; terms: string[]; statuses: { value: string; label: string }[]; sources?: { id: number; name: string }[] };
    leads: LeadRow[];
    matching: number;
    page: number;
    pages: number;
    perPage?: number;
    selectedIds: number[] | null;
    selectable: boolean;
    canManageSources?: boolean;
    canImport: boolean;
    canAssign: boolean;
    canFollowUp: boolean;
    canReport?: boolean;
    statuses?: { value: string; label: string }[];
    notice?: string | null;
    error?: string | null;
}

export default function LeadIndexPage({
    workspace,
    summary,
    filters,
    options,
    leads,
    matching,
    page,
    pages,
    perPage = 25,
    selectedIds,
    canImport,
    canAssign,
    canFollowUp,
    canManageSources = false,
    canReport = false,
    statuses = [],
    notice,
    error,
}: LeadIndexProps) {
    const [searching, setSearching] = useState(false);
    const selectionKey = selectedIds ? selectedIds.join(',') : null;
    const [selected, setSelected] = useState<number[]>(selectedIds ?? []);
    const [editing, setEditing] = useState<LeadFollowUpTarget | null>(null);
    const pageIds = leads.map((lead) => lead.id);
    const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const statusOptions = statuses.length > 0 ? statuses : options.statuses;

    useEffect(() => {
        if (selectionKey !== null) {
            setSelected(selectionKey === '' ? [] : selectionKey.split(',').map((id) => Number(id)));
        }
    }, [selectionKey]);

    function visit(next: Partial<Filters & { page: number; perPage: number; selection?: string }>) {
        const pool = next.pool ?? filters.pool;
        const status = next.status ?? filters.status;
        const state = next.state ?? filters.state;
        const university = next.university ?? filters.university;
        const graduation = next.term ?? filters.term;
        const source = next.source ?? filters.source ?? '';
        const search = (next.q ?? filters.q).trim();
        const size = next.perPage ?? perPage;

        router.get('/leads', {
            q: search !== '' ? search : undefined,
            state: state !== '' ? state : undefined,
            university: university !== '' ? university : undefined,
            term: graduation !== '' ? graduation : undefined,
            status: status !== '' ? status : undefined,
            source: source !== '' ? source : undefined,
            pool: pool === 'unassigned' ? 'unassigned' : undefined,
            page: next.page && next.page > 1 ? next.page : undefined,
            per_page: size !== 25 ? size : undefined,
            selection: next.selection,
        }, { preserveState: true, preserveScroll: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    }

    function toggle(id: number) {
        setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
    }

    function togglePage() {
        setSelected((current) => (allOnPage ? current.filter((id) => !pageIds.includes(id)) : [...new Set([...current, ...pageIds])]));
    }

    return (
        <HrmsLayout workspace={workspace} title="Leads">
            <PageIntro
                title="Leads"
                subtitle="Company lead pool. Search, filter, and assign unassigned leads to one Team Lead."
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {canFollowUp && <Button asChild variant="outline"><Link href="/leads/follow-ups">Follow-ups</Link></Button>}
                        {canReport && <Button asChild variant="outline"><Link href="/leads/reports">Reports</Link></Button>}
                        {canManageSources && <Button asChild variant="outline"><Link href="/leads/sources">Lead sources</Link></Button>}
                        {canImport && <Button asChild><Link href="/leads/import">Import Excel</Link></Button>}
                    </div>
                )}
            />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <MetricCard tone="blue" icon={Users} label="Total leads" value={String(summary.total)} />
                <MetricCard tone="orange" icon={UserRound} label="Unassigned leads" value={String(summary.unassigned)} />
                <MetricCard tone="green" icon={Contact} label="Imported leads" value={String(summary.imported)} />
            </div>
            <SectionCard title={filters.pool === 'unassigned' ? 'Unassigned pool' : 'All company leads'}>
                <div className="mb-4 flex flex-wrap gap-2">
                    <Button type="button" variant={filters.pool === 'all' ? 'default' : 'outline'} onClick={() => visit({ pool: 'all', page: 1 })}>All leads</Button>
                    <Button type="button" variant={filters.pool === 'unassigned' ? 'default' : 'outline'} onClick={() => visit({ pool: 'unassigned', page: 1 })}>Unassigned pool</Button>
                </div>
                <div className="mb-4 grid items-end gap-2 rounded-2xl bg-slate-50 p-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_repeat(5,minmax(0,0.8fr))]">
                    <LeadSearchField applied={filters.q} onSearch={(value) => visit({ q: value, page: 1 })} />
                    <Select label="State" value={filters.state} options={options.states} onChange={(value) => visit({ state: value, page: 1 })} />
                    <Select label="University" value={filters.university} options={options.universities} onChange={(value) => visit({ university: value, page: 1 })} />
                    <Select label="Graduation term" value={filters.term} options={options.terms} onChange={(value) => visit({ term: value, page: 1 })} />
                    <label className="grid min-w-0 gap-1 text-xs text-slate-500">
                        Source
                        <select aria-label="Lead source" className="h-10 w-full min-w-0 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={filters.source ?? ''} onChange={(event) => visit({ source: event.target.value, page: 1 })}>
                            <option value="">All sources</option>
                            <option value="none">No source</option>
                            {(options.sources ?? []).map((source) => <option key={source.id} value={source.id}>{source.name}</option>)}
                        </select>
                    </label>
                    <label className="grid min-w-0 gap-1 text-xs text-slate-500">
                        Pipeline status
                        <select aria-label="Pipeline status" className="h-10 w-full min-w-0 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={filters.status} onChange={(event) => visit({ status: event.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {options.statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                        </select>
                    </label>
                </div>
                <div className="mb-3 flex flex-wrap items-center gap-3 text-sm text-slate-600">
                    <span>{selected.length} selected</span>
                    <Button type="button" variant="outline" onClick={() => visit({ selection: 'matching' })}>Select all matching</Button>
                    <Button type="button" variant="ghost" onClick={() => setSelected([])}>Clear selection</Button>
                    {canAssign && selected.length > 0 && (
                        <Button type="button" onClick={() => router.post('/leads/assignments/prepare', { ids: selected })}>Assign to Team Lead</Button>
                    )}
                </div>
                {searching && <p className="mb-3 text-sm text-slate-500">Searching…</p>}
                {leads.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">{searching ? 'Searching…' : 'No leads match these filters.'}</p>
                ) : (
                    <DataTable fit>
                        <thead>
                            <tr>
                                <th className={`${headCell} w-10`}>
                                    <input aria-label="Select all leads on this page" type="checkbox" checked={allOnPage} onChange={togglePage} />
                                </th>
                                <th className={`${headCell} w-[12%]`}>Name</th>
                                <th className={`${headCell} w-[18%]`}>Email</th>
                                <th className={`${headCell} w-[12%]`}>Phone</th>
                                <th className={`${headCell} w-[10%]`}>Source</th>
                                <th className={`${headCell} w-[6%]`}>State</th>
                                <th className={`${headCell} w-[16%]`}>University</th>
                                <th className={`${headCell} w-[10%]`}>Graduation term</th>
                                <th className={`${headCell} w-[9%]`}>Status</th>
                                <th className={`${headCell} w-[9%]`}>Call on</th>
                                <th className={`${headCell} w-[8%]`}>Pool</th>
                                {canFollowUp && <th className={`${headCell} w-[8%]`}>Action</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {leads.map((lead, index) => {
                                const isSelected = selected.includes(lead.id);

                                return (
                                    <tr key={lead.id} className={rowTone(isSelected, index)}>
                                        <td className={bodyCell}>
                                            <input aria-label={`Select ${lead.name}`} type="checkbox" checked={isSelected} onChange={() => toggle(lead.id)} />
                                        </td>
                                        <td className={`${bodyCell} max-w-0 truncate font-medium text-slate-950`} title={lead.name}>
                                            <Link href={`/leads/${lead.id}`} className="text-blue-700 hover:underline">{lead.name}</Link>
                                        </td>
                                        <td className={`${bodyCell} max-w-0 truncate text-slate-600`} title={lead.email || undefined}>{lead.email || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap font-medium tabular-nums tracking-wide text-slate-950`}>{phoneLabel(lead.phone)}</td>
                                        <td className={`${bodyCell} max-w-0 truncate`} title={lead.source || undefined}>{lead.source || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{lead.state || '—'}</td>
                                        <td className={`${bodyCell} max-w-0 truncate`} title={lead.university || undefined}>{lead.university || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{lead.graduationTerm || '—'}</td>
                                        <td className={bodyCell}><StatusBadge value={lead.statusLabel} /></td>
                                        <td className={bodyCell}>
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${reminderTone(lead.reminder)}`}>{reminderLabel(lead.reminder, lead.followUpOn)}</span>
                                        </td>
                                        <td className={bodyCell}>
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${lead.unassigned ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700'}`}>
                                                {lead.unassigned ? 'Unassigned' : 'Assigned'}
                                            </span>
                                        </td>
                                        {canFollowUp && (
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
                                        )}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </DataTable>
                )}
                <LeadPagination page={page} pages={pages} matching={matching} perPage={perPage} onPage={(next) => visit({ page: next })} onPerPage={(size) => visit({ perPage: size, page: 1 })} />
            </SectionCard>
            <LeadFollowUpDialog
                open={editing !== null}
                lead={editing}
                statuses={statusOptions}
                url={editing ? `/leads/${editing.id}/follow-ups` : ''}
                onClose={() => setEditing(null)}
            />
        </HrmsLayout>
    );
}

function rowTone(isSelected: boolean, index: number): string {
    if (isSelected) {
        return `${bodyRow} ${selectedRow}`;
    }

    return `${bodyRow} ${index % 2 === 1 ? 'bg-slate-50/80' : 'bg-white'}`;
}

function Select({ label, value, options, onChange }: { label: string; value: string; options: string[]; onChange: (value: string) => void }) {
    return (
        <label className="grid min-w-0 gap-1 text-xs text-slate-500">
            {label}
            <select aria-label={label} className="h-10 w-full min-w-0 max-w-full rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={value} onChange={(event) => onChange(event.target.value)}>
                <option value="">All</option>
                {options.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
        </label>
    );
}
