import { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import type { HrmsPageProps } from '@/types/hrms';

interface Legal {
    legalName: string;
    legalAddress: string;
    registrationDetails: string;
    signatoryName: string;
    signatoryDesignation: string;
    hrEmail: string;
    status: string;
    hasSignature: boolean;
    signatureOnFile: boolean;
    signatureEnabled: boolean;
    signatureActive: boolean;
    signatureWidth: number;
    signatureHeight: number;
    signatureAlign: string;
    signatureGapBefore: number;
    signatureGapAfter: number;
    signatureImageWidth: number | null;
    signatureImageHeight: number | null;
}

interface Letterhead {
    id: number;
    version: number;
    name: string;
    status: string;
    pageSize: string;
    backgroundReady: boolean;
}

interface Identity {
    legalName: string;
    letterheadName: string;
    letterheadVersion: number | null;
    pageSize: string;
    previewUrl: string | null;
    confirmed: boolean;
    confirmedBy: string;
    confirmedAt: string;
    canConfirm: boolean;
    warning: string;
}

const control = 'w-full rounded-xl border border-slate-200 px-3 py-2 text-sm';

export default function OfferSetup({ workspace, legal = null, letterheads = [], approvalRequired, canConfigure, identity = null, identityNotes = [], notice }: HrmsPageProps & {
    legal?: Legal | null;
    letterheads?: Letterhead[];
    approvalRequired: boolean;
    canConfigure: boolean;
    identity?: Identity | null;
    identityNotes?: string[];
    notice?: string;
}) {
    const details = useForm({
        country: 'india',
        legal_name: legal?.legalName ?? '',
        legal_address: legal?.legalAddress ?? '',
        registration_details: legal?.registrationDetails ?? '',
        signatory_name: legal?.signatoryName ?? '',
        signatory_designation: legal?.signatoryDesignation ?? '',
        hr_email: legal?.hrEmail ?? '',
    });
    const letterhead = useForm<{ country: string; letterhead: File | null }>({ country: 'india', letterhead: null });
    const signature = useForm<{ country: string; signature: File | null }>({ country: 'india', signature: null });
    const placement = useForm({
        enabled: legal?.signatureEnabled ?? false,
        width: String(legal?.signatureWidth ?? 140),
        height: String(legal?.signatureHeight ?? 48),
        align: legal?.signatureAlign ?? 'left',
        gap_before: String(legal?.signatureGapBefore ?? 8),
        gap_after: String(legal?.signatureGapAfter ?? 6),
    });
    const active = letterheads.find((item) => item.status === 'active') ?? null;

    return (
        <HrmsLayout workspace={workspace} title="Company offer settings">
            <PageIntro title="Company offer settings" subtitle="Set these once. Everyday offer letters do not ask for them again." actions={<Link className="text-sm text-slate-500 underline" href={route('hrms.offer-letters')}>Back to offer letters</Link>} />
            {notice && <p className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">{notice}</p>}
            {identityNotes.map((note) => <p key={note} className="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">{note}</p>)}
            <div className="grid gap-4">
                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-base font-semibold">Offer approval</h2>
                    <p className="mt-1 text-sm text-slate-600">{approvalRequired ? 'A different authorized person must approve an offer before the PDF is generated.' : 'Authorized HR staff can preview and generate India appointment PDFs directly.'}</p>
                    {canConfigure ? (
                        <form className="mt-3 flex items-center gap-3" onSubmit={(event: FormEvent) => { event.preventDefault(); router.post(route('hrms.offer-letters.approval'), { required: approvalRequired ? 0 : 1 }); }}>
                            <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="submit">{approvalRequired ? 'Turn approval off' : 'Turn approval on'}</button>
                        </form>
                    ) : <p className="mt-3 text-sm text-slate-500">A company admin changes this setting.</p>}
                </section>
                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-base font-semibold">Letterhead and legal company</h2>
                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <div>
                            <p className="text-sm text-slate-500">Legal company name</p>
                            <p className="mt-1 text-sm font-medium text-slate-950">{identity?.legalName || 'Not saved'}</p>
                            <p className="mt-4 text-sm text-slate-500">Active letterhead</p>
                            <p className="mt-1 text-sm text-slate-950">{identity?.letterheadName ? `${identity.letterheadName}, version ${identity.letterheadVersion}, ${identity.pageSize}` : 'No active letterhead'}</p>
                            {identity && !identity.confirmed && <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">{identity.warning}</p>}
                            {identity?.confirmed && <p className="mt-4 text-sm text-slate-700">Confirmed by {identity.confirmedBy} on {identity.confirmedAt.slice(0, 10)}. Saving the legal details or replacing the letterhead clears this confirmation.</p>}
                            {identity?.canConfirm && (
                                <form className="mt-4" onSubmit={(event: FormEvent) => { event.preventDefault(); router.post(route('hrms.offer-letters.letterhead-confirmation'), { country: 'india', confirm: 'CONFIRM LETTERHEAD' }); }}>
                                    <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit">Confirm this letterhead belongs to the legal company</button>
                                </form>
                            )}
                            {identity && !identity.confirmed && !identity.canConfirm && <p className="mt-4 text-sm text-slate-500">A company admin confirms this relationship. It is not confirmed automatically.</p>}
                        </div>
                        {identity?.previewUrl && <img className="max-h-80 w-full rounded-xl border border-slate-200 object-contain" src={identity.previewUrl} alt="Active letterhead preview" />}
                    </div>
                </section>
                <form className="rounded-2xl border border-slate-200 bg-white p-5" onSubmit={(event) => { event.preventDefault(); details.post(route('hrms.offer-letters.legal')); }}>
                    <h2 className="text-base font-semibold">Company legal details and HR signatory</h2>
                    <p className="mt-1 text-sm text-slate-500">Status: {legal?.status ?? 'Not saved'}. Saving does not verify the profile.</p>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                        <label className="text-sm sm:col-span-2">Legal name<input className={`${control} mt-1`} value={details.data.legal_name} onChange={(event) => details.setData('legal_name', event.target.value)} /></label>
                        <label className="text-sm sm:col-span-2">Legal address<textarea className={`${control} mt-1`} rows={3} value={details.data.legal_address} onChange={(event) => details.setData('legal_address', event.target.value)} /></label>
                        <label className="text-sm">Signatory name<input className={`${control} mt-1`} value={details.data.signatory_name} onChange={(event) => details.setData('signatory_name', event.target.value)} /></label>
                        <label className="text-sm">Signatory designation<input className={`${control} mt-1`} value={details.data.signatory_designation} onChange={(event) => details.setData('signatory_designation', event.target.value)} /></label>
                        <label className="text-sm sm:col-span-2">HR email<input className={`${control} mt-1`} value={details.data.hr_email} onChange={(event) => details.setData('hr_email', event.target.value)} /></label>
                    </div>
                    <button className="mt-4 rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit">Save company details</button>
                </form>
                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-base font-semibold">Letterhead</h2>
                    <p className="mt-1 text-sm text-slate-600">{active ? `Active: ${active.name}, version ${active.version}, ${active.pageSize}.` : 'No active India letterhead.'}</p>
                    {active && <img alt="Active letterhead" className="mt-3 max-h-40 rounded-xl border border-slate-200" src={route('hrms.offer-letters.letterheads.preview', active.id)} />}
                    <form className="mt-4 flex flex-wrap items-center gap-3" onSubmit={(event) => { event.preventDefault(); letterhead.post(route('hrms.offer-letters.letterheads.store'), { forceFormData: true }); }}>
                        <input type="file" accept="image/png,image/jpeg,application/pdf" onChange={(event) => letterhead.setData('letterhead', event.target.files?.[0] ?? null)} />
                        <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="submit">Upload letterhead</button>
                    </form>
                </section>
                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-base font-semibold">India Appointment Letter</h2>
                    <p className="mt-1 text-sm text-slate-600">This is the one standard India appointment letter. Older copies stay on file for audit and are not offered for new letters. Approval is still required before a replacement becomes active.</p>
                    <div className="mt-3 flex flex-wrap gap-4">
                        <Link className="text-sm text-blue-700 underline" href={route('hrms.offer-letters.templates', { country: 'india' })}>Edit Appointment Letter</Link>
                        <a className="text-sm text-blue-700 underline" href={route('hrms.offer-letters.india-preview')} target="_blank" rel="noreferrer">Preview Template</a>
                    </div>
                </section>
                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-base font-semibold">HR signature image</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        {!legal?.signatureOnFile && 'No signature image is on file. The signature will not print.'}
                        {legal?.signatureOnFile && !legal.signatureActive && `The signature image is inactive${legal.signatureImageWidth && legal.signatureImageHeight ? `, ${legal.signatureImageWidth} × ${legal.signatureImageHeight} px` : ''}. The signature will not print.`}
                        {legal?.signatureOnFile && legal.signatureActive && !legal.signatureEnabled && `On file${legal.signatureImageWidth && legal.signatureImageHeight ? `, ${legal.signatureImageWidth} × ${legal.signatureImageHeight} px` : ''}. Automatic placement is off. The signature will not print.`}
                        {legal?.signatureOnFile && legal.signatureActive && legal.signatureEnabled && `On file${legal.signatureImageWidth && legal.signatureImageHeight ? `, ${legal.signatureImageWidth} × ${legal.signatureImageHeight} px` : ''}. Automatic placement is on. The signature prints above the signatory name.`}
                    </p>
                    {legal?.signatureOnFile && <img alt="HR signature" className="mt-3 max-h-16" src={route('hrms.offer-letters.signature.show', { country: 'india' })} />}
                    <form className="mt-4 flex flex-wrap items-center gap-3" onSubmit={(event) => { event.preventDefault(); signature.post(route('hrms.offer-letters.signature.store'), { forceFormData: true }); }}>
                        <input type="file" accept="image/png,image/jpeg" onChange={(event) => signature.setData('signature', event.target.files?.[0] ?? null)} />
                        <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm" type="submit">Upload signature</button>
                    </form>
                    <form className="mt-4" onSubmit={(event) => { event.preventDefault(); placement.transform((data) => ({ ...data, country: 'india', enabled: data.enabled ? 1 : 0, width: Number(data.width), height: Number(data.height), gap_before: Number(data.gap_before), gap_after: Number(data.gap_after) })); placement.post(route('hrms.offer-letters.signature.settings')); }}>
                        <label className="text-sm"><input className="mr-2" type="checkbox" checked={placement.data.enabled} onChange={(event) => placement.setData('enabled', event.target.checked)} />Place the signature on generated letters</label>
                        <button className="mt-3 block rounded-xl border border-slate-200 px-4 py-2 text-sm" type="submit">Save placement</button>
                    </form>
                </section>
            </div>
        </HrmsLayout>
    );
}
