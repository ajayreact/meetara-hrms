import { router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { LetterPage, previewPages, RichLetterEditor, signatureSummary } from './rich-letter-editor';

export interface OfferDraftData {
    id: number;
    status: string;
    statusKey: string;
    country: string;
    body: string;
    editable: boolean;
    own: boolean;
    employeeName: string;
    reference: string | null;
    submittedAt: string | null;
    approvedAt: string | null;
    issuedAt: string | null;
    issuable: boolean;
    letterheadId: number | null;
    pageWidth: number | null;
    pageHeight: number | null;
    marginTop: number;
    marginRight: number;
    marginBottom: number;
    marginLeft: number;
    headerHeight: number;
    footerHeight: number;
    mime: string | null;
    showSignature: boolean;
    signatureWidth: number;
    signatureHeight: number;
    signatureAlign: string;
    signatureGapBefore: number;
    signatureGapAfter: number;
    unresolved?: string[];
    signatureOnFile?: boolean;
    signatureEnabled?: boolean;
    signatureImageWidth?: number | null;
    signatureImageHeight?: number | null;
}

export default function OfferDraft({ draft }: { draft: OfferDraftData }) {
    const form = useForm({ body: draft.body });
    const hoursForm = useForm({ working_hours: '' });
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [previewError, setPreviewError] = useState('');
    const pages = useMemo(() => previewPages(form.data.body, {}, {
        signatureUrl: draft.showSignature ? route('hrms.offer-letters.signature.show', { country: draft.country }) : null,
        signatureWidth: draft.signatureWidth,
        signatureHeight: draft.signatureHeight,
        signatureAlign: draft.signatureAlign,
        signatureGapBefore: draft.signatureGapBefore,
        signatureGapAfter: draft.signatureGapAfter,
    }), [draft, form.data.body]);

    useEffect(() => {
        form.setData('body', draft.body);
        setPreviewError('');
        setPreviewUrl((current) => {
            if (current) {
                URL.revokeObjectURL(current);
            }

            return null;
        });
        // Opening another letter replaces the editor. Typing must not be reset by unrelated page props.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft.id, draft.statusKey, draft.body]);

    async function openPreview() {
        setPreviewError('');
        const row = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
        const token = row ? decodeURIComponent(row.slice('XSRF-TOKEN='.length)) : '';
        const response = await fetch(route('hrms.offer-letters.preview-document', draft.id), {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf, application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            },
            body: JSON.stringify({ body: form.data.body }),
        });
        const type = response.headers.get('content-type') ?? '';

        if (response.type === 'opaqueredirect' || !response.ok || !type.includes('pdf')) {
            const payload = type.includes('json')
                ? await response.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null
                : null;
            setPreviewError(payload?.errors?.letter?.[0] ?? payload?.errors?.body?.[0] ?? payload?.message ?? 'The preview could not be created.');

            return;
        }

        const blob = await response.blob();
        const pdf = blob.type.includes('pdf') ? blob : new Blob([await blob.arrayBuffer()], { type: 'application/pdf' });
        setPreviewUrl((current) => {
            if (current) {
                URL.revokeObjectURL(current);
            }

            return URL.createObjectURL(pdf);
        });
    }

    const save = () => {
        form.post(route('hrms.offer-letters.content', draft.id), { preserveScroll: true });
    };
    const submit = () => {
        form.post(route('hrms.offer-letters.content', draft.id), {
            preserveScroll: true,
            onSuccess: () => {
                router.post(route('hrms.offer-letters.preview', draft.id), {}, {
                    preserveScroll: true,
                    onSuccess: () => router.post(route('hrms.offer-letters.submit', draft.id), {}, { preserveScroll: true }),
                });
            },
        });
    };

    return (
        <section className="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-sm font-medium">Step 3: Edit and preview the letter</p>
            <p className="mt-1 text-sm text-slate-600">{draft.country === 'usa' ? 'USA offer letter' : 'India appointment letter'} for {draft.employeeName}. Changes here stay on this offer. The master template is not changed. {draft.reference ? `Reference ${draft.reference}.` : ''}</p>
            <p className="mt-2 text-sm text-slate-600">Status: {draft.status}.{draft.submittedAt ? ` Submitted ${draft.submittedAt}.` : ''}{draft.approvedAt ? ` Approved ${draft.approvedAt}.` : ''}{draft.issuedAt ? ` Issued ${draft.issuedAt}.` : ''}</p>
            {draft.editable ? (
                <div className="mt-3">
                    <RichLetterEditor body={form.data.body} placeholders={[]} onChange={(body) => form.setData('body', body)} />
                    {form.errors.body && <p className="mt-2 text-sm text-rose-700">{form.errors.body}</p>}
                </div>
            ) : (
                <p className="mt-3 text-sm text-slate-600">This offer is {draft.status.toLowerCase()}, so the wording is locked.</p>
            )}
            {(draft.unresolved ?? []).length > 0 && (
                <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                    <p>This letter still has unresolved placeholders: {(draft.unresolved ?? []).join(', ')}. Fill them before preview, submission, or issuance.</p>
                    {(draft.unresolved ?? []).includes('working_hours') && (
                        <form className="mt-3 grid gap-2" onSubmit={(event: FormEvent) => { event.preventDefault(); hoursForm.post(route('hrms.offer-letters.working-hours', draft.id), { preserveScroll: true }); }}>
                            <label htmlFor="draft-hours">Working hours
                                <input id="draft-hours" className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2" value={hoursForm.data.working_hours} onChange={(event) => hoursForm.setData('working_hours', event.target.value)} />
                            </label>
                            {hoursForm.errors.working_hours && <p className="text-rose-700">{hoursForm.errors.working_hours}</p>}
                            <button className="w-fit rounded-xl border border-slate-200 bg-white px-3 py-2" type="submit" disabled={hoursForm.processing}>Save working hours on this offer</button>
                            <p className="text-xs">This updates the offer only. The employee record is not changed, and no hours are invented.</p>
                        </form>
                    )}
                </div>
            )}
            {draft.signatureOnFile && (
                <p className="mt-3 text-sm text-slate-600">{signatureSummary({
                    signatureImageWidth: draft.signatureImageWidth,
                    signatureImageHeight: draft.signatureImageHeight,
                    signatureWidth: draft.signatureWidth,
                    signatureHeight: draft.signatureHeight,
                    signatureEnabled: draft.signatureEnabled,
                })}</p>
            )}
            <div className="letter-editor mt-4 rounded-xl bg-slate-100 p-4">
                <p className="mb-3 text-xs font-medium text-slate-500">On-screen sample. The header and footer bands match the PDF. The center of the letterhead, including a watermark drawn in the file, is left clear. The signature image is only in the closing, and only when automatic placement is on. It is not a legally verified electronic signature. The PDF preview below is the copy that matches issuance.</p>
                {pages.map((page, index) => (
                    <LetterPage key={index} html={page} layout={draft.letterheadId && draft.mime?.startsWith('image/') && draft.pageWidth && draft.pageHeight ? {
                        imageUrl: route('hrms.offer-letters.letterheads.preview', draft.letterheadId),
                        pageWidth: draft.pageWidth,
                        pageHeight: draft.pageHeight,
                        marginTop: draft.marginTop,
                        marginRight: draft.marginRight,
                        marginBottom: draft.marginBottom,
                        marginLeft: draft.marginLeft,
                        headerHeight: draft.headerHeight,
                        footerHeight: draft.footerHeight,
                    } : null} />
                ))}
            </div>
            <div className="mt-4">
                <p className="text-sm font-medium">Step 4: Submit for approval and issue</p>
                <p className="mt-1 text-sm text-slate-600">Opening the preview does not issue the letter and does not replace a letter that was already issued. The person who writes the offer cannot approve it. No email is sent.</p>
                <div className="mt-3 flex flex-wrap gap-2">
                    {draft.editable && <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="button" onClick={save} disabled={form.processing}>Save draft</button>}
                    <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="button" onClick={() => void openPreview()}>Preview PDF</button>
                    {draft.editable && <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="button" onClick={submit} disabled={form.processing}>Submit for approval</button>}
                    {draft.statusKey === 'pending_approval' && !draft.own && (
                        <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.approve', draft.id), { confirm: 'APPROVE' })}>Approve</button>
                    )}
                    {draft.statusKey === 'pending_approval' && draft.own && <p className="text-sm text-slate-600">Another authorized person has to approve this offer.</p>}
                    {draft.statusKey === 'approved' && draft.issuable && (
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="button" onClick={() => router.post(route('hrms.offer-letters.issue', draft.id), { confirm: 'ISSUE' })}>Issue</button>
                    )}
                    {['issued', 'accepted', 'declined'].includes(draft.statusKey) && (
                        <a className="rounded-xl border border-slate-200 px-4 py-2 text-sm" href={route('hrms.offer-letters.pdf', draft.id)}>Download</a>
                    )}
                </div>
                {previewError && <p className="mt-3 text-sm text-rose-700">{previewError}</p>}
                {previewUrl && (
                    <div className="mt-4">
                        <p className="text-xs font-medium text-slate-500">Use the PDF controls to move between pages and change the zoom. This preview is not an issued letter.</p>
                        <iframe className="mt-2 h-[720px] w-full rounded-xl border border-slate-200" title="Offer letter preview" src={previewUrl} />
                    </div>
                )}
            </div>
        </section>
    );
}
