import { HrmsLayout } from '@/components/hrms/hrms-layout';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { Building2, Eye, FileText, ImageIcon, Info, PenLine, Upload } from 'lucide-react';
import { FormEvent, ReactNode, useRef, useState } from 'react';
import { HistoryRow, Identity, Legal, Letterhead, stamp, templateStatus, TemplateRow } from './india-shared';

type Section = 'company' | 'letterhead' | 'signature' | 'letter';

const field = 'mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100';

export default function IndiaSettings({
    workspace,
    legal = null,
    letterheads = [],
    templates = [],
    templateHistory = [],
    identity = null,
    approvalRequired = true,
    canConfigure = false,
    canVerifyLegal = false,
    actorId = 0,
    notice,
}: HrmsPageProps & {
    legal?: Legal | null;
    letterheads?: Letterhead[];
    templates?: TemplateRow[];
    templateHistory?: HistoryRow[];
    identity?: Identity | null;
    approvalRequired?: boolean;
    canConfigure?: boolean;
    canVerifyLegal?: boolean;
    actorId?: number;
    notice?: string;
}) {
    const [section, setSection] = useState<Section>('company');
    const current = templates.find((item) => item.name === 'India Appointment Letter') ?? templates[0] ?? null;
    const active = letterheads.find((item) => item.status === 'active') ?? null;
    const status = templateStatus(current?.status ?? 'draft');

    return (
        <HrmsLayout workspace={workspace} title="India Offer Letter Settings">
            <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                        <FileText className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-sm text-slate-500">
                            <Link className="hover:text-slate-800" href={route('hrms.offer-letters')}>Offer letters</Link>
                            <span className="px-1.5">/</span>
                            <span>Company offer settings</span>
                            <span className="px-1.5">/</span>
                            <span className="text-slate-700">India</span>
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">India Offer Letter Settings</h1>
                        <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Manage your company information, letterhead, HR signature and standard India appointment letter.</p>
                    </div>
                </div>
                <div className="flex rounded-xl bg-slate-100 p-1">
                    <span className="rounded-lg bg-blue-700 px-4 py-1.5 text-sm font-medium text-white">India</span>
                    <Link className="rounded-lg px-4 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900" href={route('hrms.offer-letters.templates', { country: 'usa' })}>USA</Link>
                </div>
            </div>
            {notice && <p className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">{notice}</p>}
            <div className="mb-5 flex flex-wrap gap-1 border-b border-slate-200">
                <Tab icon={<Building2 className="size-4" />} active={section === 'company'} onClick={() => setSection('company')}>Company Details</Tab>
                <Tab icon={<ImageIcon className="size-4" />} active={section === 'letterhead'} onClick={() => setSection('letterhead')}>Letterhead</Tab>
                <Tab icon={<PenLine className="size-4" />} active={section === 'signature'} onClick={() => setSection('signature')}>HR Signature</Tab>
                <Tab icon={<FileText className="size-4" />} active={section === 'letter'} onClick={() => setSection('letter')}>Appointment Letter</Tab>
            </div>
            {section === 'company' && (
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(17rem,0.85fr)]">
                    <CompanyForm legal={legal} verified={canVerifyLegal} />
                    <div className="grid gap-4">
                        <LetterheadSummary item={active} onManage={() => setSection('letterhead')} />
                        <SignatureSummary legal={legal} onManage={() => setSection('signature')} />
                        <LetterSummary template={current} statusLabel={status.label} tone={status.tone} />
                    </div>
                </div>
            )}
            {section === 'letterhead' && <LetterheadSection active={active} letterheads={letterheads} identity={identity} />}
            {section === 'signature' && <SignatureSection legal={legal} />}
            {section === 'letter' && (
                <LetterSection
                    template={current}
                    history={templateHistory}
                    approvalRequired={approvalRequired}
                    canConfigure={canConfigure}
                    actorId={actorId}
                />
            )}
        </HrmsLayout>
    );
}

function Tab({ active, onClick, icon, children }: { active: boolean; onClick: () => void; icon: ReactNode; children: string }) {
    return (
        <button type="button" className={`inline-flex items-center gap-2 border-b-2 px-2.5 py-3 text-sm font-medium ${active ? 'border-blue-700 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`} aria-current={active ? 'page' : undefined} onClick={onClick}>
            {icon}
            {children}
        </button>
    );
}

