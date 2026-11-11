import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    batchId: number | null;
    batchName: string;
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
}

interface LeadIndexProps extends HrmsPageProps {
    summary: { total: number; unassigned: number; imported: number };
    filters: Filters;
    options: { states: string[]; universities: string[]; terms: string[]; statuses: { value: string; label: string }[] };
    leads: LeadRow[];
    matching: number;
    page: number;
    pages: number;
    selectedIds: number[] | null;
    selectable: boolean;
    canImport: boolean;
    canAssign: boolean;
    canFollowUp: boolean;
    canReport?: boolean;
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
    selectedIds,
    canImport,
    canAssign,
    canFollowUp,
    canReport = false,
    notice,
    error,
}: LeadIndexProps) {
    const [term, setTerm] = useState(filters.q);
    const selectionKey = selectedIds ? selectedIds.join(',') : null;
    const [selected, setSelected] = useState<number[]>(selectedIds ?? []);
    const pageIds = leads.map((lead) => lead.id);
    const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));

    useEffect(() => {
        if (selectionKey !== null) {
            setSelected(selectionKey === '' ? [] : selectionKey.split(',').map((id) => Number(id)));
        }
    }, [selectionKey]);

    function visit(next: Partial<Filters & { page: number; selection?: string }>) {
        const pool = next.pool ?? filters.pool;
        const status = next.status ?? filters.status;
        const state = next.state ?? filters.state;
        const university = next.university ?? filters.university;
        const graduation = next.term ?? filters.term;
        const search = next.q ?? filters.q;

        router.get('/leads', {
            q: search !== '' ? search : undefined,
            state: state !== '' ? state : undefined,
            university: university !== '' ? university : undefined,
            term: graduation !== '' ? graduation : undefined,
            status: status !== '' ? status : undefined,
            pool: pool === 'unassigned' ? 'unassigned' : undefined,
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
        <HrmsLayout workspace={workspace} title="Leads">
            <PageIntro
                title="Leads"
                subtitle="Company lead pool. Search, filter, and assign unassigned leads to one Team Lead."
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {canFollowUp && <Button asChild variant="outline"><Link href="/leads/follow-ups">Follow-ups</Link></Button>}
                        {canReport && <Button asChild variant="outline"><Link href="/leads/reports">Reports</Link></Button>}
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
                <form className="mb-4 grid gap-2 rounded-2xl bg-slate-50 p-3 lg:grid-cols-6" onSubmit={(event) => { event.preventDefault(); visit({ q: term, page: 1 }); }}>
                    <Input aria-label="Search leads" value={term} onChange={(event) => setTerm(event.target.value)} placeholder="Name, email, or phone" className="bg-white lg:col-span-2" />
                    <Select label="State" value={filters.state} options={options.states} onChange={(value) => visit({ state: value, page: 1 })} />
                    <Select label="University" value={filters.university} options={options.universities} onChange={(value) => visit({ university: value, page: 1 })} />
                    <Select label="Graduation term" value={filters.term} options={options.terms} onChange={(value) => visit({ term: value, page: 1 })} />
                    <label className="grid gap-1 text-xs text-slate-500">
                        Pipeline status
                        <select aria-label="Pipeline status" className="h-10 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={filters.status} onChange={(event) => visit({ status: event.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {options.statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                        </select>
                    </label>
                    <div className="flex items-end gap-2 lg:col-span-6">
                        <Button type="submit" variant="outline">Search</Button>
                        <Button type="button" variant="ghost" onClick={() => { setTerm(''); visit({ q: '', state: '', university: '', term: '', status: '', page: 1 }); }}>Clear</Button>
                    </div>
                </form>
                <div className="mb-3 flex flex-wrap items-center gap-3 text-sm text-slate-600">
                    <span>{selected.length} selected</span>
                    <Button type="button" variant="outline" onClick={() => visit({ selection: 'matching' })}>Select all matching</Button>
                    <Button type="button" variant="ghost" onClick={() => setSelected([])}>Clear selection</Button>
                    {canAssign && selected.length > 0 && (
                        <Button type="button" onClick={() => router.post('/leads/assignments/prepare', { ids: selected })}>Assign to Team Lead</Button>
                    )}
                    <span>{matching} matching · page {page} of {pages}</span>
                </div>
                {leads.length === 0 ? (
                    <p className="text-sm text-slate-500">No leads match these filters.</p>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <th className={headCell}>
                                    <input aria-label="Select all leads on this page" type="checkbox" checked={allOnPage} onChange={togglePage} />
                                </th>
                                {['Name', 'Email', 'Phone', 'State', 'University', 'Graduation term', 'Status', 'Import batch', 'Pool', 'Held by'].map((heading) => (
                                    <th key={heading} className={headCell}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {leads.map((lead) => (
                                <tr key={lead.id} className={bodyRow}>
                                    <td className={bodyCell}>
                                        <input aria-label={`Select ${lead.name}`} type="checkbox" checked={selected.includes(lead.id)} onChange={() => toggle(lead.id)} />
                                    </td>
                                    <td className={`${bodyCell} font-medium`}><Link href={`/leads/${lead.id}`} className="text-blue-700 hover:underline">{lead.name}</Link></td>
                                    <td className={bodyCell}>{lead.email || '—'}</td>
                                    <td className={bodyCell}>{lead.phone || '—'}</td>
                                    <td className={bodyCell}>{lead.state || '—'}</td>
                                    <td className={bodyCell}>{lead.university || '—'}</td>
                                    <td className={bodyCell}>{lead.graduationTerm || '—'}</td>
                                    <td className={bodyCell}><StatusBadge value={lead.statusLabel} /></td>
                                    <td className={bodyCell}>{lead.batchName || '—'}</td>
                                    <td className={bodyCell}>{lead.unassigned ? 'Unassigned' : 'Assigned'}</td>
                                    <td className={bodyCell}>{lead.heldBy}</td>
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
        </HrmsLayout>
    );
}

function Select({ label, value, options, onChange }: { label: string; value: string; options: string[]; onChange: (value: string) => void }) {
    return (
        <label className="grid gap-1 text-xs text-slate-500">
            {label}
            <select aria-label={label} className="h-10 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={value} onChange={(event) => onChange(event.target.value)}>
                <option value="">All</option>
                {options.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
        </label>
    );
}
