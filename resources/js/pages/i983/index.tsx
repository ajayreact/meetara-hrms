import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { LeadPagination, LeadSearchField } from '@/components/hrms/lead-listing';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface CaseRow {
    id: number;
    reference: string;
    candidate: string;
    email: string;
    status: string;
    stageLabel?: string;
    submitted: boolean;
    canReviewEvaluation?: boolean;
    createdAt: string;
}

interface Catalog {
    cases?: CaseRow[];
    matching?: number;
    page?: number;
    pages?: number;
    perPage?: number;
    q?: string;
    sort?: string;
    direction?: string;
    notice?: string;
}

const control = 'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm';

const labels: Record<string, string> = {
    awaiting_student: 'Student Submission',
    student_submitted: 'Employer Information',
    correction_requested: 'Student Submission',
    resubmitted: 'Employer Information',
    finalized: 'Generate Final PDF',
};

function label(status: string): string {
    return labels[status] ?? status;
}

function visit(query: Record<string, string | number>) {
    router.get(route('hrms.i983'), query, { preserveState: true, preserveScroll: true, replace: true });
}

export default function I983Index({
    workspace,
    cases = [],
    matching = 0,
    page = 1,
    pages = 1,
    perPage = 25,
    q = '',
    sort = 'created',
    direction = 'desc',
    notice,
}: HrmsPageProps & Catalog) {
    const form = useForm({ candidate_name: '', candidate_email: '' });
    const errors = usePage().props.errors as Record<string, string>;
    const query = { q, sort, direction, page, per_page: perPage };

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('hrms.i983.store'));
    }

    function search(value: string) {
        visit({ ...query, q: value, page: 1 });
    }

    function sortBy(column: string) {
        const next = sort === column ? (direction === 'asc' ? 'desc' : 'asc') : column === 'created' ? 'desc' : 'asc';
        visit({ ...query, sort: column, direction: next, page: 1 });
    }

    return (
        <HrmsLayout workspace={workspace}>
            <PageIntro title="STEM OPT I-983" subtitle="Create a case, then follow Student Submission, Employer Information, Training Plan, Employer Certification, and Generate Final PDF." />
            {notice && <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
            {errors.delete && <p className="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">{errors.delete}</p>}
            <form className="mb-4" onSubmit={submit}>
                <SectionCard title="New case">
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="text-sm text-slate-700">Candidate name<input className={control} value={form.data.candidate_name} onChange={(event) => form.setData('candidate_name', event.target.value)} /></label>
                        <label className="text-sm text-slate-700">Candidate email<input className={control} type="email" value={form.data.candidate_email} onChange={(event) => form.setData('candidate_email', event.target.value)} /></label>
                    </div>
                    {form.errors.candidate_name && <p className="mt-2 text-sm text-rose-700">{form.errors.candidate_name}</p>}
                    <button className="mt-3 rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={form.processing}>Create case</button>
                </SectionCard>
            </form>
            <SectionCard title="Cases">
                <div className="mb-4">
                    <LeadSearchField applied={q} onSearch={search} placeholder="Reference, candidate name, or email" ariaLabel="Search I-983 cases" />
                </div>
                {cases.length === 0 ? (
                    <p className="text-sm text-slate-600">{q === '' ? 'No I-983 cases yet.' : 'No I-983 cases match this search.'}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="py-2 pr-3"><button type="button" className="font-medium" onClick={() => sortBy('reference')}>Reference</button></th>
                                    <th className="py-2 pr-3"><button type="button" className="font-medium" onClick={() => sortBy('name')}>Candidate</button></th>
                                    <th className="py-2 pr-3">Email</th>
                                    <th className="py-2 pr-3"><button type="button" className="font-medium" onClick={() => sortBy('status')}>Status</button></th>
                                    <th className="py-2 pr-3"><button type="button" className="font-medium" onClick={() => sortBy('created')}>Created</button></th>
                                    <th className="py-2">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {cases.map((item) => (
                                    <tr key={item.id} className="border-t border-slate-100">
                                        <td className="py-2 pr-3">{item.reference}</td>
                                        <td className="py-2 pr-3">{item.candidate}</td>
                                        <td className="py-2 pr-3">{item.email}</td>
                                        <td className="py-2 pr-3">{item.stageLabel || label(item.status)}</td>
                                        <td className="py-2 pr-3">{item.createdAt}</td>
                                        <td className="py-2">
                                            <div className="flex flex-wrap items-center gap-3">
                                                <Link className="font-medium text-blue-700" href={route('hrms.i983.show', item.id)}>Review</Link>
                                                {item.canReviewEvaluation && (
                                                    <Link className="font-medium text-blue-700" href={route('hrms.i983.evaluation', item.id)}>Review Evaluation</Link>
                                                )}
                                                {workspace.role === 'company_admin' && (
                                                    <button className="font-medium text-rose-700" type="button" onClick={() => {
                                                        if (window.confirm(`Delete ${item.reference} (${label(item.status)}) for ${item.candidate}? The case, student link, and stored PDFs will be removed. This cannot be undone.`)) {
                                                            router.delete(route('hrms.i983.destroy', item.id));
                                                        }
                                                    }}>Delete</button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {matching > 0 && (
                    <LeadPagination
                        page={page}
                        pages={pages}
                        matching={matching}
                        perPage={perPage}
                        onPage={(next) => visit({ ...query, page: next })}
                        onPerPage={(next) => visit({ ...query, per_page: next, page: 1 })}
                    />
                )}
            </SectionCard>
        </HrmsLayout>
    );
}
