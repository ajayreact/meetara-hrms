import { FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';

interface Row {
    id: number;
    reference: string;
    name: string;
    email: string;
    jobTitle: string;
    status: string;
    submittedAt: string;
}

interface Submissions {
    rows: Row[];
    page: number;
    pages: number;
    total: number;
    statuses: Record<string, string>;
    enabled: boolean;
}

export default function CandidatesPage({ workspace, submissions, filters, notice }: HrmsPageProps & { submissions: Submissions; filters: { search: string; status: string; from: string; to: string }; notice?: string | null }) {
    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get('/candidates', {
            search: String(data.get('search') ?? ''),
            status: String(data.get('status') ?? ''),
            from: String(data.get('from') ?? ''),
            to: String(data.get('to') ?? ''),
        }, { preserveState: true });
    };

    return (
        <HrmsLayout workspace={workspace} title="Candidate forms">
            <PageIntro title="Candidate submissions" subtitle={submissions.enabled ? 'The public form is accepting new submissions for this company.' : 'The public form is not accepting new submissions. Existing submissions stay here.'} />
            {notice && <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{notice}</p>}
            <SectionCard title="Find a submission" extra={<span className="text-sm text-slate-500">{submissions.total} submissions</span>}>
                <form className="grid gap-3 md:grid-cols-5" onSubmit={search}>
                    <Input name="search" defaultValue={filters.search} aria-label="Search by name, email, or reference" placeholder="Name, email, or reference" />
                    <select name="status" defaultValue={filters.status} aria-label="Submission status" className="h-10 rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">All statuses</option>
                        {Object.entries(submissions.statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                    <Input name="from" type="date" defaultValue={filters.from} aria-label="Submitted from" />
                    <Input name="to" type="date" defaultValue={filters.to} aria-label="Submitted to" />
                    <Button type="submit">Search</Button>
                </form>
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="text-slate-500">
                            <tr><th className="py-2 pr-3">Reference</th><th className="py-2 pr-3">Name</th><th className="py-2 pr-3">Email</th><th className="py-2 pr-3">Status</th><th className="py-2">Submitted</th></tr>
                        </thead>
                        <tbody>
                            {submissions.rows.map((row) => (
                                <tr key={row.id} className="border-t border-slate-100">
                                    <td className="py-2 pr-3"><Link href={`/candidates/${row.id}`} className="font-medium text-blue-700">{row.reference}</Link></td>
                                    <td className="py-2 pr-3">{row.name}</td>
                                    <td className="py-2 pr-3">{row.email}</td>
                                    <td className="py-2 pr-3"><StatusBadge value={row.status} /></td>
                                    <td className="py-2">{row.submittedAt}</td>
                                </tr>
                            ))}
                            {submissions.rows.length === 0 && <tr><td colSpan={5} className="py-6 text-slate-500">No candidate submissions match this search.</td></tr>}
                        </tbody>
                    </table>
                </div>
                {submissions.pages > 1 && (
                    <div className="mt-4 flex gap-2">
                        {Array.from({ length: submissions.pages }, (_, index) => index + 1).map((page) => (
                            <Link key={page} href={`/candidates?page=${page}&search=${encodeURIComponent(filters.search)}&status=${encodeURIComponent(filters.status)}&from=${encodeURIComponent(filters.from)}&to=${encodeURIComponent(filters.to)}`} className={`rounded-md px-3 py-1 text-sm ${page === submissions.page ? 'bg-slate-900 text-white' : 'border border-slate-200'}`}>{page}</Link>
                        ))}
                    </div>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}
