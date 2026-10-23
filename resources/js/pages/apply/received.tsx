import { Head } from '@inertiajs/react';

export default function ReceivedForm({ reference }: { reference?: string | null }) {
    return (
        <main className="mx-auto flex min-h-screen max-w-xl items-center px-4 py-12">
            <Head title="Candidate form" />
            <section className="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h1 className="text-2xl font-semibold text-slate-950">Submission received</h1>
                <p className="mt-3 text-sm leading-6 text-slate-600">The company will review this submission. Submitting the form does not establish employment.</p>
                {reference ? <p className="mt-4 text-sm text-slate-800">Reference <span className="font-semibold">{reference}</span>. Write this reference down. It is not an employee number.</p> : <p className="mt-4 text-sm text-slate-600">If you just submitted the form, the reference was shown on this page. This page does not look up a previous submission.</p>}
            </section>
        </main>
    );
}
