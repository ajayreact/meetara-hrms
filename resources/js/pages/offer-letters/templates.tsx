import { HrmsLayout } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { LetterPage, plainToHtml, previewPages, RichLetterEditor, signatureSummary } from './rich-letter-editor';

interface Legal {
    legalName: string;
    legalAddress: string;
    registrationDetails: string;
    signatoryName: string;
    signatoryDesignation: string;
    hrEmail: string;
    status: string;
    verifiedAt: string | null;
    hasSignature: boolean;
    signatureOnFile?: boolean;
    signatureEnabled?: boolean;
    signatureActive?: boolean;
    signatureVersion?: number;
    signatureWidth?: number;
    signatureHeight?: number;
    signatureAlign?: string;
    signatureGapBefore?: number;
    signatureGapAfter?: number;
    signatureImageWidth?: number | null;
    signatureImageHeight?: number | null;
}

interface Letterhead {
    id: number;
    version: number;
    name: string;
    mime: string;
    status: string;
    backgroundReady: boolean;
    pageSize?: string;
    pageWidth?: number | null;
    pageHeight?: number | null;
    headerHeight?: number;
    footerHeight?: number;
    marginTop: number;
    marginRight?: number;
    marginBottom?: number;
    marginLeft?: number;
    note?: string | null;
    imageWidth?: number | null;
    imageHeight?: number | null;
}

interface TemplateRow {
    id: number;
    name: string;
    status: string;
    version: number;
    body: string;
    approvedAt: string | null;
    createdBy: number | null;
    submittedBy: number | null;
}

const usaLetter = 'Dear {{employee_name}},\n\nWe offer the role of {{designation}}.\nJoining date: {{joining_date}}\nCompensation: {{salary}}\nAnnual amount: {{annual_ctc}}\n\n{{company_legal_name}}\n{{company_address}}\n{{hr_name}}';

function letterDraft(nextCountry: 'usa' | 'india', rows: TemplateRow[], indiaHtml: string) {
    const current = rows[0];
    const stored = current?.body?.trim() ?? '';
    const rich = stored.startsWith('<');

    if (nextCountry === 'india') {
        return {
            country: nextCountry,
            name: current?.name ?? 'India Appointment Letter',
            body: rich ? stored : (indiaHtml || '<p></p>'),
            template_id: (rich ? (current?.id ?? '') : '') as number | '',
        };
    }

    return {
        country: nextCountry,
        name: current?.name ?? 'USA offer letter',
        body: stored ? (rich ? stored : plainToHtml(stored)) : plainToHtml(usaLetter),
        template_id: (current?.id ?? '') as number | '',
    };
}

function editorBody(body: string, indiaHtml: string): string {
    const trim = body.trim();

    if (trim.startsWith('<')) {
        return body;
    }

    if (trim.startsWith('{') && indiaHtml) {
        return indiaHtml;
    }

    return plainToHtml(body);
}

