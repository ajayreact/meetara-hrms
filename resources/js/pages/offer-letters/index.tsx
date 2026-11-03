import { Link, router, useForm } from '@inertiajs/react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { StatusBadge } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';

interface Letter {
    id: number;
    employeeCode?: string | null;
    employeeName: string;
    designation?: string;
    position: string;
    joiningDate?: string | null;
    monthly: string;
    createdAt?: string | null;
    status: string;
    download?: boolean;
}

interface Editing {
    id: number;
    status: string;
    statusKey: string;
    country: string;
    body: string;
    editable: boolean;
    own: boolean;
    employeeName: string;
    issuable: boolean;
    unresolved: string[];
    blocks?: string[];
    letterheadId: number | null;
    correction?: {
        joiningDate: string;
        dateOfIssue: string;
        salaryDiffers: boolean;
        offerSalary: { basic: string | null; hra: string | null; special: string | null; monthly: string };
        currentSalary: { basic: string; hra: string; special: string; monthly: string; effectiveFrom: string } | null;
        canCorrect: boolean;
    } | null;
}

interface Counts {
    total: number;
    drafts: number;
    generated: number;
}

function OfferCorrection({ letterId, correction }: { letterId: number; correction: NonNullable<Editing['correction']> }) {
    const form = useForm({
        joining_date: correction.joiningDate,
        date_of_issue: correction.dateOfIssue,
        reason: '',
        apply_salary: false,
    });

    return (
        <form className="mb-4 rounded-2xl border border-slate-200 bg-white p-4" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.offer-letters.correct', letterId)); }}>
            <h2 className="text-sm font-semibold text-slate-950">Correct this offer</h2>
            <p className="mt-1 text-sm text-slate-600">The joining date has to be later than the issue date. This updates only this unissued offer. It does not change the employee record or salary register, and it does not create a PDF.</p>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <label className="text-sm text-slate-700">Date of issue
                    <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" type="date" value={form.data.date_of_issue} onChange={(event) => form.setData('date_of_issue', event.target.value)} />
                </label>
                <label className="text-sm text-slate-700">Joining date
                    <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" type="date" value={form.data.joining_date} onChange={(event) => form.setData('joining_date', event.target.value)} />
                    {form.errors.joining_date && <span className="mt-1 block text-rose-700">{form.errors.joining_date}</span>}
                </label>
                <label className="text-sm text-slate-700 sm:col-span-2">Reason
                    <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    {form.errors.reason && <span className="mt-1 block text-rose-700">{form.errors.reason}</span>}
                </label>
            </div>
            {correction.salaryDiffers && correction.currentSalary && (
                <label className="mt-3 flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={form.data.apply_salary} onChange={(event) => form.setData('apply_salary', event.target.checked)} />
                    <span>Copy the current salary snapshot into this offer only. Offer basic {correction.offerSalary.basic ?? '—'}, HRA {correction.offerSalary.hra ?? '—'}, special allowance {correction.offerSalary.special ?? '—'}. Current record from {correction.currentSalary.effectiveFrom}: basic {correction.currentSalary.basic}, HRA {correction.currentSalary.hra}, special allowance {correction.currentSalary.special}. The salary register stays as it is.</span>
                </label>
            )}
            <button className="mt-3 rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" disabled={form.processing || !correction.canCorrect}>Save correction</button>
        </form>
    );
}

function label(token: string): string {
    return token.split('_').join(' ');
}