function Badge({ tone, children }: { tone: 'green' | 'amber' | 'blue' | 'slate'; children: ReactNode }) {
    const tones = { green: 'bg-emerald-50 text-emerald-700', amber: 'bg-amber-50 text-amber-800', blue: 'bg-blue-50 text-blue-700', slate: 'bg-slate-100 text-slate-600' };

    return <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${tones[tone]}`}>{children}</span>;
}

function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
    return <section className={`rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ${className}`}>{children}</section>;
}

function CompanyForm({ legal, verified }: { legal: Legal | null; verified: boolean }) {
    const form = useForm({
        country: 'india',
        legal_name: legal?.legalName ?? '',
        legal_address: legal?.legalAddress ?? '',
        registration_details: legal?.registrationDetails ?? '',
        signatory_name: legal?.signatoryName ?? '',
        signatory_designation: legal?.signatoryDesignation ?? '',
        hr_email: legal?.hrEmail ?? '',
    });
    const statusKey = legal?.statusKey ?? (legal?.status === 'verified' ? 'verified' : 'draft');
    const statusLabel = legal?.statusLabel ?? (statusKey === 'verified' ? 'Verified' : statusKey === 'pending_verification' ? 'Pending Verification' : 'Draft');
    const statusCopy = statusKey === 'verified'
        ? 'These legal details were verified by someone other than the person who saved them.'
        : statusKey === 'pending_verification'
            ? 'Saved and waiting for a different authorized person. Saving does not verify them.'
            : 'Required legal details are still incomplete.';

    return (
        <Card>
            <div className="mb-4 flex items-start gap-3">
                <span className="flex size-9 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><Building2 className="size-4" /></span>
                <div>
                    <h2 className="text-base font-semibold text-slate-950">Company Details</h2>
                    <p className="text-sm text-slate-500">Legal information used in the India appointment letter.</p>
                </div>
            </div>
            <form className="grid gap-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.offer-letters.legal')); }}>
                <label className="text-sm font-medium text-slate-700">Legal company name
                    <input className={field} value={form.data.legal_name} onChange={(event) => form.setData('legal_name', event.target.value)} />
                    {form.errors.legal_name && <span className="mt-1 block font-normal text-rose-700">{form.errors.legal_name}</span>}
                </label>
                <label className="text-sm font-medium text-slate-700">Registration details
                    <input className={field} placeholder="CIN / Registration No. (optional)" value={form.data.registration_details} onChange={(event) => form.setData('registration_details', event.target.value)} />
                </label>
                <label className="text-sm font-medium text-slate-700 sm:col-span-2">Registered address
                    <textarea className={`${field} min-h-24`} value={form.data.legal_address} onChange={(event) => form.setData('legal_address', event.target.value)} />
                    {form.errors.legal_address && <span className="mt-1 block font-normal text-rose-700">{form.errors.legal_address}</span>}
                </label>
                <label className="text-sm font-medium text-slate-700">Authorized signatory
                    <input className={field} value={form.data.signatory_name} onChange={(event) => form.setData('signatory_name', event.target.value)} />
                    {form.errors.signatory_name && <span className="mt-1 block font-normal text-rose-700">{form.errors.signatory_name}</span>}
                </label>
                <label className="text-sm font-medium text-slate-700">Signatory designation
                    <input className={field} value={form.data.signatory_designation} onChange={(event) => form.setData('signatory_designation', event.target.value)} />
                </label>
                <label className="text-sm font-medium text-slate-700 sm:col-span-2">HR contact email
                    <input className={field} value={form.data.hr_email} onChange={(event) => form.setData('hr_email', event.target.value)} />
                    {form.errors.hr_email && <span className="mt-1 block font-normal text-rose-700">{form.errors.hr_email}</span>}
                </label>
                <div className="flex items-start justify-between gap-3 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 sm:col-span-2">
                    <div className="flex gap-3">
                        <Info className="mt-0.5 size-4 shrink-0 text-blue-700" />
                        <div>
                            <p className="text-sm font-medium text-slate-900">Legal verification</p>
                            <p className="text-sm text-slate-600">{statusCopy}</p>
                        </div>
                    </div>
                    <Badge tone={statusKey === 'verified' ? 'green' : statusKey === 'pending_verification' ? 'amber' : 'slate'}>{statusLabel}</Badge>
                </div>
                <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <button className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60" type="submit" disabled={form.processing}>Save Changes</button>
                    {verified && statusKey === 'pending_verification' && (
                        <button className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700" type="button" onClick={() => router.post(route('hrms.offer-letters.legal.verify'), { confirm: 'VERIFY', country: 'india' })}>Verify legal details</button>
                    )}
                    {!verified && statusKey === 'pending_verification' && <p className="text-sm text-slate-500">Another authorized person verifies these details.</p>}
                </div>
            </form>
        </Card>
    );
}

function LetterheadSummary({ item, onManage }: { item: Letterhead | null; onManage: () => void }) {
    return (
        <Card>
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="flex items-start gap-2">
                    <ImageIcon className="mt-0.5 size-4 text-blue-700" />
                    <div>
                        <h2 className="text-sm font-semibold text-slate-950">Letterhead</h2>
                        <p className="text-xs text-slate-500">One active India letterhead used on all appointment letters.</p>
                    </div>
                </div>
                {item && <Badge tone="green">Active</Badge>}
            </div>
            {item ? <LetterheadPreview item={item} /> : <p className="text-sm text-slate-500">No India letterhead is active.</p>}
            <button className="mt-3 text-sm font-medium text-blue-700" type="button" onClick={onManage}>{item ? 'Replace letterhead' : 'Upload letterhead'}</button>
        </Card>
    );
}

function LetterheadPreview({ item }: { item: Letterhead }) {
    const dimensions = item.pageWidth && item.pageHeight ? `${Math.round(item.pageWidth)} × ${Math.round(item.pageHeight)} pt` : item.pageSize;

    return (
        <div className="flex gap-3">
            <div className="flex h-28 w-[4.6rem] shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-50 shadow-sm">
                {item.mime.startsWith('image/') ? (
                    <img className="h-full w-full object-cover object-top" alt="" src={route('hrms.offer-letters.letterheads.preview', item.id)} />
                ) : (
                    <FileText className="size-5 text-slate-400" />
                )}
            </div>
            <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-950">{item.name}</p>
                {dimensions && <p className="mt-1 text-xs text-slate-500">{dimensions}</p>}
            </div>
        </div>
    );
}

function SignatureSummary({ legal, onManage }: { legal: Legal | null; onManage: () => void }) {
    const onFile = Boolean(legal?.signatureOnFile);
    const prints = Boolean(onFile && legal?.signatureActive && legal?.signatureEnabled);

    return (
        <Card>
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="flex items-start gap-2">
                    <PenLine className="mt-0.5 size-4 text-blue-700" />
                    <div>
                        <h2 className="text-sm font-semibold text-slate-950">HR Signature</h2>
                        <p className="text-xs text-slate-500">Authorized signatory’s handwritten signature and placement.</p>
                    </div>
                </div>
                <Badge tone={onFile && legal?.signatureActive ? 'green' : 'slate'}>{onFile && legal?.signatureActive ? 'Active' : 'Inactive'}</Badge>
            </div>
            <div className="flex items-center gap-3">
                <div className="flex h-14 min-w-28 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-2">
                    {onFile ? <img className="max-h-12 max-w-full object-contain" alt="HR signature" src={route('hrms.offer-letters.signature.show', { country: 'india' })} /> : <span className="text-xs text-slate-400">No image</span>}
                </div>
                <div>
                    <p className="text-sm font-medium text-slate-950">{legal?.signatoryName || 'Signatory not saved'}</p>
                    <p className="text-xs text-slate-500">{legal?.signatoryDesignation || 'Designation not saved'}</p>
                </div>
            </div>
            <div className="mt-3">
                <Badge tone={prints ? 'green' : 'amber'}>{prints ? 'Automatic placement on' : 'Automatic placement off'}</Badge>
            </div>
            <button className="mt-3 text-sm font-medium text-blue-700" type="button" onClick={onManage}>Manage signature</button>
        </Card>
    );
}

function LetterSummary({ template, statusLabel, tone }: { template: TemplateRow | null; statusLabel: string; tone: 'green' | 'amber' | 'blue' | 'slate' }) {
    return (
        <Card>
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="flex items-start gap-2">
                    <FileText className="mt-0.5 size-4 text-blue-700" />
                    <div>
                        <h2 className="text-sm font-semibold text-slate-950">Appointment Letter</h2>
                        <p className="text-xs text-slate-500">Standard India appointment letter (22 clauses).</p>
                    </div>
                </div>
                <Badge tone={tone}>{statusLabel}</Badge>
            </div>
            <p className="text-sm font-medium text-slate-950">India Appointment Letter</p>
            <p className="mt-1 text-xs leading-5 text-slate-500">The active India template is the default for new offers. Other approved templates stay available.</p>
            <p className="mt-2 text-xs text-slate-500">Last updated: {stamp(template?.updatedAt)}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                <a className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-700" href={route('hrms.offer-letters.india-preview')} target="_blank" rel="noreferrer"><Eye className="size-3.5" /> Preview Template</a>
                <Link className="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-1.5 text-sm font-medium text-white" href={route('hrms.offer-letters.templates', { country: 'india', editor: 1 })}><PenLine className="size-3.5" /> Edit Appointment Letter</Link>
            </div>
        </Card>
    );
}

function LetterheadSection({ active, letterheads, identity }: { active: Letterhead | null; letterheads: Letterhead[]; identity: Identity | null }) {
    const input = useRef<HTMLInputElement>(null);
    const [pending, setPending] = useState<File | null>(null);
    const [printedName, setPrintedName] = useState(identity?.printedName ?? '');
    const [printedAddress, setPrintedAddress] = useState(identity?.printedAddress ?? '');
    const history = letterheads.filter((item) => item.id !== active?.id);
    const mismatched = identity?.brand === 'mismatched';

    function choose(file: File | null) {
        if (!file) {
            return;
        }

        if (active) {
            setPending(file);
            return;
        }

        storeLetterhead(file, null, printedName, printedAddress);
    }

    return (
        <div className="grid gap-4">
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-slate-950">India letterhead</h2>
                        <p className="mt-1 text-sm text-slate-500">One active letterhead is used for new India letters. Replacing it keeps the previous file for audit and clears the letterhead confirmation.</p>
                    </div>
                    {active ? <Badge tone={mismatched ? 'amber' : 'green'}>{mismatched ? 'Name mismatch' : 'Active'}</Badge> : <Badge tone="slate">Not uploaded</Badge>}
                </div>
                {active ? (
                    <div className="mt-4 flex flex-wrap items-start gap-4">
                        <div className="w-full max-w-[16rem] overflow-hidden rounded-lg border border-slate-200 bg-slate-50 shadow-sm">
                            {active.mime.startsWith('image/') ? <img className="max-h-56 w-full object-contain object-top" alt={active.name} src={route('hrms.offer-letters.letterheads.preview', active.id)} /> : <div className="flex h-40 items-center justify-center text-sm text-slate-500">PDF letterhead</div>}
                        </div>
                        <div>
                            <p className="text-sm font-medium text-slate-950">{active.name}</p>
                            <p className="mt-1 text-sm text-slate-500">{active.pageWidth && active.pageHeight ? `${Math.round(active.pageWidth)} × ${Math.round(active.pageHeight)} pt` : active.pageSize}</p>
                            {identity?.printedName && <p className="mt-1 text-sm text-slate-700">Printed name: {identity.printedName}</p>}
                            {identity?.printedAddress && <p className="text-sm text-slate-500">Printed address: {identity.printedAddress}</p>}
                            {identity?.legalName && <p className="text-sm text-slate-500">Legal company: {identity.legalName}</p>}
                            {active.imageWidth && active.imageHeight && <p className="text-sm text-slate-500">{active.imageWidth} × {active.imageHeight} px</p>}
                            <div className="mt-4 flex flex-wrap gap-2">
                                <button className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => input.current?.click()}><Upload className="size-3.5" /> Replace letterhead</button>
                                <a className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm" href={route('hrms.offer-letters.letterheads.preview', active.id)} target="_blank" rel="noreferrer"><Eye className="size-3.5" /> Preview</a>
                            </div>
                        </div>
                    </div>
                ) : (
                    <button className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="button" onClick={() => input.current?.click()}><Upload className="size-3.5" /> Upload letterhead</button>
                )}
                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    <label className="text-sm font-medium text-slate-700">Printed company name
                        <input className={field} value={printedName} onChange={(event) => setPrintedName(event.target.value)} placeholder="Name visible on the letterhead" />
                    </label>
                    <label className="text-sm font-medium text-slate-700">Printed address
                        <input className={field} value={printedAddress} onChange={(event) => setPrintedAddress(event.target.value)} placeholder="Address visible on the letterhead" />
                    </label>
                </div>
                {active && (
                    <button className="mt-3 rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.letterheads.identity', active.id), { printed_legal_name: printedName, printed_address: printedAddress })}>Save printed identity</button>
                )}
                <input ref={input} className="hidden" type="file" accept="image/png,image/jpeg,application/pdf" onChange={(event) => { choose(event.target.files?.[0] ?? null); event.target.value = ''; }} />
                {active?.note && <p className="mt-3 text-sm text-amber-800">{active.note}</p>}
                {identity && !identity.confirmed && <p className={`mt-4 rounded-xl border px-4 py-3 text-sm ${mismatched ? 'border-rose-200 bg-rose-50 text-rose-950' : 'border-amber-200 bg-amber-50 text-amber-950'}`}>{identity.warning}</p>}
                {identity?.confirmed && <p className="mt-4 text-sm text-slate-600">Letterhead confirmed by {identity.confirmedBy || 'an authorized user'}{identity.confirmedAt ? ` on ${stamp(identity.confirmedAt)}` : ''}. Replacing the file clears this confirmation.</p>}
                {identity?.canConfirm && (
                    <form className="mt-3" onSubmit={(event: FormEvent) => { event.preventDefault(); router.post(route('hrms.offer-letters.letterhead-confirmation'), { country: 'india', confirm: 'CONFIRM LETTERHEAD' }); }}>
                        <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="submit">Confirm this letterhead belongs to the legal company</button>
                    </form>
                )}
                {identity && !identity.confirmed && !identity.canConfirm && <p className="mt-3 text-sm text-slate-500">A company admin confirms that this letterhead belongs to the legal company. It is not confirmed automatically.</p>}
            </Card>
            {active && <LayoutSettings item={active} />}
            <details className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary className="cursor-pointer px-5 py-4 text-sm font-medium text-slate-800">Advanced history</summary>
                <div className="border-t border-slate-100 px-5 py-4">
                    {history.length === 0 && <p className="text-sm text-slate-500">No earlier letterhead files.</p>}
                    <ul className="grid gap-2">
                        {history.map((item) => (
                            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                <span className="min-w-0 truncate text-slate-800">{item.name}</span>
                                <span className="flex items-center gap-3">
                                    <Badge tone="slate">Historical</Badge>
                                    <button className="text-blue-700" type="button" onClick={() => router.post(route('hrms.offer-letters.letterheads.select', item.id))}>Use this letterhead</button>
                                    <span className="text-slate-500">{stamp(item.uploadedAt)}</span>
                                    <a className="text-blue-700" href={route('hrms.offer-letters.letterheads.preview', item.id)} target="_blank" rel="noreferrer">View file</a>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </details>
            {pending && active && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="replace-letterhead-title">
                        <h2 id="replace-letterhead-title" className="text-base font-semibold text-slate-950">Replace the active letterhead?</h2>
                        <p className="mt-2 text-sm leading-6 text-slate-600">{pending.name} will become the letterhead for new letters. {active.name} stays in history. This clears the letterhead confirmation. Issued PDFs are not changed.</p>
                        <div className="mt-4 flex justify-end gap-2">
                            <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => setPending(null)}>Cancel</button>
                            <button className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" type="button" onClick={() => { storeLetterhead(pending, active, printedName, printedAddress); setPending(null); }}>Replace letterhead</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

function storeLetterhead(file: File, active: Letterhead | null, printedName: string, printedAddress: string) {
    router.post(route('hrms.offer-letters.letterheads.store'), {
        country: 'india',
        letterhead: file,
        printed_legal_name: printedName,
        printed_address: printedAddress,
        margin_top: active?.marginTop ?? 110,
        margin_right: active?.marginRight ?? 54,
        margin_bottom: active?.marginBottom ?? 70,
        margin_left: active?.marginLeft ?? 54,
        header_height: active?.headerHeight ?? 96,
        footer_height: active?.footerHeight ?? 72,
        page_width: active?.pageWidth ?? 612,
        page_height: active?.pageHeight ?? 792,
    }, { forceFormData: true });
}

function LayoutSettings({ item }: { item: Letterhead }) {
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
    const fields: Array<[string, keyof typeof form.data]> = [
        ['Page width', 'page_width'],
        ['Page height', 'page_height'],
        ['Header height', 'header_height'],
        ['Footer height', 'footer_height'],
        ['Body start', 'margin_top'],
        ['Bottom margin', 'margin_bottom'],
        ['Left margin', 'margin_left'],
        ['Right margin', 'margin_right'],
    ];

    return (
        <details className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <summary className="cursor-pointer px-5 py-4 text-sm font-medium text-slate-800">Advanced layout settings</summary>
            <form className="grid gap-3 border-t border-slate-100 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.offer-letters.letterheads.layout', item.id)); }}>
                {fields.map(([label, key]) => (
                    <label key={key} className="text-sm text-slate-700">{label}
                        <input className={field} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} />
                    </label>
                ))}
                <div className="sm:col-span-2 lg:col-span-4">
                    <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="submit" disabled={form.processing}>Save layout</button>
                </div>
            </form>
        </details>
    );
}

function SignatureSection({ legal }: { legal: Legal | null }) {
    const file = useRef<HTMLInputElement>(null);
    const onFile = Boolean(legal?.signatureOnFile);
    const form = useForm({
        enabled: legal?.signatureEnabled ?? false,
        width: String(legal?.signatureWidth ?? 140),
        height: String(legal?.signatureHeight ?? 48),
        align: legal?.signatureAlign ?? 'left',
        gap_before: String(legal?.signatureGapBefore ?? 8),
        gap_after: String(legal?.signatureGapAfter ?? 6),
    });
    const upload = useForm<{ signature: File | null; country: string }>({ signature: null, country: 'india' });
    const width = Number(form.data.width) || 140;
    const height = Number(form.data.height) || 48;
    const savedPlacement = Boolean(onFile && legal?.signatureActive && legal?.signatureEnabled);

    return (
        <div className="grid items-start gap-4 lg:grid-cols-2">
            <Card>
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-slate-950">HR signature</h2>
                        <p className="mt-1 text-sm text-slate-500">The handwritten image is placed in the closing only. It is not used on the employee acceptance line.</p>
                    </div>
                    <Badge tone={onFile && legal?.signatureActive ? 'green' : 'slate'}>{onFile && legal?.signatureActive ? 'Active' : 'Inactive'}</Badge>
                </div>
                <div className="mt-4 flex h-24 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-4">
                    {onFile ? <img className="max-h-16 max-w-full object-contain" alt="Uploaded HR signature" src={route('hrms.offer-letters.signature.show', { country: 'india' })} /> : <p className="text-sm text-slate-500">No signature image is on file.</p>}
                </div>
                {onFile && legal?.signatureImageWidth && legal.signatureImageHeight && <p className="mt-2 text-xs text-slate-500">{legal.signatureImageWidth} × {legal.signatureImageHeight} px. The image keeps its own shape.</p>}
                {!onFile && <p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">No signature image is on file. The signature will not print.</p>}
                {onFile && !legal?.signatureActive && <p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">This signature is inactive. The signature will not print.</p>}
                <div className="mt-4 flex flex-wrap gap-2">
                    <button className="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" type="button" onClick={() => file.current?.click()}><Upload className="size-3.5" /> {onFile ? 'Replace signature' : 'Upload signature'}</button>
                    {onFile && <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.signature.active'), { country: 'india', active: legal?.signatureActive ? 0 : 1 })}>{legal?.signatureActive ? 'Deactivate' : 'Activate'}</button>}
                    {onFile && <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600" type="button" onClick={() => { if (window.confirm('Remove the signature from new letters? Issued PDFs keep the image they were generated with.')) router.post(route('hrms.offer-letters.signature.remove'), { country: 'india' }); }}>Remove</button>}
                </div>
                <input ref={file} className="hidden" type="file" accept="image/png,image/jpeg" onChange={(event) => { const next = event.target.files?.[0] ?? null; if (next) { upload.transform((data) => ({ ...data, signature: next })); upload.post(route('hrms.offer-letters.signature.store'), { forceFormData: true }); } event.target.value = ''; }} />
                {upload.errors.signature && <p className="mt-2 text-sm text-rose-700">{upload.errors.signature}</p>}
            </Card>
            <Card>
                <h2 className="text-base font-semibold text-slate-950">Placement preview</h2>
                <p className="mt-1 text-sm text-slate-500">This shows where the signature sits in the closing. It is not printed unless automatic placement is saved as on.</p>
                {!savedPlacement && <p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">{onFile && legal?.signatureActive ? 'Automatic placement is off. The signature will not print until placement is saved.' : 'The signature will not print.'}</p>}
                {savedPlacement && <p className="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">Automatic placement is on. New letters print this signature above the signatory name.</p>}
                <div className="mt-4 rounded-xl border border-slate-200 bg-white px-5 py-4 text-sm leading-6 text-slate-800">
                    <p>With warm regards,</p>
                    <p className="mt-3">For {legal?.legalName || 'the company'}</p>
                    <div style={{ textAlign: form.data.align === 'center' || form.data.align === 'right' ? form.data.align : 'left', marginTop: `${Number(form.data.gap_before) || 0}pt`, marginBottom: `${Number(form.data.gap_after) || 0}pt` }}>
                        {onFile ? (
                            <img alt="Signature placement" src={route('hrms.offer-letters.signature.show', { country: 'india' })} style={{ display: 'inline-block', maxWidth: width, maxHeight: height, width: 'auto', height: 'auto', objectFit: 'contain' }} />
                        ) : (
                            <span className="inline-flex h-12 w-36 items-center justify-center rounded border border-dashed border-slate-300 text-xs text-slate-400">Signature image</span>
                        )}
                    </div>
                    <p className="font-medium">{legal?.signatoryName || 'Signatory'}</p>
                    <p>{legal?.signatoryDesignation || 'Designation'}</p>
                </div>
                <details className="mt-4 rounded-xl border border-slate-200" open>
                    <summary className="cursor-pointer px-4 py-3 text-sm font-medium text-slate-800">Advanced placement</summary>
                    <form className="grid gap-3 border-t border-slate-100 px-4 py-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.transform((data) => ({ ...data, country: 'india', enabled: data.enabled ? 1 : 0, width: Number(data.width), height: Number(data.height), gap_before: Number(data.gap_before), gap_after: Number(data.gap_after) })); form.post(route('hrms.offer-letters.signature.settings'), { preserveScroll: true }); }}>
                        <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                            <input type="checkbox" checked={form.data.enabled} onChange={(event) => form.setData('enabled', event.target.checked)} />
                            Place the signature on generated letters
                        </label>
                        <label className="text-sm text-slate-700">Width<input className={field} value={form.data.width} onChange={(event) => form.setData('width', event.target.value)} /></label>
                        <label className="text-sm text-slate-700">Height<input className={field} value={form.data.height} onChange={(event) => form.setData('height', event.target.value)} /></label>
                        <label className="text-sm text-slate-700">Alignment
                            <select className={field} value={form.data.align} onChange={(event) => form.setData('align', event.target.value)}>
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </label>
                        <label className="text-sm text-slate-700">Space before<input className={field} value={form.data.gap_before} onChange={(event) => form.setData('gap_before', event.target.value)} /></label>
                        <label className="text-sm text-slate-700">Space after<input className={field} value={form.data.gap_after} onChange={(event) => form.setData('gap_after', event.target.value)} /></label>
                        <div className="sm:col-span-2">
                            <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save placement'}</button>
                            {Object.values(form.errors)[0] && <p className="mt-2 text-sm text-rose-700">{Object.values(form.errors)[0]}</p>}
                        </div>
                    </form>
                </details>
            </Card>
        </div>
    );
}

function LetterSection({ template, history, approvalRequired, canConfigure, actorId }: { template: TemplateRow | null; history: HistoryRow[]; approvalRequired: boolean; canConfigure: boolean; actorId: number }) {
    const status = templateStatus(template?.status ?? 'draft');
    const older = history.filter((item) => !template || item.templateId !== template.id || item.version !== template.version);
    const canApprove = template?.status === 'submitted' && template.createdBy !== actorId && template.submittedBy !== actorId;
    const canActivate = canConfigure && template?.status === 'approved';
    const active = template?.status === 'active' || history.some((item) => item.status === 'active' && item.templateId === template?.id);

    return (
        <div className="grid gap-4">
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-slate-950">India Appointment Letter</h2>
                        <p className="mt-1 max-w-2xl text-sm text-slate-500">Named India templates stay available after approval. One active template is the default for new offers. The person who prepared a version cannot approve it.</p>
                    </div>
                    <Badge tone={status.tone}>{status.label}</Badge>
                </div>
                {!active && <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">The India Appointment Letter must be approved and activated before a new offer can use it.</p>}
                <p className="mt-4 text-sm text-slate-700">{status.detail}</p>
                <p className="mt-2 text-sm text-slate-500">Last updated: {stamp(template?.updatedAt)}</p>
                <p className="mt-2 text-sm text-slate-500">{approvalRequired ? 'A different authorized person approves an offer before its PDF is generated.' : 'Authorized HR staff can generate an India appointment PDF directly.'}</p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <a className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm" href={route('hrms.offer-letters.india-preview')} target="_blank" rel="noreferrer"><Eye className="size-3.5" /> Preview Template</a>
                    <Link className="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" href={route('hrms.offer-letters.templates', { country: 'india', editor: 1 })}><PenLine className="size-3.5" /> Edit Appointment Letter</Link>
                    {template?.status === 'draft' && <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.submit', template.id))}>Submit for approval</button>}
                    {canApprove && template && <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.approve', template.id), { confirm: 'APPROVE' })}>Approve</button>}
                    {canActivate && template && <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.activate', template.id), { confirm: 'ACTIVATE' })}>Activate</button>}
                </div>
                {template?.status === 'submitted' && !canApprove && <p className="mt-3 text-sm text-slate-500">You prepared this version, so another authorized person has to approve it.</p>}
            </Card>
            <details className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary className="cursor-pointer px-5 py-4 text-sm font-medium text-slate-800">Advanced history</summary>
                <div className="border-t border-slate-100 px-5 py-4">
                    {older.length === 0 && <p className="text-sm text-slate-500">No earlier template copies.</p>}
                    <ul className="grid gap-2">
                        {older.map((item) => (
                            <li key={item.versionId} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                <span className="text-slate-800">{item.name}</span>
                                <span className="flex items-center gap-3 text-slate-500">
                                    <Badge tone="slate">{item.templateStatus === 'archived' || item.status === 'archived' ? 'Historical' : templateStatus(item.status).label}</Badge>
                                    <span>{stamp(item.updatedAt)}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </details>
            {canConfigure && (
                <details className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <summary className="cursor-pointer px-5 py-4 text-sm font-medium text-slate-800">Offer approval</summary>
                    <form className="border-t border-slate-100 px-5 py-4" onSubmit={(event) => { event.preventDefault(); router.post(route('hrms.offer-letters.approval'), { required: approvalRequired ? 0 : 1 }); }}>
                        <p className="text-sm text-slate-600">{approvalRequired ? 'Approval is required. Existing offers are not changed by this setting.' : 'Approval is off. Existing offers are not changed by this setting.'}</p>
                        <button className="mt-3 rounded-lg border border-slate-200 px-3 py-2 text-sm" type="submit">{approvalRequired ? 'Turn approval off' : 'Turn approval on'}</button>
                    </form>
                </details>
            )}
        </div>
    );
}
