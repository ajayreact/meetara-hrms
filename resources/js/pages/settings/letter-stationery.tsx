import { SectionCard } from '@/components/hrms/section-card';
import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { signatureSummary } from '../offer-letters/rich-letter-editor';

interface Legal {
    legalName: string;
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

interface CountryPack {
    letterheads: Letterhead[];
    legal: Legal | null;
}

export interface Stationery {
    india: CountryPack;
    usa: CountryPack;
}

export default function LetterStationery({ stationery }: { stationery: Stationery }) {
    const [country, setCountry] = useState<'india' | 'usa'>('india');
    const [enlarged, setEnlarged] = useState<string | null>(null);

    return (
        <div className="mt-4 grid gap-4">
            <SectionCard title="Letterhead and HR signature">
                <p className="text-sm text-slate-600">India and the USA keep separate letterheads and signatures. Images stay in private storage for this company. A later replacement does not change a letter that has already been issued. The signature image is placed in the closing only. It is not a legally verified electronic signature.</p>
                <div className="mt-3 flex gap-2">
                    <button className={`rounded-xl px-3 py-2 text-sm ${country === 'india' ? 'bg-blue-700 text-white' : 'border border-slate-200'}`} type="button" onClick={() => setCountry('india')}>India</button>
                    <button className={`rounded-xl px-3 py-2 text-sm ${country === 'usa' ? 'bg-blue-700 text-white' : 'border border-slate-200'}`} type="button" onClick={() => setCountry('usa')}>USA</button>
                </div>
            </SectionCard>
            <CountryPanel key={country} country={country} pack={stationery[country]} onEnlarge={setEnlarged} />
            {enlarged && (
                <button type="button" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-6" onClick={() => setEnlarged(null)}>
                    <img src={enlarged} alt="Larger preview" className="max-h-full max-w-full object-contain" />
                </button>
            )}
        </div>
    );
}

function CountryPanel({ country, pack, onEnlarge }: { country: 'india' | 'usa'; pack: CountryPack; onEnlarge: (src: string) => void }) {
    const label = country === 'usa' ? 'USA' : 'India';
    const legal = pack.legal;
    const onFile = Boolean(legal?.signatureOnFile ?? legal?.hasSignature);
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
        page_width: country === 'india' ? '612' : '595.28',
        page_height: country === 'india' ? '792' : '841.89',
    });
    const signatureForm = useForm<{ signature: File | null; country: string }>({ signature: null, country });
    const placementForm = useForm({
        enabled: legal?.signatureEnabled ?? false,
        width: String(legal?.signatureWidth ?? 140),
        height: String(legal?.signatureHeight ?? 48),
        align: legal?.signatureAlign ?? 'left',
        gap_before: String(legal?.signatureGapBefore ?? 8),
        gap_after: String(legal?.signatureGapAfter ?? 6),
    });

    const uploadLetterhead = (event: FormEvent) => {
        event.preventDefault();
        letterheadForm.post(route('hrms.offer-letters.letterheads.store'), { forceFormData: true });
    };

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <SectionCard title={`${label} letterhead`}>
                <p className="text-sm text-slate-600">Upload a PNG or JPG. The original shape is kept. The image is the background of every PDF page, and the letter text stays inside the margins below.</p>
                <form className="mt-3 grid gap-3 sm:grid-cols-2" onSubmit={uploadLetterhead}>
                    <label className="text-sm sm:col-span-2">Letterhead image
                        <input className="mt-1 block w-full text-sm" type="file" accept="image/png,image/jpeg" onChange={(event) => letterheadForm.setData('letterhead', event.target.files?.[0] ?? null)} />
                    </label>
                    <label className="text-sm">Page size
                        <select className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={pageChoice(letterheadForm.data.page_width, letterheadForm.data.page_height)} onChange={(event) => applyPage(letterheadForm, event.target.value)}>
                            <option value="letter">US Letter</option>
                            <option value="a4">A4</option>
                            <option value="custom">Custom</option>
                        </select>
                    </label>
                    <Field label="Page width" value={letterheadForm.data.page_width} onChange={(value) => letterheadForm.setData('page_width', value)} />
                    <Field label="Page height" value={letterheadForm.data.page_height} onChange={(value) => letterheadForm.setData('page_height', value)} />
                    <Field label="Top margin" value={letterheadForm.data.margin_top} onChange={(value) => letterheadForm.setData('margin_top', value)} />
                    <Field label="Bottom margin" value={letterheadForm.data.margin_bottom} onChange={(value) => letterheadForm.setData('margin_bottom', value)} />
                    <Field label="Left margin" value={letterheadForm.data.margin_left} onChange={(value) => letterheadForm.setData('margin_left', value)} />
                    <Field label="Right margin" value={letterheadForm.data.margin_right} onChange={(value) => letterheadForm.setData('margin_right', value)} />
                    <Field label="Header reserved height" value={letterheadForm.data.header_height} onChange={(value) => letterheadForm.setData('header_height', value)} />
                    <Field label="Footer reserved height" value={letterheadForm.data.footer_height} onChange={(value) => letterheadForm.setData('footer_height', value)} />
                    <p className="text-sm text-slate-600 sm:col-span-2">{layoutNote(letterheadForm.data)}</p>
                    {letterheadForm.errors.letterhead && <p className="text-sm text-rose-700 sm:col-span-2">{letterheadForm.errors.letterhead}</p>}
                    <button className="w-fit rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={letterheadForm.processing}>Upload letterhead</button>
                </form>
                <ul className="mt-3 space-y-3 text-sm">
                    {pack.letterheads.map((item) => (
                        <LetterheadRow key={item.id} item={item} onEnlarge={onEnlarge} />
                    ))}
                    {pack.letterheads.length === 0 && <li className="text-slate-500">No letterhead uploaded</li>}
                </ul>
            </SectionCard>
            <SectionCard title={`${label} HR signature`}>
                <p className="text-sm text-slate-600">Upload a transparent PNG or a JPG. The closing of a new appointment letter uses this order: With warm regards, the company legal name, this image, the signatory name, then the signatory designation. The employee acceptance section does not receive this image.</p>
                {!legal && <p className="mt-3 text-sm text-amber-800">Save the {label} legal details on the offer letter templates page before uploading a signature.</p>}
                <p className="mt-2 text-sm">Status: {onFile ? `${legal?.signatureActive ? 'active' : 'inactive'} · version ${legal?.signatureVersion ?? 0}` : 'No HR signature uploaded'}{legal?.signatureEnabled ? ' · automatic placement on' : ' · automatic placement off'}</p>
                {onFile && legal && <p className="mt-2 text-sm text-slate-600">{signatureSummary(legal)}</p>}
                {onFile ? (
                    <button type="button" className="mt-3 block rounded-xl border border-slate-200 bg-white p-3" onClick={() => onEnlarge(route('hrms.offer-letters.signature.show', { country }))}>
                        <img className="max-h-24 max-w-full object-contain" alt="Current HR signature" src={route('hrms.offer-letters.signature.show', { country })} />
                    </button>
                ) : (
                    <p className="mt-3 text-sm text-slate-500">No HR signature uploaded</p>
                )}
                <form className="mt-3 grid gap-3" onSubmit={(event) => { event.preventDefault(); signatureForm.post(route('hrms.offer-letters.signature.store'), { forceFormData: true }); }}>
                    <input className="text-sm" type="file" accept="image/png,image/jpeg" onChange={(event) => signatureForm.setData('signature', event.target.files?.[0] ?? null)} />
                    {signatureForm.errors.signature && <p className="text-sm text-rose-700">{signatureForm.errors.signature}</p>}
                    <button className="w-fit rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={signatureForm.processing || !legal}>Replace signature</button>
                </form>
                {onFile && (
                    <div className="mt-2 flex flex-wrap gap-3">
                        <button className="text-sm underline" type="button" onClick={() => router.post(route('hrms.offer-letters.signature.active'), { country, active: legal?.signatureActive ? 0 : 1 })}>{legal?.signatureActive ? 'Deactivate signature' : 'Activate signature'}</button>
                        <button className="text-sm underline" type="button" onClick={() => router.post(route('hrms.offer-letters.signature.remove'), { country })}>Remove signature</button>
                    </div>
                )}
                <form className="mt-4 grid gap-3 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); placementForm.transform((data) => ({ ...data, country, enabled: data.enabled ? 1 : 0, width: Number(data.width), height: Number(data.height), gap_before: Number(data.gap_before), gap_after: Number(data.gap_after) })); placementForm.post(route('hrms.offer-letters.signature.settings')); }}>
                    <label className="text-sm sm:col-span-2"><input className="mr-2" type="checkbox" checked={placementForm.data.enabled} onChange={(event) => placementForm.setData('enabled', event.target.checked)} />Place the HR signature automatically on new letters</label>
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
                    <button className="w-fit rounded-xl border border-slate-200 px-3 py-2 text-sm" type="submit" disabled={!legal}>Save placement</button>
                </form>
            </SectionCard>
        </div>
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
            <p>v{item.version} · {item.name} · {item.status}{item.backgroundReady ? ' · background ready' : ''}{item.pageSize ? ` · ${item.pageSize}` : ''}</p>
            {item.mime.startsWith('image/') && (
                <button type="button" className="mt-3 block w-full rounded-xl border border-slate-200 bg-white p-3" onClick={() => onEnlarge(route('hrms.offer-letters.letterheads.preview', item.id))}>
                    <img alt={item.name} src={route('hrms.offer-letters.letterheads.preview', item.id)} className="mx-auto max-h-48 max-w-full object-contain" />
                    {item.imageWidth && item.imageHeight && <span className="mt-2 block text-xs text-slate-500">{item.imageWidth} × {item.imageHeight} pixels. The preview keeps the original shape.</span>}
                </button>
            )}
            {item.note && <p className="mt-2 text-amber-800">{item.note}</p>}
            <form className="mt-3 grid gap-2 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.offer-letters.letterheads.layout', item.id)); }}>
                <label className="text-sm sm:col-span-2">Page size
                    <select className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={pageChoice(form.data.page_width, form.data.page_height)} onChange={(event) => applyPage(form, event.target.value)}>
                        <option value="letter">US Letter</option>
                        <option value="a4">A4</option>
                        <option value="custom">Custom</option>
                    </select>
                </label>
                <Field label="Page width" value={form.data.page_width} onChange={(value) => form.setData('page_width', value)} />
                <Field label="Page height" value={form.data.page_height} onChange={(value) => form.setData('page_height', value)} />
                <Field label="Top margin" value={form.data.margin_top} onChange={(value) => form.setData('margin_top', value)} />
                <Field label="Bottom margin" value={form.data.margin_bottom} onChange={(value) => form.setData('margin_bottom', value)} />
                <Field label="Left margin" value={form.data.margin_left} onChange={(value) => form.setData('margin_left', value)} />
                <Field label="Right margin" value={form.data.margin_right} onChange={(value) => form.setData('margin_right', value)} />
                <Field label="Header reserved height" value={form.data.header_height} onChange={(value) => form.setData('header_height', value)} />
                <Field label="Footer reserved height" value={form.data.footer_height} onChange={(value) => form.setData('footer_height', value)} />
                <p className="text-sm text-slate-600 sm:col-span-2">{layoutNote(form.data)}</p>
                <div className="flex flex-wrap gap-2 sm:col-span-2">
                    <button className="rounded-xl border px-3 py-2 text-sm" type="submit">Save layout</button>
                    {item.status === 'active' && <button type="button" className="rounded-xl border px-3 py-2 text-sm" onClick={() => router.post(route('hrms.offer-letters.letterheads.deactivate', item.id))}>Deactivate</button>}
                </div>
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

function pageChoice(width: string, height: string): string {
    const w = Number(width);
    const h = Number(height);

    if (Math.abs(w - 612) < 3 && Math.abs(h - 792) < 3) {
        return 'letter';
    }

    if (Math.abs(w - 595) < 4 && Math.abs(h - 842) < 4) {
        return 'a4';
    }

    return 'custom';
}

function applyPage(form: { data: { page_width: string; page_height: string }; setData: (data: { page_width: string; page_height: string }) => void }, choice: string) {
    if (choice === 'letter') {
        form.setData({ ...form.data, page_width: '612', page_height: '792' });
    }

    if (choice === 'a4') {
        form.setData({ ...form.data, page_width: '595.28', page_height: '841.89' });
    }
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
    return (
        <label className="text-sm">{label}
            <input className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={value} onChange={(event) => onChange(event.target.value)} />
        </label>
    );
}