export default function OfferLetters({ workspace, letters, counts = { total: letters.length, drafts: 0, generated: 0 }, approvalRequired = true, editing = null, notice }: HrmsPageProps & {
    letters: Letter[];
    counts?: Counts;
    approvalRequired?: boolean;
    editing?: Editing | null;
    notice?: string;
}) {
    if (editing) {
        const blocked = editing.unresolved.length > 0;

        return (
            <HrmsLayout workspace={workspace} title="Appointment letter">
                <PageIntro
                    title={editing.employeeName}
                    subtitle={editing.status}
                    actions={<Link className="rounded-xl border border-slate-200 px-4 py-2 text-sm" href={route('hrms.offer-letters')}>Back to offers</Link>}
                />
                {notice && <p className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">{notice}</p>}
                {blocked && (
                    <p className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        {editing.editable
                            ? `Add ${editing.unresolved.map(label).join(', ')} before this letter can be previewed or generated. Use Edit details to fill them in.`
                            : `Missing ${editing.unresolved.map(label).join(', ')}. This offer is waiting in its current state, so its saved details stay locked.`}
                    </p>
                )}
                {(editing.blocks ?? []).map((block) => (
                    <p key={block} className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">{block}</p>
                ))}
                {editing.correction && <OfferCorrection letterId={editing.id} correction={editing.correction} />}
                <div className="mb-4 flex flex-wrap gap-2">
                    {editing.editable && <Link className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm" href={route('hrms.offer-letters.create', { letter: editing.id })}>Edit details</Link>}
                    <button
                        className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm disabled:opacity-50"
                        type="button"
                        disabled={blocked}
                        onClick={() => router.post(route('hrms.offer-letters.appointment-preview', editing.id), {}, {
                            onSuccess: () => window.open(route('hrms.offer-letters.preview-pdf', editing.id), '_blank', 'noopener'),
                        })}
                    >
                        Preview appointment letter
                    </button>
                    {!approvalRequired && editing.country === 'india' && (
                        <button
                            className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white disabled:opacity-50"
                            type="button"
                            disabled={blocked}
                            onClick={() => router.post(route('hrms.offer-letters.issue', editing.id), { confirm: 'ISSUE' })}
                        >
                            Generate PDF
                        </button>
                    )}
                    {approvalRequired && editing.statusKey === 'generated' && (
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="button" disabled={blocked} onClick={() => router.post(route('hrms.offer-letters.submit', editing.id))}>Submit for approval</button>
                    )}
                    {approvalRequired && editing.statusKey === 'pending_approval' && !editing.own && (
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="button" onClick={() => router.post(route('hrms.offer-letters.approve', editing.id), { confirm: 'APPROVE' })}>Approve</button>
                    )}
                    {approvalRequired && editing.issuable && (
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="button" onClick={() => router.post(route('hrms.offer-letters.issue', editing.id), { confirm: 'ISSUE' })}>Generate PDF</button>
                    )}
                    {['issued', 'accepted', 'declined'].includes(editing.statusKey) && (
                        <a className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm" href={route('hrms.offer-letters.pdf', editing.id)}>Download PDF</a>
                    )}
                </div>
                <article className="rounded-2xl border border-slate-200 bg-white px-8 py-8">
                    <div className="prose prose-slate max-w-none" dangerouslySetInnerHTML={{ __html: editing.body }} />
                </article>
            </HrmsLayout>
        );
    }

    return (
        <HrmsLayout workspace={workspace} title="Offer letters">
            <PageIntro
                title="Offer letters"
                subtitle="Select an employee, enter the offer, preview the appointment letter, and download the PDF."
                actions={<Link className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" href={route('hrms.offer-letters.create')}>+ Create offer letter</Link>}
            />
            {notice && <p className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">{notice}</p>}
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                {[['Total offers', counts.total], ['Drafts', counts.drafts], ['Generated PDFs', counts.generated]].map(([label, value]) => (
                    <div key={String(label)} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                        <p className="text-sm text-slate-500">{label}</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-950">{value}</p>
                    </div>
                ))}
            </div>
            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 text-slate-500">
                        <tr>
                            {['Employee', 'Employee ID', 'Designation', 'Joining date', 'Salary', 'Created', 'Status'].map((heading) => (
                                <th key={heading} className="px-3 py-3 font-medium">{heading}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {letters.length === 0 && (
                            <tr><td className="px-3 py-8 text-slate-500" colSpan={7}>No offer letters yet.</td></tr>
                        )}
                        {letters.map((letter) => (
                            <tr key={letter.id} className="border-t border-slate-100">
                                <td className="px-3 py-3 font-medium text-slate-950">{letter.employeeName}</td>
                                <td className="px-3 py-3">{letter.employeeCode || '—'}</td>
                                <td className="px-3 py-3">{letter.designation || letter.position}</td>
                                <td className="px-3 py-3">{letter.joiningDate || '—'}</td>
                                <td className="px-3 py-3">{letter.monthly}</td>
                                <td className="px-3 py-3">{letter.createdAt ? letter.createdAt.slice(0, 10) : '—'}</td>
                                <td className="px-3 py-3">
                                    <StatusBadge value={letter.status} />
                                    <div className="mt-2 flex gap-3">
                                        <Link className="text-blue-700" href={route('hrms.offer-letters', { letter: letter.id })}>View</Link>
                                        {letter.download && <a className="text-blue-700" href={route('hrms.offer-letters.pdf', letter.id)}>Download PDF</a>}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="mt-4 text-sm"><Link className="text-slate-500 underline" href={route('hrms.offer-letters.setup')}>Company offer settings</Link></p>
        </HrmsLayout>
    );
}
