import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { SignaturePad } from './signature-pad';

interface EvaluationForm {
    available: boolean;
    dateFrom: string;
    dateTo: string;
    narrative: string;
    studentName: string;
    studentSigned: boolean;
    studentSignedOn: string;
    employerSigned: boolean;
    employerName?: string;
    employerTitle?: string;
    employerSignedOn?: string;
    datesReady: boolean;
    evaluationPdf?: boolean;
}

export function StudentEvaluationPanel({ token, evaluation }: { token: string; evaluation: EvaluationForm }) {
    const form = useForm<{ narrative: string; signature: File | null }>({
        narrative: evaluation.narrative,
        signature: null,
    });
    const [error, setError] = useState('');

    function submit(event: FormEvent) {
        event.preventDefault();
        if (form.data.narrative.trim() === '') {
            setError('Write your own progress narrative. It is not generated for you.');

            return;
        }
        setError('');
        form.post(`/i983/s/${token}/evaluation`, { forceFormData: true });
    }

    return (
        <section className="mx-auto mt-6 max-w-[860px] rounded-md border border-neutral-300 bg-white p-4">
            <h2 className="text-lg font-semibold text-neutral-950">Final evaluation</h2>
            <p className="mt-2 text-sm leading-6 text-neutral-600">
                Write your own progress narrative. Your printed name comes from Section 1. The evaluation dates come from your employer.
            </p>
            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-neutral-500">Printed name</dt>
                    <dd className="font-medium">{evaluation.studentName === '' ? 'Not recorded in Section 1' : evaluation.studentName}</dd>
                </div>
                <div>
                    <dt className="text-neutral-500">Evaluation dates</dt>
                    <dd className="font-medium">{evaluation.datesReady ? `${evaluation.dateFrom} to ${evaluation.dateTo}` : 'Your employer has not saved both dates yet.'}</dd>
                </div>
            </dl>
            {evaluation.studentSigned ? (
                <div className="mt-4">
                    <p className="whitespace-pre-wrap text-sm text-neutral-900">{evaluation.narrative}</p>
                    <p className="mt-3 text-sm text-neutral-600">Signed {evaluation.studentSignedOn}. This narrative and signature were not replaced.</p>
                </div>
            ) : (
                <form className="mt-4" onSubmit={submit}>
                    <label className="block text-sm text-neutral-700">
                        Your progress narrative
                        <textarea
                            className="mt-1 min-h-32 w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
                            value={form.data.narrative}
                            onChange={(event) => form.setData('narrative', event.target.value)}
                        />
                    </label>
                    <div className="mt-4">
                        <p className="mb-2 text-sm text-neutral-700">Your signature</p>
                        <SignaturePad disabled={!evaluation.datesReady} value={form.data.signature} restoreUrl={null} onChange={(file) => form.setData('signature', file)} />
                        {!evaluation.datesReady && <p className="mt-2 text-sm text-neutral-600">You can save the narrative now. The signature waits until both evaluation dates are saved.</p>}
                    </div>
                    {(error || form.errors.narrative || form.errors.signature) && (
                        <p className="mt-3 text-sm text-rose-800">{error || form.errors.narrative || form.errors.signature}</p>
                    )}
                    <button className="mt-4 min-h-11 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white" type="submit" disabled={form.processing}>
                        {form.data.signature ? 'Save narrative and signature' : 'Save narrative'}
                    </button>
                </form>
            )}
            {evaluation.employerSigned && (
                <p className="mt-3 text-sm text-neutral-600">
                    Your employer has signed this evaluation{evaluation.employerName ? ` as ${evaluation.employerName}` : ''}{evaluation.employerSignedOn ? ` on ${evaluation.employerSignedOn}` : ''}.
                </p>
            )}
            {evaluation.evaluationPdf && (
                <p className="mt-3 flex flex-wrap gap-4 text-sm">
                    <a className="font-medium text-neutral-950 underline" href={`/i983/s/${token}/evaluation`}>View completed evaluation</a>
                    <a className="font-medium text-neutral-950 underline" href={`/i983/s/${token}/evaluation/download`}>Download completed evaluation</a>
                </p>
            )}
        </section>
    );
}
