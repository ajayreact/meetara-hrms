import { Head } from '@inertiajs/react';

export default function UnavailableForm({ uploadNotice }: { uploadNotice?: string | null }) {
    return (
        <main className="mx-auto flex min-h-screen max-w-xl items-center px-4 py-12">
            <Head title="Candidate form" />
            <section className="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h1 className="text-2xl font-semibold text-slate-950">This form is not accepting submissions.</h1>
                <p className="mt-3 text-sm leading-6 text-slate-600">The company that gave you this link is not taking new candidate submissions. Existing submissions are not shown here.</p>
                {uploadNotice && <p className="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{uploadNotice}</p>}
            </section>
        </main>
    );
}
