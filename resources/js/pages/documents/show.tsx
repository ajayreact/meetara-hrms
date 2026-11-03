import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Version {
    id: number;
    category: string;
    status: string;
    reference: string;
    description: string;
    fileName: string;
    uploadedAt: string;
    reviewer: string;
    reviewedAt: string | null;
    note: string;
    replacesId: number | null;
    latest: boolean;
    preview: boolean;
}

export default function DocumentShowPage({
    workspace,
    employee,
    document,
    versions = [],
    canReview = false,
    notice,
}: HrmsPageProps & {
    employee: { id: number; name: string; code: string };
    document: Version;
    versions?: Version[];
    canReview?: boolean;
    notice?: string | null;
}) {
    const replacement = useForm({ note: '' });
    const preview = document.preview ? route('hrms.documents.preview', [employee.id, document.id]) : '';
    const image = /\.(jpe?g|png)$/i.test(document.fileName);
    const decide = (status: 'verified' | 'rejected', confirm: 'VERIFY' | 'REJECT', note: string) => {
        router.post(route('hrms.employees.documents.review', [employee.id, document.id]), { status, note, confirm });
    };

    return (
        <HrmsLayout workspace={workspace} title={document.category}>
            <PageIntro title={document.category} subtitle={`${employee.name} · ${employee.code} · ${document.status}${document.latest ? ' · Latest version' : ''}`} actions={<Link className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" href={route('hrms.documents.employee', employee.id)}>Checklist</Link>} />
            {notice && <p className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <SectionCard title="Preview">
                    {!document.preview || preview === '' ? <p className="text-sm text-slate-500">This file cannot be previewed.</p> : image ? (
                        <img className="max-h-96 rounded-xl border border-slate-200" alt="" src={preview} />
                    ) : (
                        <iframe className="h-96 w-full rounded-xl border border-slate-200" title={document.category} src={preview} />
                    )}
                    <a className="mt-3 inline-flex text-sm font-medium text-blue-700" href={route('hrms.employees.documents.download', [employee.id, document.id])}>Download original</a>
                </SectionCard>
                <SectionCard title="Verification">
                    <dl className="space-y-2 text-sm">
                        <div><dt className="text-xs text-slate-500">Uploaded</dt><dd>{document.uploadedAt.slice(0, 16).replace('T', ' ')}</dd></div>
                        <div><dt className="text-xs text-slate-500">Reference</dt><dd>{document.reference || 'Not recorded'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Description</dt><dd>{document.description || 'Not recorded'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Verified by</dt><dd>{document.reviewer || 'Not reviewed'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Verification time</dt><dd>{document.reviewedAt ? document.reviewedAt.slice(0, 16).replace('T', ' ') : 'Not reviewed'}</dd></div>
                        <div><dt className="text-xs text-slate-500">HR remarks</dt><dd>{document.note || 'None'}</dd></div>
                    </dl>
                    {canReview && document.latest && document.status !== 'Verified' && (
                        <ReviewActions onDecide={decide} />
                    )}
                    {canReview && document.latest && (
                        <form className="mt-3 grid gap-2" onSubmit={(event) => { event.preventDefault(); replacement.post(route('hrms.documents.replacement', [employee.id, document.id])); }}>
                            <label className="text-sm">Replacement reason
                                <textarea className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={replacement.data.note} onChange={(event) => replacement.setData('note', event.target.value)} />
                            </label>
                            {replacement.errors.note && <p className="text-sm text-rose-700">{replacement.errors.note}</p>}
                            <button className="w-fit rounded-lg border border-slate-200 px-3 py-2 text-sm" type="submit">Request replacement</button>
                        </form>
                    )}
                </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Version history">
                    <ul className="space-y-2 text-sm">
                        {versions.map((version) => (
                            <li key={version.id} className="rounded-xl border border-slate-100 px-3 py-2">
                                <Link className="font-medium text-slate-900" href={route('hrms.documents.show', [employee.id, version.id])}>{version.latest ? 'Latest version' : `Version ${version.id}`}</Link>
                                <p className="text-xs text-slate-500">{version.status} · {version.uploadedAt.slice(0, 10)}{version.reviewer ? ` · ${version.reviewer}` : ''}{version.replacesId ? ` · Replaces ${version.replacesId}` : ''}</p>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function ReviewActions({ onDecide }: { onDecide: (status: 'verified' | 'rejected', confirm: 'VERIFY' | 'REJECT', note: string) => void }) {
    const [note, setNote] = useState('');

    return (
        <div className="mt-4 grid gap-2">
            <label className="text-sm">Remarks
                <textarea className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={note} onChange={(event) => setNote(event.target.value)} />
            </label>
            <div className="flex flex-wrap gap-2">
                <button className="rounded-lg bg-blue-700 px-3 py-2 text-sm text-white" type="button" onClick={() => onDecide('verified', 'VERIFY', note)}>Verify</button>
                <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => onDecide('rejected', 'REJECT', note)}>Reject</button>
            </div>
        </div>
    );
}