export default function OfferTemplates({
    workspace,
    country,
    placeholders,
    sample,
    legal,
    letterheads,
    templates,
    indiaHtml,
    templateHistory = [],
    actorId = 0,
    mailNote,
    notice,
}: HrmsPageProps & {
    country: 'usa' | 'india';
    placeholders: string[];
    sample: Record<string, string>;
    legal: Legal | null;
    letterheads: Letterhead[];
    templates: TemplateRow[];
    indiaHtml?: string | null;
    templateHistory?: { versionId: number; name: string; version: number; status: string; templateStatus: string }[];
    actorId?: number;
    mailNote: string;
    notice?: string;
}) {
    const legalForm = useForm({
        country,
        legal_name: legal?.legalName ?? '',
        legal_address: legal?.legalAddress ?? '',
        registration_details: legal?.registrationDetails ?? '',
        signatory_name: legal?.signatoryName ?? '',
        signatory_designation: legal?.signatoryDesignation ?? '',
        hr_email: legal?.hrEmail ?? '',
    });
    const letterheadForm = useForm<{
        country: string;
        letterhead: File | null;
        margin_top: string;
        margin_right: string;
        margin_bottom: string;
        margin_left: string;
        header_height: string;
        footer_height: string;
        page_width: string;
        page_height: string;
    }>({
        country,
        letterhead: null,
        margin_top: country === 'india' ? '110' : '72',
        margin_right: country === 'india' ? '54' : '48',
        margin_bottom: country === 'india' ? '70' : '56',
        margin_left: country === 'india' ? '54' : '48',
        header_height: country === 'india' ? '96' : '0',
        footer_height: country === 'india' ? '72' : '0',
        page_width: country === 'india' ? '612' : '595',
        page_height: country === 'india' ? '792' : '842',
    });
    const signatureForm = useForm<{ signature: File | null; country: string }>({ signature: null, country });
    const [enlarged, setEnlarged] = useState<string | null>(null);
    const placementForm = useForm({
        enabled: legal?.signatureEnabled ?? false,
        width: String(legal?.signatureWidth ?? 140),
        height: String(legal?.signatureHeight ?? 48),
        align: legal?.signatureAlign ?? 'left',
        gap_before: String(legal?.signatureGapBefore ?? 8),
        gap_after: String(legal?.signatureGapAfter ?? 6),
    });
    const templateForm = useForm(letterDraft(country, templates, indiaHtml ?? ''));
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [previewError, setPreviewError] = useState('');
    const signatureOnFile = Boolean(legal?.signatureOnFile ?? legal?.hasSignature);
    const showSignature = Boolean(legal?.hasSignature && legal?.signatureEnabled);
    const pages = useMemo(() => previewPages(templateForm.data.body, sample, {
        signatureUrl: showSignature ? route('hrms.offer-letters.signature.show', { country }) : null,
        signatureWidth: legal?.signatureWidth,
        signatureHeight: legal?.signatureHeight,
        signatureAlign: legal?.signatureAlign,
        signatureGapBefore: legal?.signatureGapBefore,
        signatureGapAfter: legal?.signatureGapAfter,
    }), [country, legal?.hasSignature, legal?.signatureAlign, legal?.signatureEnabled, legal?.signatureGapAfter, legal?.signatureGapBefore, legal?.signatureHeight, legal?.signatureWidth, sample, showSignature, templateForm.data.body]);
    const activeLetterhead = letterheads.find((item) => item.status === 'active') ?? null;

    useEffect(() => {
        legalForm.setData({
            country,
            legal_name: legal?.legalName ?? '',
            legal_address: legal?.legalAddress ?? '',
            registration_details: legal?.registrationDetails ?? '',
            signatory_name: legal?.signatoryName ?? '',
            signatory_designation: legal?.signatoryDesignation ?? '',
            hr_email: legal?.hrEmail ?? '',
        });
        signatureForm.setData('country', country);
        placementForm.setData({
            enabled: legal?.signatureEnabled ?? false,
            width: String(legal?.signatureWidth ?? 140),
            height: String(legal?.signatureHeight ?? 48),
            align: legal?.signatureAlign ?? 'left',
            gap_before: String(legal?.signatureGapBefore ?? 8),
            gap_after: String(legal?.signatureGapAfter ?? 6),
        });
    }, [country, legal?.hrEmail, legal?.legalAddress, legal?.legalName, legal?.registrationDetails, legal?.signatoryDesignation, legal?.signatoryName, legal?.signatureEnabled, legal?.signatureVersion]);

    useEffect(() => {
        templateForm.setData(letterDraft(country, templates, indiaHtml ?? ''));
        setPreviewError('');
        setPreviewUrl((current) => {
            if (current) {
                URL.revokeObjectURL(current);
            }

            return null;
        });
        // Switching country replaces the draft. Legal-field updates must not wipe unsaved wording.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [country]);

    const saveLegal = (event: FormEvent) => {
        event.preventDefault();
        legalForm.post(route('hrms.offer-letters.legal'));
    };
    const saveTemplate = (event: FormEvent) => {
        event.preventDefault();
        templateForm.post(route('hrms.offer-letters.templates.save'));
    };

    async function openPreview() {
        setPreviewError('');

        if (templateForm.data.body.trim() === '') {
            setPreviewError('Enter the letter wording before opening the preview.');
            return;
        }

        const row = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
        const token = row ? decodeURIComponent(row.slice('XSRF-TOKEN='.length)) : '';
        const response = await fetch(route('hrms.offer-letters.templates.sample-pdf'), {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            },
            body: JSON.stringify({ country, body: templateForm.data.body }),
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

    return (
        <HrmsLayout workspace={workspace} title="Offer letter templates">
            <SectionCard title="Offer letter templates">
                <p className="text-sm text-slate-600">{mailNote}</p>
                <p className="mt-2 text-sm"><Link className="text-blue-700 underline" href={route('hrms.offer-letters')}>Back to offer letters</Link></p>
                {notice && <p className="mt-2 text-sm text-slate-700">{notice}</p>}
                <div className="mt-3 flex gap-2">
                    <Link className={`rounded-xl px-3 py-2 text-sm ${country === 'india' ? 'bg-blue-700 text-white' : 'border border-slate-200'}`} href={route('hrms.offer-letters.templates', { country: 'india' })}>India</Link>
                    <Link className={`rounded-xl px-3 py-2 text-sm ${country === 'usa' ? 'bg-blue-700 text-white' : 'border border-slate-200'}`} href={route('hrms.offer-letters.templates', { country: 'usa' })}>USA</Link>
                </div>
            </SectionCard>
            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <SectionCard title="Company legal details">
                    <p className="text-sm text-slate-600">The company display name is not legal verification. Saving clears any earlier verification.</p>
                    <form onSubmit={saveLegal} className="mt-3 grid gap-3">
                        <Field label="Legal company name" value={legalForm.data.legal_name} onChange={(value) => legalForm.setData('legal_name', value)} error={legalForm.errors.legal_name} />
                        <label className="text-sm">Registered address
                            <textarea className="mt-1 min-h-20 w-full rounded-xl border border-slate-200 px-3 py-2" value={legalForm.data.legal_address} onChange={(event) => legalForm.setData('legal_address', event.target.value)} />
                            {legalForm.errors.legal_address && <span className="mt-1 block text-rose-700">{legalForm.errors.legal_address}</span>}
                        </label>
                        <Field label="Registration details" value={legalForm.data.registration_details} onChange={(value) => legalForm.setData('registration_details', value)} />
                        <Field label="Authorized signatory" value={legalForm.data.signatory_name} onChange={(value) => legalForm.setData('signatory_name', value)} error={legalForm.errors.signatory_name} />
                        <Field label="Signatory designation" value={legalForm.data.signatory_designation} onChange={(value) => legalForm.setData('signatory_designation', value)} />
                        <Field label="HR contact email" value={legalForm.data.hr_email} onChange={(value) => legalForm.setData('hr_email', value)} error={legalForm.errors.hr_email} />
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={legalForm.processing}>Save legal details</button>
                    </form>
                    <p className="mt-3 text-sm text-slate-600">Status: {legal?.status ?? 'Not saved'}{legal?.verifiedAt ? ` · verified ${legal.verifiedAt}` : ''}.</p>
                    <button className="mt-2 rounded-xl border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.legal.verify'), { confirm: 'VERIFY', country })}>Verify legal details</button>
                </SectionCard>
                <SectionCard title="HR signatory signature">
                    <p className="text-sm text-slate-600">Upload a PNG or JPG of the authorized signatory's handwritten signature. A transparent PNG is preferred. This image is placed on the appointment letter. It is not a legally verified electronic signature, and it is not used for the employee acceptance line.</p>
                    <p className="mt-2 text-sm">Status: {signatureOnFile ? `${legal?.signatureActive ? 'active' : 'inactive'} · version ${legal?.signatureVersion ?? 0}` : 'No HR signature uploaded'}{legal?.signatureEnabled ? ' · automatic placement on' : ' · automatic placement off'}</p>
                    {!signatureOnFile && <p className="mt-2 text-sm text-amber-900">No signature image is on file. The signature will not print.</p>}
                    {signatureOnFile && !legal?.signatureActive && <p className="mt-2 text-sm text-amber-900">The signature image is inactive. The signature will not print.</p>}
                    {signatureOnFile && legal?.signatureActive && !showSignature && <p className="mt-2 text-sm text-amber-900">Automatic placement is off. The signature will not print.</p>}
                    {signatureOnFile && legal && <p className="mt-2 text-sm text-slate-600">{signatureSummary(legal)}</p>}
                    {signatureOnFile ? (
                        <button type="button" className="mt-3 block rounded-xl border border-slate-200 bg-white p-3" onClick={() => setEnlarged(route('hrms.offer-letters.signature.show', { country }))}>
                            <img className="max-h-24 max-w-full object-contain" alt="Current HR signature" src={route('hrms.offer-letters.signature.show', { country })} />
                        </button>
                    ) : (
                        <p className="mt-3 text-sm text-slate-500">No HR signature uploaded</p>
                    )}
                    <form className="mt-3 grid gap-3" onSubmit={(event) => { event.preventDefault(); signatureForm.post(route('hrms.offer-letters.signature.store'), { forceFormData: true }); }}>
                        <input className="text-sm" type="file" accept="image/png,image/jpeg" onChange={(event) => signatureForm.setData('signature', event.target.files?.[0] ?? null)} />
                        {signatureForm.errors.signature && <p className="text-sm text-rose-700">{signatureForm.errors.signature}</p>}
                        <button className="w-fit rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={signatureForm.processing}>Upload signature</button>
                    </form>
                    {signatureOnFile && (
                        <div className="mt-2 flex flex-wrap gap-3">
                            <button className="text-sm underline" type="button" onClick={() => router.post(route('hrms.offer-letters.signature.active'), { country, active: legal?.signatureActive ? 0 : 1 })}>{legal?.signatureActive ? 'Deactivate signature' : 'Activate signature'}</button>
                            <button className="text-sm underline" type="button" onClick={() => router.post(route('hrms.offer-letters.signature.remove'), { country })}>Remove signature</button>
                        </div>
                    )}
                    <form className="mt-4 grid gap-3 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); placementForm.transform((data) => ({ ...data, country, enabled: data.enabled ? 1 : 0, width: Number(data.width), height: Number(data.height), gap_before: Number(data.gap_before), gap_after: Number(data.gap_after) })); placementForm.post(route('hrms.offer-letters.signature.settings')); }}>
                        <label className="text-sm sm:col-span-2"><input className="mr-2" type="checkbox" checked={placementForm.data.enabled} onChange={(event) => placementForm.setData('enabled', event.target.checked)} />Place the HR signature automatically</label>
                        <Field label="Width" value={placementForm.data.width} onChange={(value) => placementForm.setData('width', value)} />
                        <Field label="Height" value={placementForm.data.height} onChange={(value) => placementForm.setData('height', value)} />
                        <label className="text-sm">Alignment
                            <select className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={placementForm.data.align} onChange={(event) => placementForm.setData('align', event.target.value)}>
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </label>
                        <Field label="Space before" value={placementForm.data.gap_before} onChange={(value) => placementForm.setData('gap_before', value)} />
                        <Field label="Space after" value={placementForm.data.gap_after} onChange={(value) => placementForm.setData('gap_after', value)} />
                        <button className="w-fit rounded-xl border border-slate-200 px-3 py-2 text-sm" type="submit">Save placement</button>
                    </form>
                </SectionCard>
            </div>
            <SectionCard title={`${country === 'usa' ? 'USA' : 'India'} letterhead`}>
                <p className="text-sm text-slate-600">Upload a clean one-page PDF, PNG, or JPG without appointment wording. Page size follows the file. These measurements are a starting point and can be changed.</p>
                <form className="mt-3 grid gap-3 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); letterheadForm.post(route('hrms.offer-letters.letterheads.store'), { forceFormData: true }); }}>
                    <label className="text-sm sm:col-span-2">Letterhead file
                        <input className="mt-1 block w-full text-sm" type="file" accept="image/png,image/jpeg,application/pdf" onChange={(event) => letterheadForm.setData('letterhead', event.target.files?.[0] ?? null)} />
                    </label>
                    <Field label="Page width" value={letterheadForm.data.page_width} onChange={(value) => letterheadForm.setData('page_width', value)} />
                    <Field label="Page height" value={letterheadForm.data.page_height} onChange={(value) => letterheadForm.setData('page_height', value)} />
                    <Field label="Header reserved height" value={letterheadForm.data.header_height} onChange={(value) => letterheadForm.setData('header_height', value)} />
                    <Field label="Footer reserved height" value={letterheadForm.data.footer_height} onChange={(value) => letterheadForm.setData('footer_height', value)} />
                    <Field label="Body start" value={letterheadForm.data.margin_top} onChange={(value) => letterheadForm.setData('margin_top', value)} />
                    <Field label="Bottom margin" value={letterheadForm.data.margin_bottom} onChange={(value) => letterheadForm.setData('margin_bottom', value)} />
                    <Field label="Left margin" value={letterheadForm.data.margin_left} onChange={(value) => letterheadForm.setData('margin_left', value)} />
                    <Field label="Right margin" value={letterheadForm.data.margin_right} onChange={(value) => letterheadForm.setData('margin_right', value)} />
                    <p className="text-sm text-slate-600 sm:col-span-2">{layoutNote(letterheadForm.data)}</p>
                    {letterheadForm.errors.letterhead && <p className="text-sm text-rose-700 sm:col-span-2">{letterheadForm.errors.letterhead}</p>}
                    <button className="w-fit rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={letterheadForm.processing}>Upload letterhead</button>
                </form>
                <ul className="mt-3 space-y-3 text-sm">
                    {letterheads.map((item) => (
                        <LetterheadRow key={item.id} item={item} onEnlarge={setEnlarged} />
                    ))}
                    {letterheads.length === 0 && <li className="text-slate-500">No letterhead uploaded</li>}
                </ul>
                {activeLetterhead?.pageWidth && activeLetterhead.pageHeight && (
                    <p className="mt-2 text-xs text-slate-500">Body area is about {Math.max(0, Math.round(activeLetterhead.pageHeight - activeLetterhead.marginTop - (activeLetterhead.marginBottom ?? 0)))} points tall on a {activeLetterhead.pageSize} page.</p>
                )}
            </SectionCard>
            <SectionCard title={`${country === 'usa' ? 'USA' : 'India'} template`}>
                <p className="text-sm text-slate-600">Each country can have several named templates. One approved template is the active default. Saving keeps a draft, and the person who wrote it cannot approve it.</p>
                <form onSubmit={saveTemplate} className="mt-3 grid gap-3">
                    <Field label="Template name" value={templateForm.data.name} onChange={(value) => templateForm.setData('name', value)} error={templateForm.errors.name} />
                    <p className="text-sm text-slate-600">Select text and format it from the toolbar. Saving keeps the formatting. Scripts are removed. This draft is not approved until another authorized user approves it.</p>
                    {country === 'india' && indiaHtml && (
                        <button className="w-fit rounded-xl border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => templateForm.setData('body', indiaHtml)}>Load the 22-clause appointment wording</button>
                    )}
                    <RichLetterEditor body={templateForm.data.body} placeholders={placeholders} onChange={(body) => templateForm.setData('body', body)} />
                    {templateForm.errors.body && <p className="text-sm text-rose-700">{templateForm.errors.body}</p>}
                    <div className="flex flex-wrap gap-2">
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={templateForm.processing}>Save draft</button>
                        <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="button" onClick={() => templateForm.setData({ ...templateForm.data, name: '', body: plainToHtml(country === 'india' ? '' : usaLetter), template_id: '' })}>New template</button>
                        <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="button" onClick={openPreview}>Preview PDF</button>
                    </div>
                </form>
                {previewError && <p className="mt-3 text-sm text-rose-700">{previewError}</p>}
                {previewUrl && (
                    <div className="mt-4">
                        <p className="text-xs font-medium text-slate-500">Sample preview. This is not an issued offer letter. Use the PDF controls to move between pages.</p>
                        <iframe className="mt-2 h-[720px] w-full rounded-xl border border-slate-200" title="Appointment letter preview" src={previewUrl} />
                    </div>
                )}
                <div className="letter-editor mt-4 rounded-xl bg-slate-100 p-4">
                    <p className="mb-3 text-xs font-medium text-slate-500">Sample layout with example employee details. This is not an issued offer letter. The header and footer bands match the PDF, and the center of the letterhead stays clear. The signature image appears only in the closing when automatic placement is on. It is not a legally verified electronic signature.</p>
                    {pages.map((page, index) => (
                        <LetterPage key={index} html={page} layout={activeLetterhead?.mime.startsWith('image/') && activeLetterhead.pageWidth && activeLetterhead.pageHeight ? {
                            imageUrl: route('hrms.offer-letters.letterheads.preview', activeLetterhead.id),
                            pageWidth: activeLetterhead.pageWidth,
                            pageHeight: activeLetterhead.pageHeight,
                            marginTop: activeLetterhead.marginTop,
                            marginRight: activeLetterhead.marginRight ?? 54,
                            marginBottom: activeLetterhead.marginBottom ?? 70,
                            marginLeft: activeLetterhead.marginLeft ?? 54,
                            headerHeight: activeLetterhead.headerHeight ?? 0,
                            footerHeight: activeLetterhead.footerHeight ?? 0,
                        } : null} />
                    ))}
                </div>
                <ul className="mt-4 space-y-3 text-sm">
                    {templates.length === 0 && <li className="text-slate-500">No template saved yet.</li>}
                    {templates.map((item) => (
                        <li key={item.id} className="rounded-xl border border-slate-200 p-3">
                            <p className="font-medium">{item.name} · v{item.version} · {item.status}{item.status === 'active' ? ' · default' : ''}</p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <button type="button" className="rounded-xl border px-3 py-1" onClick={() => { templateForm.setData({ ...templateForm.data, name: item.name, body: editorBody(item.body, indiaHtml ?? ''), template_id: item.id }); }}>Edit</button>
                                <button type="button" className="rounded-xl border px-3 py-1" onClick={() => router.post(route('hrms.offer-letters.templates.duplicate', item.id))}>Duplicate</button>
                                {item.status === 'draft' && <button type="button" className="rounded-xl border px-3 py-1" onClick={() => router.post(route('hrms.offer-letters.templates.submit', item.id))}>Submit for review</button>}
                                {item.status === 'submitted' && item.createdBy !== actorId && item.submittedBy !== actorId && <button type="button" className="rounded-xl border px-3 py-1" onClick={() => router.post(route('hrms.offer-letters.templates.approve', item.id), { confirm: 'APPROVE' })}>Approve</button>}
                                {item.status === 'approved' && <button type="button" className="rounded-xl border px-3 py-1" onClick={() => router.post(route('hrms.offer-letters.templates.activate', item.id), { confirm: 'ACTIVATE' })}>Activate</button>}
                                {item.status === 'submitted' && (item.createdBy === actorId || item.submittedBy === actorId) && <span className="text-slate-500">Another authorized person has to approve this version.</span>}
                            </div>
                        </li>
                    ))}
                </ul>
                <div className="mt-4">
                    <p className="text-sm font-medium text-slate-800">Version history</p>
                    {templateHistory.length === 0 && <p className="mt-2 text-sm text-slate-500">No versions yet.</p>}
                    <ul className="mt-2 space-y-2 text-sm">
                        {templateHistory.map((item) => (
                            <li key={item.versionId} className="flex flex-wrap justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2">
                                <span>{item.name} · v{item.version}</span>
                                <span className="text-slate-500">{item.status}{item.templateStatus === 'archived' ? ' · historical' : ''}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </SectionCard>
            {enlarged && (
                <button type="button" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-6" onClick={() => setEnlarged(null)}>
                    <img src={enlarged} alt="Larger preview" className="max-h-full max-w-full object-contain" />
                </button>
            )}
        </HrmsLayout>
    );
}

function LetterheadRow({ item, onEnlarge }: { item: Letterhead; onEnlarge: (src: string) => void }) {
    const form = useForm({
        margin_top: String(item.marginTop),
        margin_right: String(item.marginRight ?? 54),
        margin_bottom: String(item.marginBottom ?? 70),
        margin_left: String(item.marginLeft ?? 54),
        header_height: String(item.headerHeight ?? 0),
        footer_height: String(item.footerHeight ?? 0),
        page_width: String(item.pageWidth ?? 612),
        page_height: String(item.pageHeight ?? 792),
    });

    return (
        <li className="rounded-xl border border-slate-200 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span>v{item.version} · {item.name} · {item.status}{item.backgroundReady ? ' · background ready' : ' · stored, not placed behind text'}{item.pageSize ? ` · ${item.pageSize}` : ''}</span>
                <span className="flex gap-2">
                    <a className="underline" href={route('hrms.offer-letters.letterheads.preview', item.id)}>Letterhead file</a>
                    {item.status === 'active' && <button type="button" className="underline" onClick={() => router.post(route('hrms.offer-letters.letterheads.deactivate', item.id))}>Deactivate</button>}
                </span>
            </div>
            {item.mime.startsWith('image/') && (
                <button type="button" className="mt-3 block w-full rounded-xl border border-slate-200 bg-white p-3" onClick={() => onEnlarge(route('hrms.offer-letters.letterheads.preview', item.id))}>
                    <img alt={item.name} src={route('hrms.offer-letters.letterheads.preview', item.id)} className="mx-auto max-h-48 max-w-full object-contain" />
                    {item.imageWidth && item.imageHeight && <span className="mt-2 block text-xs text-slate-500">{item.imageWidth} × {item.imageHeight} pixels. The preview keeps the original shape.</span>}
                </button>
            )}
            {item.note && <p className="mt-2 text-amber-800">{item.note}</p>}
            <form className="mt-3 grid gap-2 sm:grid-cols-4" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.offer-letters.letterheads.layout', item.id)); }}>
                <Field label="Body start" value={form.data.margin_top} onChange={(value) => form.setData('margin_top', value)} />
                <Field label="Bottom" value={form.data.margin_bottom} onChange={(value) => form.setData('margin_bottom', value)} />
                <Field label="Left" value={form.data.margin_left} onChange={(value) => form.setData('margin_left', value)} />
                <Field label="Right" value={form.data.margin_right} onChange={(value) => form.setData('margin_right', value)} />
                <Field label="Header" value={form.data.header_height} onChange={(value) => form.setData('header_height', value)} />
                <Field label="Footer" value={form.data.footer_height} onChange={(value) => form.setData('footer_height', value)} />
                <Field label="Width" value={form.data.page_width} onChange={(value) => form.setData('page_width', value)} />
                <Field label="Height" value={form.data.page_height} onChange={(value) => form.setData('page_height', value)} />
                <p className="text-sm text-slate-600 sm:col-span-4">{layoutNote(form.data)}</p>
                <button className="w-fit rounded-xl border px-3 py-2 text-sm" type="submit">Save layout</button>
            </form>
        </li>
    );
}

function layoutNote(data: { page_width: string; page_height: string; margin_top: string; margin_bottom: string; margin_left: string; margin_right: string; header_height: string; footer_height: string }): string {
    const width = Number(data.page_width);
    const height = Number(data.page_height);
    const top = Math.max(Number(data.margin_top) || 0, Number(data.header_height) || 0);
    const bottom = Math.max(Number(data.margin_bottom) || 0, Number(data.footer_height) || 0);
    const left = Number(data.margin_left) || 0;
    const right = Number(data.margin_right) || 0;
    const bodyWidth = Math.max(0, Math.round(width - left - right));
    const bodyHeight = Math.max(0, Math.round(height - top - bottom));

    return `Printable body ${bodyWidth} × ${bodyHeight} pt. Text starts ${top} pt from the top and stops ${bottom} pt from the bottom. Only the header and footer bands are printed. A mark drawn in the middle of the file, including the arrow, stays out of the letter. Its opacity cannot be changed without a new image.`;
}

function Field({ label, value, onChange, error }: { label: string; value: string; onChange: (value: string) => void; error?: string }) {
    return (
        <label className="text-sm">{label}
            <input className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={value} onChange={(event) => onChange(event.target.value)} />
            {error && <span className="mt-1 block text-rose-700">{error}</span>}
        </label>
    );
}
