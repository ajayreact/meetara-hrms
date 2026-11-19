import { FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';

interface Expiry {
    label: string;
    tone: string;
    date: string | null;
    detail: string;
}

interface Row {
    id: number;
    reference: string;
    name: string;
    email: string;
    jobTitle: string;
    completion: number;
    ead: Expiry;
    passport: Expiry;
}

interface Records {
    rows: Row[];
    page: number;
    pages: number;
    total: number;
}

export default function CandidateRecordsPage({ workspace, records, filters, options, canExport }: HrmsPageProps & {
    records: Records;
    filters: { search: string; expiry: string[] };
    options: { expiryFilters: Record<string, string> };
    canExport: boolean;
}) {
    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get('/candidate-records', {
            search: String(data.get('search') ?? ''),
            expiry: data.getAll('expiry'),
        }, { preserveState: true });
    };

    const exportQuery = new URLSearchParams();
    if (filters.search) {
        exportQuery.set('search', filters.search);
    }
    filters.expiry.forEach((value) => exportQuery.append('expiry[]', value));

    return (
        <HrmsLayout workspace={workspace} title="Candidate Management">
            <PageIntro title="Candidate Management" subtitle="HR-managed candidate records, documents, payments, and compliance. This list is separate from public candidate forms." />
            <SectionCard
                title="Candidates"
                extra={(
                    <div className="flex gap-2">
                        {canExport && <Button variant="outline" asChild><a href={`/candidate-records/export?${exportQuery.toString()}`}>Export Excel</a></Button>}
                        <Button asChild><Link href="/candidate-records/create">Register candidate</Link></Button>
                    </div>
                )}
            >
                <form className="grid gap-3" onSubmit={search}>
                    <div className="flex flex-wrap gap-3">
                        <Input name="search" defaultValue={filters.search} aria-label="Search candidates" placeholder="Name, email, job title, or reference" className="max-w-md" />
                        <Button type="submit">Search</Button>
                    </div>
                    <div className="flex flex-wrap gap-4 text-sm">
                        {Object.entries(options.expiryFilters).map(([value, label]) => (
                            <label key={value} className="flex items-center gap-2">
                                <input type="checkbox" name="expiry" value={value} defaultChecked={filters.expiry.includes(value)} />
                                {label}
                            </label>
                        ))}
                    </div>
                </form>
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="text-slate-500">
                            <tr>
                                <th className="py-2 pr-3">Reference</th>
                                <th className="py-2 pr-3">Name</th>
                                <th className="py-2 pr-3">Job title</th>
                                <th className="py-2 pr-3">Checklist</th>
                                <th className="py-2 pr-3">EAD</th>
                                <th className="py-2">Passport</th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.rows.map((row) => (
                                <tr key={row.id} className="border-t border-slate-100 align-top">
                                    <td className="py-2 pr-3"><Link href={`/candidate-records/${row.id}`} className="font-medium text-blue-700">{row.reference}</Link></td>
                                    <td className="py-2 pr-3">{row.name}<div className="text-slate-500">{row.email}</div></td>
                                    <td className="py-2 pr-3">{row.jobTitle}</td>
                                    <td className="py-2 pr-3">{row.completion}%</td>
                                    <td className="py-2 pr-3"><ExpiryIndicator value={row.ead} /></td>
                                    <td className="py-2"><ExpiryIndicator value={row.passport} /></td>
                                </tr>
                            ))}
                            {records.rows.length === 0 && <tr><td colSpan={6} className="py-6 text-slate-500">No candidates match this search.</td></tr>}
                        </tbody>
                    </table>
                </div>
                <p className="mt-3 text-sm text-slate-500">{records.total} candidates</p>
                {records.pages > 1 && (
                    <div className="mt-4 flex gap-2">
                        {Array.from({ length: records.pages }, (_, index) => index + 1).map((page) => (
                            <Link key={page} href={`/candidate-records?page=${page}&search=${encodeURIComponent(filters.search)}&${filters.expiry.map((value) => `expiry[]=${encodeURIComponent(value)}`).join('&')}`} className={`rounded-md px-3 py-1 text-sm ${page === records.page ? 'bg-slate-900 text-white' : 'border border-slate-200'}`}>{page}</Link>
                        ))}
                    </div>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}

function ExpiryIndicator({ value }: { value: Expiry }) {
    const alert = value.tone === 'alert-expired' || value.tone === 'alert-expiring';

    return (
        <div className={alert ? 'text-rose-700' : 'text-slate-700'}>
            <StatusBadge value={value.label} tone={value.tone} />
            <div className="mt-1">{value.date ?? 'No date'}{value.detail ? ` · ${value.detail}` : ''}</div>
        </div>
    );
}
