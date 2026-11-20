import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface EvaluationPage {
    caseId: number;
    reference: string;
    candidate: string;
    caseStatus: string;
    evaluationStatus: string;
    studentName: string;
    employerName: string;
    supervisorName: string;
    supervisorTitle: string;
    optStart: string;
    optEnd: string;
    employmentStart: string;
    suggestedFrom: string;
    suggestedTo: string;
    dateFrom: string;
    dateTo: string;
    narrative: string;
    studentSigned: boolean;
    employerSigned: boolean;
    documentId: number | null;
    finalStored: boolean;
    employerPrintedName: string;
    employerTitle: string;
    employerSignedOn: string;
    notice?: string;
}

const statuses: Record<string, string> = {
    pending: 'Pending',
    in_progress: 'In progress',
    ready_for_signature: 'Ready for signature',
    completed: 'Completed',
};

function recorded(value: string): string {
    return value === '' ? 'Not recorded' : value;
}

const control = 'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm';

export default function I983Evaluation({ workspace, notice, ...evaluation }: HrmsPageProps & EvaluationPage) {
    const form = useForm({ date_from: evaluation.dateFrom, date_to: evaluation.dateTo });
    const suggestions = evaluation.suggestedFrom !== '' || evaluation.suggestedTo !== '';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('hrms.i983.evaluation.save', evaluation.caseId));
    }

    function useSuggested() {
        form.setData({
            date_from: evaluation.suggestedFrom !== '' ? evaluation.suggestedFrom : form.data.date_from,
            date_to: evaluation.suggestedTo !== '' ? evaluation.suggestedTo : form.data.date_to,
        });
    }

    return (
        <HrmsLayout workspace={workspace}>
            <PageIntro title="Final evaluation" subtitle={`${evaluation.reference} · ${evaluation.candidate}`} />
            {notice && <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
            <div className="mb-4">
                <Link className="text-sm font-medium text-blue-700" href={route('hrms.i983.show', evaluation.caseId)}>Back to case</Link>
            </div>
            <SectionCard title="Recorded information">
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-slate-500">Student name from Section 1</dt><dd className="font-medium">{recorded(evaluation.studentName)}</dd></div>
                    <div><dt className="text-slate-500">Employer</dt><dd className="font-medium">{recorded(evaluation.employerName)}</dd></div>
                    <div><dt className="text-slate-500">Supervisor</dt><dd className="font-medium">{recorded(evaluation.supervisorName)}</dd></div>
                    <div><dt className="text-slate-500">Supervisor title</dt><dd className="font-medium">{recorded(evaluation.supervisorTitle)}</dd></div>
                    <div><dt className="text-slate-500">STEM OPT start</dt><dd className="font-medium">{recorded(evaluation.optStart)}</dd></div>
                    <div><dt className="text-slate-500">STEM OPT end</dt><dd className="font-medium">{recorded(evaluation.optEnd)}</dd></div>
                    <div><dt className="text-slate-500">Employment start</dt><dd className="font-medium">{recorded(evaluation.employmentStart)}</dd></div>
                    <div><dt className="text-slate-500">Evaluation status</dt><dd className="font-medium">{statuses[evaluation.evaluationStatus] ?? evaluation.evaluationStatus}</dd></div>
                </dl>
                <p className="mt-3 text-sm text-slate-600">Employment start is shown for context. It is not the evaluation period.</p>
            </SectionCard>
            <form className="mt-4" onSubmit={submit}>
                <SectionCard title="Evaluation dates">
                    <p className="text-sm text-slate-600">
                        Suggested from: {evaluation.suggestedFrom === '' ? 'Not recorded' : evaluation.suggestedFrom}. Suggested to: {evaluation.suggestedTo === '' ? 'Not recorded' : evaluation.suggestedTo}.
                        Suggestions stay blank until you choose them. Both dates are required, and each one must fall inside the stored STEM OPT period. If either STEM OPT date is missing, complete it before saving this range.
                    </p>
                    <button className="mt-3 rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium" type="button" disabled={!suggestions} onClick={useSuggested}>Use suggested dates</button>
                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                        <label className="text-sm text-slate-700">From
                            <input className={control} type="date" value={form.data.date_from} onChange={(event) => form.setData('date_from', event.target.value)} />
                        </label>
                        <label className="text-sm text-slate-700">To
                            <input className={control} type="date" value={form.data.date_to} onChange={(event) => form.setData('date_to', event.target.value)} />
                        </label>
                    </div>
                    {form.errors.date_from && <p className="mt-2 text-sm text-rose-700">{form.errors.date_from}</p>}
                    {form.errors.date_to && <p className="mt-2 text-sm text-rose-700">{form.errors.date_to}</p>}
                    {form.errors.narrative && <p className="mt-2 text-sm text-rose-700">{form.errors.narrative}</p>}
                    <button className="mt-3 rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={form.processing}>Save dates</button>
                </SectionCard>
            </form>
            <div className="mt-4">
                <SectionCard title="Student narrative">
                    <p className="text-sm text-slate-600">The student writes this narrative and signs it on the private student link. HR does not enter it.</p>
                    <p className="mt-3 whitespace-pre-wrap text-sm text-slate-900">{evaluation.narrative === '' ? 'Not provided yet.' : evaluation.narrative}</p>
                    <p className="mt-3 text-sm text-slate-600">Student signature: {evaluation.studentSigned ? 'On file' : 'Not signed'}. Employer signature: {evaluation.employerSigned ? `On file · ${evaluation.employerPrintedName}, ${evaluation.employerTitle} · ${evaluation.employerSignedOn}` : 'Not signed'}.</p>
                    {evaluation.employerSigned && evaluation.documentId === null && (
                        <div className="mt-4 flex flex-wrap gap-3">
                            <a className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium" href={route('hrms.i983.evaluation.preview', evaluation.caseId)} target="_blank" rel="noopener noreferrer">Preview evaluation PDF</a>
                            {evaluation.finalStored ? (
                                <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="button" onClick={() => router.post(route('hrms.i983.evaluation.document', evaluation.caseId))}>
                                    Store separate evaluation PDF
                                </button>
                            ) : (
                                <p className="text-sm text-slate-600">This evaluation will be included when the final I-983 is first created. The final signed PDF is not replaced after that.</p>
                            )}
                        </div>
                    )}
                    {evaluation.documentId !== null && (
                        <div className="mt-4">
                            <a className="text-sm font-medium text-blue-700" href={route('hrms.i983.documents.preview', [evaluation.caseId, evaluation.documentId])} target="_blank" rel="noopener noreferrer">Open stored evaluation PDF</a>
                        </div>
                    )}
                    {evaluation.studentSigned && evaluation.narrative !== '' && evaluation.dateFrom !== '' && evaluation.dateTo !== '' && !evaluation.employerSigned && (
                        <button
                            className="mt-3 rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white"
                            type="button"
                            onClick={() => router.post(route('hrms.i983.evaluation.signature', evaluation.caseId))}
                        >
                            Confirm employer signature
                        </button>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
