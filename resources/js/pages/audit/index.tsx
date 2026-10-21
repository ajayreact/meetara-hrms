import { DataTable, bodyCell, bodyRow, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PlatformEmpty } from '@/components/hrms/platform-mark';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface AuditEvent {
    source: string;
    action: string;
    company: string;
    actor: string;
    at: string;
    subjectEmail?: string;
}

interface AuditFilters {
    search: string;
    source: string;
    company: string;
    from: string;
    to: string;
}

const sources = ['', 'Leave', 'Payroll', 'Salary', 'Access', 'Help desk', 'Overtime', 'Payroll settings', 'Company'];

export default function AuditPage({
    workspace,
    events = [],
    page = 1,
    pages = 1,
    filters = { search: '', source: '', company: '', from: '', to: '' },
    companies = [],
}: HrmsPageProps & {
    events?: AuditEvent[];
    page?: number;
    pages?: number;
    filters?: AuditFilters;
    companies?: Array<{ id: number; name: string }>;
}) {
    const [search, setSearch] = useState(filters.search);
    const filtering = filters.search !== '' || filters.source !== '' || filters.company !== '' || filters.from !== '' || filters.to !== '';

    function visit(next: Partial<AuditFilters> & { page?: number }) {
        const current = { ...filters, search, ...next };
        router.get('/audit', {
            search: current.search || undefined,
            source: current.source || undefined,
            company: current.company || undefined,
            from: current.from || undefined,
            to: current.to || undefined,
            page: current.page && current.page > 1 ? current.page : undefined,
        }, { preserveState: true, preserveScroll: true });
    }

    return (
        <HrmsLayout workspace={workspace} title="Audit log">
            <PageIntro title="Audit log" subtitle="Saved workflow events. Salary amounts, passwords, and payment references are not shown." />
            <SectionCard divided title="Events">
                <form className="mb-4 grid gap-2 md:grid-cols-2 xl:grid-cols-6" onSubmit={(event) => { event.preventDefault(); visit({ search, page: 1 }); }}>
                    <Input aria-label="Search audit events" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Actor or action" />
                    <select aria-label="Event type" className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={filters.source} onChange={(event) => visit({ source: event.target.value, search, page: 1 })}>
                        {sources.map((source) => <option key={source || 'all'} value={source}>{source === '' ? 'All event types' : source}</option>)}
                    </select>
                    <select aria-label="Audit company" className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={filters.company} onChange={(event) => visit({ company: event.target.value, search, page: 1 })}>
                        <option value="">All companies</option>
                        {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
                    </select>
                    <Input aria-label="From date" type="date" value={filters.from} onChange={(event) => visit({ from: event.target.value, search, page: 1 })} />
                    <Input aria-label="To date" type="date" value={filters.to} onChange={(event) => visit({ to: event.target.value, search, page: 1 })} />
                    <div className="flex gap-2">
                        <Button type="submit" variant="outline">Search</Button>
                        {filtering && <Button type="button" variant="ghost" onClick={() => { setSearch(''); router.get('/audit'); }}>Clear filters</Button>}
                    </div>
                </form>
                {events.length === 0 ? <PlatformEmpty>{filtering ? 'No audit events match these filters.' : 'No audit events yet.'}</PlatformEmpty> : (
                    <DataTable>
                        <thead>
                            <tr>
                                <th className={headCell}>Event</th>
                                <th className={headCell}>Action</th>
                                <th className={headCell}>Company</th>
                                <th className={headCell}>Actor</th>
                                <th className={headCell}>Detail</th>
                                <th className={headCell}>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((entry) => (
                                <tr key={`${entry.source}-${entry.action}-${entry.at}-${entry.company}-${entry.actor}`} className={bodyRow}>
                                    <td className={`${bodyCell} font-medium`}>{entry.source}</td>
                                    <td className={bodyCell}>{entry.action}</td>
                                    <td className={bodyCell}>{entry.company}</td>
                                    <td className={bodyCell}>{entry.actor}</td>
                                    <td className={bodyCell}>{entry.subjectEmail || '—'}</td>
                                    <td className={`${bodyCell} whitespace-nowrap text-slate-500`}>{entry.at}</td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
                {pages > 1 && (
                    <div className="mt-3 flex items-center gap-2 text-sm">
                        <span className="text-slate-500">Page {page} of {pages}</span>
                        {page > 1 && <Button type="button" variant="outline" onClick={() => visit({ page: page - 1 })}>Previous</Button>}
                        {page < pages && <Button type="button" variant="outline" onClick={() => visit({ page: page + 1 })}>Next</Button>}
                    </div>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}
