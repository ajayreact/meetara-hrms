import { HrmsLayout } from '@/components/hrms/hrms-layout';
import { FileUpload } from '@/components/ui/file-upload';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { Building2, Eye, FileText, ImageIcon, PenLine, Upload } from 'lucide-react';
import { FormEvent, ReactNode, useEffect, useState } from 'react';

interface Designation {
    id: number;
    name: string;
    group: string;
    groupKey: string;
    active: boolean;
    used: boolean;
    count: number;
    responsibilities: string[];
}

interface StationeryState {
    letterhead: boolean;
    letterheadName: string;
    signature: boolean;
    signatureName: string;
}

interface CompanyProfile {
    company_name?: string;
    address_line?: string;
    city_line?: string;
    country_line?: string;
    website?: string;
    email?: string;
    phone?: string;
    e_verify?: string;
    federal_id?: string;
    manager_name?: string;
    manager_title?: string;
    manager_email?: string;
    manager_phone?: string;
    hr_name?: string;
    hr_title?: string;
    hr_phone?: string;
}

interface AppointmentTemplate {
    subject: string;
    opening: string;
    position: string;
    compensation: string;
    working_hours: string;
    work_arrangement: string;
    joining_date: string;
    employment_type: string;
    conditions: string;
    closing_paragraph: string;
    responsibilities_heading: string;
    reporting: string;
    closing: string;
    acceptance: string;
}

type Section = 'company' | 'letterhead' | 'signature' | 'letter';

const field = 'mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100';
const sections: Section[] = ['company', 'letterhead', 'signature', 'letter'];

const placeholders = [
    ['{{company_name}}', 'Company name'],
    ['{{federal_id}}', 'Federal ID (EIN)'],
    ['{{designation}}', 'Position on the offer'],
    ['{{pay_phrase}}', 'Hourly or annual pay wording'],
    ['{{pay_amount}}', 'Pay amount on the offer'],
    ['{{working_hours}}', 'Working hours on the offer'],
    ['{{work_arrangement}}', 'Work arrangement on the offer'],
    ['{{start_date}}', 'Joining date on the offer'],
    ['{{university}}', 'University on the offer'],
    ['{{employment_type}}', 'Employment type on the offer'],
    ['{{candidate_name}}', 'Candidate name'],
    ['{{manager}}', 'Reporting manager and title'],
    ['{{manager_email}}', 'Manager email'],
    ['{{manager_phone}}', 'Manager phone'],
] as const;

export default function UsaOfferSettings({
    workspace,
    designations = [],
    groups = {},
    stationery,
    profile,
    appointment,
    appointmentCustomized = false,
    tab = 'company',
    notice,
}: HrmsPageProps & {
    designations?: Designation[];
    groups?: Record<string, string>;
    profile?: CompanyProfile;
    stationery?: StationeryState;
    appointment?: Partial<AppointmentTemplate>;
    appointmentCustomized?: boolean;
    tab?: string;
    notice?: string;
}) {
    const initial = sections.includes(tab as Section) ? (tab as Section) : 'company';
    const [section, setSection] = useState<Section>(initial);

    useEffect(() => {
        setSection(initial);
    }, [initial]);

    return (
        <HrmsLayout workspace={workspace} title="USA Offer Letter Settings">
            <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                        <FileText className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-sm text-slate-500">
                            <Link className="hover:text-slate-800" href={route('hrms.usa-offers')}>USA offers</Link>
                            <span className="px-1.5">/</span>
                            <span>Company offer settings</span>
                            <span className="px-1.5">/</span>
                            <span className="text-slate-700">USA</span>
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">USA Offer Letter Settings</h1>
                        <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Manage the USA company profile, letterhead, HR signature, and the standard USA appointment letter. India settings stay separate.</p>
                    </div>
                </div>
                <div className="flex rounded-xl bg-slate-100 p-1">
                    <Link className="rounded-lg px-4 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900" href={route('hrms.offer-letters.templates')}>India</Link>
                    <span className="rounded-lg bg-blue-700 px-4 py-1.5 text-sm font-medium text-white">USA</span>
                </div>
            </div>
            {notice && <p className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">{notice}</p>}
            <div className="mb-5 flex flex-wrap gap-1 border-b border-slate-200" role="tablist" aria-label="USA offer settings">
                <Tab icon={<Building2 className="size-4" />} active={section === 'company'} onClick={() => setSection('company')}>Company Details</Tab>
                <Tab icon={<ImageIcon className="size-4" />} active={section === 'letterhead'} onClick={() => setSection('letterhead')}>Letterhead</Tab>
                <Tab icon={<PenLine className="size-4" />} active={section === 'signature'} onClick={() => setSection('signature')}>HR Signature</Tab>
                <Tab icon={<FileText className="size-4" />} active={section === 'letter'} onClick={() => setSection('letter')}>Appointment Letter</Tab>
            </div>
            {section === 'company' && (
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(17rem,0.85fr)]">
                    <CompanyForm profile={profile} />
                    <div className="grid gap-4">
                        <SummaryCard icon={<ImageIcon className="mt-0.5 size-4 text-blue-700" />} title="Letterhead" copy="One USA letterhead used on new USA offers." badge={stationery?.letterhead ? 'Active' : 'Not uploaded'} tone={stationery?.letterhead ? 'green' : 'slate'} action={stationery?.letterhead ? 'Replace letterhead' : 'Upload letterhead'} onManage={() => setSection('letterhead')} />
                        <SummaryCard icon={<PenLine className="mt-0.5 size-4 text-blue-700" />} title="HR Signature" copy={profile?.hr_name || 'Signatory not saved'} badge={stationery?.signature ? 'Active' : 'Not uploaded'} tone={stationery?.signature ? 'green' : 'slate'} action="Manage signature" onManage={() => setSection('signature')} />
                        <SummaryCard icon={<FileText className="mt-0.5 size-4 text-blue-700" />} title="Appointment Letter" copy="Standard USA offer wording used on new letters." badge={appointmentCustomized ? 'Custom' : 'Current wording'} tone={appointmentCustomized ? 'blue' : 'green'} action="Edit Appointment Letter" onManage={() => setSection('letter')} />
                    </div>
                </div>
            )}
            {section === 'letterhead' && <LetterheadSection stationery={stationery} />}
            {section === 'signature' && <SignatureSection stationery={stationery} profile={profile} />}
            {section === 'letter' && <LetterSection appointment={appointment} designations={designations} groups={groups} />}
        </HrmsLayout>
    );
}

function Tab({ active, onClick, icon, children }: { active: boolean; onClick: () => void; icon: ReactNode; children: string }) {
    return (
        <button type="button" role="tab" aria-selected={active} className={`inline-flex items-center gap-2 border-b-2 px-2.5 py-3 text-sm font-medium ${active ? 'border-blue-700 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`} onClick={onClick}>
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

function SummaryCard({ icon, title, copy, badge, tone, action, onManage }: { icon: ReactNode; title: string; copy: string; badge: string; tone: 'green' | 'blue' | 'slate'; action: string; onManage: () => void }) {
    return (
        <Card>
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="flex items-start gap-2">
                    {icon}
                    <div>
                        <h2 className="text-sm font-semibold text-slate-950">{title}</h2>
                        <p className="text-xs text-slate-500">{copy}</p>
                    </div>
                </div>
                <Badge tone={tone}>{badge}</Badge>
            </div>
            <button className="text-sm font-medium text-blue-700" type="button" onClick={onManage}>{action}</button>
        </Card>
    );
}

function CompanyForm({ profile }: { profile?: CompanyProfile }) {
    const company = useForm({
        company_name: profile?.company_name ?? '',
        address_line: profile?.address_line ?? '',
        city_line: profile?.city_line ?? '',
        country_line: profile?.country_line ?? '',
        website: profile?.website ?? '',
        email: profile?.email ?? '',
        phone: profile?.phone ?? '',
        e_verify: profile?.e_verify ?? '',
        federal_id: profile?.federal_id ?? '',
        manager_name: profile?.manager_name ?? '',
        manager_title: profile?.manager_title ?? '',
        manager_email: profile?.manager_email ?? '',
        manager_phone: profile?.manager_phone ?? '',
        hr_name: profile?.hr_name ?? '',
        hr_title: profile?.hr_title ?? '',
        hr_phone: profile?.hr_phone ?? '',
    });

    return (
        <Card>
            <div className="mb-4 flex items-start gap-3">
                <span className="flex size-9 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><Building2 className="size-4" /></span>
                <div>
                    <h2 className="text-base font-semibold text-slate-950">Company Details</h2>
                    <p className="text-sm text-slate-500">Company information printed on new USA offer letters. Previously generated PDFs stay stored.</p>
                </div>
            </div>
            <form className="grid gap-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); company.post(route('hrms.usa-offers.settings.save')); }}>
                <Field label="Company name" error={company.errors.company_name}><input className={field} value={company.data.company_name} onChange={(event) => company.setData('company_name', event.target.value)} /></Field>
                <Field label="City and region" error={company.errors.city_line}><input className={field} value={company.data.city_line} onChange={(event) => company.setData('city_line', event.target.value)} /></Field>
                <Field label="Registered address" className="sm:col-span-2" error={company.errors.address_line}><textarea className={`${field} min-h-24`} value={company.data.address_line} onChange={(event) => company.setData('address_line', event.target.value)} /></Field>
                <Field label="Country" error={company.errors.country_line}><input className={field} value={company.data.country_line} onChange={(event) => company.setData('country_line', event.target.value)} /></Field>
                <Field label="Website" error={company.errors.website}><input className={field} value={company.data.website} onChange={(event) => company.setData('website', event.target.value)} /></Field>
                <Field label="Email" error={company.errors.email}><input className={field} value={company.data.email} onChange={(event) => company.setData('email', event.target.value)} /></Field>
                <Field label="Phone" error={company.errors.phone}><input className={field} value={company.data.phone} onChange={(event) => company.setData('phone', event.target.value)} /></Field>
                <Field label="E-Verify" error={company.errors.e_verify}><input className={field} value={company.data.e_verify} onChange={(event) => company.setData('e_verify', event.target.value)} /></Field>
                <Field label="Federal ID (EIN)" error={company.errors.federal_id}><input className={field} value={company.data.federal_id} onChange={(event) => company.setData('federal_id', event.target.value)} /></Field>
                <Field label="Reporting manager" error={company.errors.manager_name}><input className={field} value={company.data.manager_name} onChange={(event) => company.setData('manager_name', event.target.value)} /></Field>
                <Field label="Manager title" error={company.errors.manager_title}><input className={field} value={company.data.manager_title} onChange={(event) => company.setData('manager_title', event.target.value)} /></Field>
                <Field label="Manager email" error={company.errors.manager_email}><input className={field} value={company.data.manager_email} onChange={(event) => company.setData('manager_email', event.target.value)} /></Field>
                <Field label="Manager phone" error={company.errors.manager_phone}><input className={field} value={company.data.manager_phone} onChange={(event) => company.setData('manager_phone', event.target.value)} /></Field>
                <Field label="Signatory name" error={company.errors.hr_name}><input className={field} value={company.data.hr_name} onChange={(event) => company.setData('hr_name', event.target.value)} /></Field>
                <Field label="Signatory title" error={company.errors.hr_title}><input className={field} value={company.data.hr_title} onChange={(event) => company.setData('hr_title', event.target.value)} /></Field>
                <Field label="Signatory phone" error={company.errors.hr_phone}><input className={field} value={company.data.hr_phone} onChange={(event) => company.setData('hr_phone', event.target.value)} /></Field>
                <div className="sm:col-span-2">
                    <button className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={company.processing}>Save company profile</button>
                </div>
            </form>
        </Card>
    );
}

function Field({ label, error, className = '', children }: { label: string; error?: string; className?: string; children: ReactNode }) {
    return (
        <label className={`text-sm font-medium text-slate-700 ${className}`}>{label}
            {children}
            {error && <span className="mt-1 block font-normal text-rose-700">{error}</span>}
        </label>
    );
}

function LetterheadSection({ stationery }: { stationery?: StationeryState }) {
    const letterhead = useForm<{ letterhead: File | null }>({ letterhead: null });

    function upload(event: FormEvent) {
        event.preventDefault();
        letterhead.post(route('hrms.usa-offers.settings.letterhead'), { forceFormData: true });
    }

    return (
        <Card>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-slate-950">USA letterhead</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-500">PNG, JPG, or JPEG up to 5 MB. A PDF is accepted only when this server can convert it. Replacing the file does not change offers that were already generated.</p>
                </div>
                <Badge tone={stationery?.letterhead ? 'green' : 'slate'}>{stationery?.letterhead ? 'Active' : 'Not uploaded'}</Badge>
            </div>
            {stationery?.letterhead ? (
                <div className="mt-4 flex flex-wrap items-start gap-4">
                    <div className="w-full max-w-[16rem] overflow-hidden rounded-lg border border-slate-200 bg-slate-50 shadow-sm">
                        <img className="max-h-56 w-full object-contain object-top" src={route('hrms.usa-offers.settings.letterhead.preview')} alt="Current USA letterhead" />
                    </div>
                    <div>
                        <p className="text-sm font-medium text-slate-950">{stationery.letterheadName || 'Company letterhead'}</p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <a className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm" href={route('hrms.usa-offers.settings.letterhead.preview')} target="_blank" rel="noreferrer"><Eye className="size-3.5" /> Preview</a>
                        </div>
                    </div>
                </div>
            ) : (
                <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">A letterhead is required before a USA offer can be previewed or generated.</p>
            )}
            <form className="mt-4" onSubmit={upload}>
                <FileUpload accept="image/png,image/jpeg,.pdf" file={letterhead.data.letterhead} hint="PNG, JPEG, or PDF." error={letterhead.errors.letterhead} onChange={(file) => letterhead.setData('letterhead', file)} />
                {letterhead.errors.letterhead && <p className="mt-2 text-sm text-rose-700">{letterhead.errors.letterhead}</p>}
                <button className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" type="submit" disabled={letterhead.processing}><Upload className="size-3.5" />{stationery?.letterhead ? 'Replace letterhead' : 'Upload letterhead'}</button>
            </form>
        </Card>
    );
}

function SignatureSection({ stationery, profile }: { stationery?: StationeryState; profile?: CompanyProfile }) {
    const signature = useForm<{ signature: File | null }>({ signature: null });

    function upload(event: FormEvent) {
        event.preventDefault();
        signature.post(route('hrms.usa-offers.settings.signature'), { forceFormData: true });
    }

    return (
        <Card>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-slate-950">HR Signature</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-500">PNG, JPG, or JPEG up to 2 MB. It prints above the signatory line on new USA offers. Replacing it does not change stored PDFs.</p>
                </div>
                <Badge tone={stationery?.signature ? 'green' : 'slate'}>{stationery?.signature ? 'Active' : 'Not uploaded'}</Badge>
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-4">
                <div className="flex h-24 min-w-40 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-3">
                    {stationery?.signature ? <img className="max-h-20 max-w-full object-contain" src={route('hrms.usa-offers.settings.signature.preview')} alt="Current HR signature" /> : <span className="text-sm text-slate-400">No signature</span>}
                </div>
                <div>
                    <p className="text-sm font-medium text-slate-950">{profile?.hr_name || 'Signatory not saved'}</p>
                    <p className="text-xs text-slate-500">{profile?.hr_title || 'Title not saved'}</p>
                    {stationery?.signatureName && <p className="mt-1 text-xs text-slate-500">{stationery.signatureName}</p>}
                </div>
            </div>
            {!stationery?.signature && <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">An HR signature is required before a USA offer can be previewed or generated.</p>}
            <form className="mt-4" onSubmit={upload}>
                <FileUpload accept="image/png,image/jpeg" file={signature.data.signature} hint="PNG or JPEG." error={signature.errors.signature} onChange={(file) => signature.setData('signature', file)} />
                {signature.errors.signature && <p className="mt-2 text-sm text-rose-700">{signature.errors.signature}</p>}
                <button className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" type="submit" disabled={signature.processing}><Upload className="size-3.5" />{stationery?.signature ? 'Replace signature' : 'Upload signature'}</button>
            </form>
        </Card>
    );
}

function LetterSection({ appointment, designations, groups }: { appointment?: Partial<AppointmentTemplate>; designations: Designation[]; groups: Record<string, string> }) {
    const letter = useForm<AppointmentTemplate>({
        subject: appointment?.subject ?? '',
        opening: appointment?.opening ?? '',
        position: appointment?.position ?? '',
        compensation: appointment?.compensation ?? '',
        working_hours: appointment?.working_hours ?? '',
        work_arrangement: appointment?.work_arrangement ?? '',
        joining_date: appointment?.joining_date ?? '',
        employment_type: appointment?.employment_type ?? '',
        conditions: appointment?.conditions ?? '',
        closing_paragraph: appointment?.closing_paragraph ?? '',
        responsibilities_heading: appointment?.responsibilities_heading ?? '',
        reporting: appointment?.reporting ?? '',
        closing: appointment?.closing ?? '',
        acceptance: appointment?.acceptance ?? '',
    });
    const [previewError, setPreviewError] = useState('');
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const visible = designations.filter((designation) => designation.name.toLowerCase().includes(query.trim().toLowerCase()));
    const added = useForm({ name: '', group: 'software', responsibilities: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        letter.post(route('hrms.usa-offers.settings.appointment'));
    }

    async function preview() {
        setPreviewError('');
        const body = new FormData();
        (Object.keys(letter.data) as Array<keyof AppointmentTemplate>).forEach((key) => body.append(key, letter.data[key]));
        const token = decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const response = await fetch(route('hrms.usa-offers.settings.appointment.preview'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-XSRF-TOKEN': token, Accept: 'application/pdf, application/json' },
            body,
        });

        if (!response.ok || !response.headers.get('content-type')?.includes('application/pdf')) {
            setPreviewError('Save the company name, address, federal ID, letterhead, and HR signature before previewing.');

            return;
        }

        const url = URL.createObjectURL(await response.blob());
        window.open(url, '_blank', 'noopener');
    }

    return (
        <div className="grid gap-4">
            <Card>
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <span className="flex size-9 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><FileText className="size-4" /></span>
                        <div>
                            <h2 className="text-base font-semibold text-slate-950">USA Appointment Letter</h2>
                            <p className="mt-1 max-w-2xl text-sm text-slate-500">These fields start as the wording already used on USA offers. Placeholders fill in company and candidate details. Saving changes new letters only.</p>
                        </div>
                    </div>
                </div>
                <form className="grid gap-4" onSubmit={save}>
                    <Editor label="Offer letter subject/title" hint="Leave blank to keep the current letter, which has no subject line." value={letter.data.subject} error={letter.errors.subject} onChange={(value) => letter.setData('subject', value)} />
                    <Editor label="Opening paragraph" hint="Includes the company, EIN, and position. Use {{designation}} for the position on each offer." value={letter.data.opening} error={letter.errors.opening} onChange={(value) => letter.setData('opening', value)} rows={4} />
                    <Editor label="Employment position and job description" hint="Leave blank to keep the current letter. Text saved here is an extra paragraph on new letters. Duty bullets still come from each offer." value={letter.data.position} error={letter.errors.position} onChange={(value) => letter.setData('position', value)} rows={3} />
                    <div className="grid gap-4 md:grid-cols-2">
                        <Editor label="Compensation and payment terms" value={letter.data.compensation} error={letter.errors.compensation} onChange={(value) => letter.setData('compensation', value)} />
                        <Editor label="Employment type" hint="Printed only when the offer has an employment type." value={letter.data.employment_type} error={letter.errors.employment_type} onChange={(value) => letter.setData('employment_type', value)} />
                        <Editor label="Working hours" value={letter.data.working_hours} error={letter.errors.working_hours} onChange={(value) => letter.setData('working_hours', value)} />
                        <Editor label="Work location and remote/hybrid terms" value={letter.data.work_arrangement} error={letter.errors.work_arrangement} onChange={(value) => letter.setData('work_arrangement', value)} />
                        <Editor label="Joining date" value={letter.data.joining_date} error={letter.errors.joining_date} onChange={(value) => letter.setData('joining_date', value)} />
                        <Editor label="Employee responsibilities" hint="Heading above the bullets saved on each offer." value={letter.data.responsibilities_heading} error={letter.errors.responsibilities_heading} onChange={(value) => letter.setData('responsibilities_heading', value)} />
                    </div>
                    <Editor label="Employment conditions" value={letter.data.conditions} error={letter.errors.conditions} onChange={(value) => letter.setData('conditions', value)} rows={3} />
                    <Editor label="Closing paragraph" value={letter.data.closing_paragraph} error={letter.errors.closing_paragraph} onChange={(value) => letter.setData('closing_paragraph', value)} rows={3} />
                    <Editor label="Reporting manager" hint="The default wording leaves out a missing manager email or phone. A rewritten sentence prints the placeholders you keep." value={letter.data.reporting} error={letter.errors.reporting} onChange={(value) => letter.setData('reporting', value)} rows={3} />
                    <Editor label="Authorized signatory" hint="Prints above the saved signature image. The signatory name, title, and phone come from Company Details." value={letter.data.closing} error={letter.errors.closing} onChange={(value) => letter.setData('closing', value)} />
                    <Editor label="Employee acceptance line" value={letter.data.acceptance} error={letter.errors.acceptance} onChange={(value) => letter.setData('acceptance', value)} rows={2} />
                    <div className="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-600">
                        <p className="font-medium text-slate-700">Placeholders</p>
                        <ul className="mt-2 grid gap-1 sm:grid-cols-2">
                            {placeholders.map(([token, label]) => <li key={token}><span className="font-medium text-slate-800">{token}</span> {label}</li>)}
                        </ul>
                    </div>
                    {(letter.errors as Record<string, string | undefined>).appointment && <p className="text-sm text-rose-700">{(letter.errors as Record<string, string | undefined>).appointment}</p>}
                    {previewError && <p className="text-sm text-rose-700">{previewError}</p>}
                    <div className="flex flex-wrap gap-2">
                        <button className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={letter.processing}>Save appointment letter</button>
                        <button className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700" type="button" onClick={preview}><Eye className="size-3.5" /> Preview template</button>
                    </div>
                </form>
            </Card>
            <Card>
                <h2 className="text-base font-semibold text-slate-950">Designations</h2>
                <p className="mt-1 text-sm text-slate-500">Default duties for new offers. Changing a designation does not change an offer that was already saved.</p>
                <input className={field} placeholder="Search designations" value={query} onChange={(event) => setQuery(event.target.value)} />
                <p className="mt-3 text-sm text-slate-600">{visible.length} shown. Used designations stay on file and can be deactivated.</p>
                <ul className="mt-3 divide-y divide-slate-100">
                    {visible.map((designation) => (
                        <DesignationRow key={designation.id} designation={designation} open={open === designation.id} onOpen={() => setOpen(open === designation.id ? null : designation.id)} />
                    ))}
                </ul>
            </Card>
            <form onSubmit={(event) => { event.preventDefault(); added.post(route('hrms.usa-offers.designations.add')); }}>
                <Card>
                    <h2 className="text-base font-semibold text-slate-950">Add a designation</h2>
                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                        <Field label="Name" error={added.errors.name}><input className={field} value={added.data.name} onChange={(event) => added.setData('name', event.target.value)} /></Field>
                        <label className="text-sm font-medium text-slate-700">Group
                            <select className={field} value={added.data.group} onChange={(event) => added.setData('group', event.target.value)}>
                                {Object.entries(groups).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </select>
                        </label>
                    </div>
                    <Field label="Default responsibilities" className="mt-3 block"><textarea className={field} rows={5} value={added.data.responsibilities} onChange={(event) => added.setData('responsibilities', event.target.value)} /></Field>
                    <button className="mt-3 rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700" type="submit" disabled={added.processing}>Add designation</button>
                </Card>
            </form>
        </div>
    );
}

function Editor({ label, hint, value, error, onChange, rows = 2 }: { label: string; hint?: string; value: string; error?: string; onChange: (value: string) => void; rows?: number }) {
    return (
        <label className="text-sm font-medium text-slate-700">{label}
            {hint && <span className="mt-1 block font-normal text-slate-500">{hint}</span>}
            <textarea className={field} rows={rows} value={value} onChange={(event) => onChange(event.target.value)} />
            {error && <span className="mt-1 block font-normal text-rose-700">{error}</span>}
        </label>
    );
}

function DesignationRow({ designation, open, onOpen }: { designation: Designation; open: boolean; onOpen: () => void }) {
    const form = useForm({
        responsibilities: designation.responsibilities.join('\n'),
        active: designation.active ? 1 : 0,
    });

    function save(event: FormEvent) {
        event.preventDefault();
        form.post(route('hrms.usa-offers.designations.update', designation.id));
    }

    return (
        <li className="py-3">
            <button className="flex w-full items-center justify-between gap-3 text-left" type="button" onClick={onOpen}>
                <span>
                    <span className="block text-sm font-medium text-slate-950">{designation.name}</span>
                    <span className="text-xs text-slate-500">{designation.group} · {designation.count} responsibilities{designation.used ? ' · used by an offer' : ''}{designation.active ? '' : ' · inactive'}</span>
                </span>
                <span className="text-xs text-blue-700">{open ? 'Hide' : 'View'}</span>
            </button>
            {open && (
                <form className="mt-3" onSubmit={save}>
                    <textarea className={field} rows={8} value={form.data.responsibilities} onChange={(event) => form.setData('responsibilities', event.target.value)} />
                    <label className="mt-2 flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={Boolean(form.data.active)} onChange={(event) => form.setData('active', event.target.checked ? 1 : 0)} />
                        Active
                    </label>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button className="rounded-lg bg-blue-700 px-3 py-1.5 text-sm text-white" type="submit">Save default</button>
                        <button className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm" type="button" onClick={() => router.post(route('hrms.usa-offers.designations.restore', designation.id))}>Restore default</button>
                    </div>
                </form>
            )}
        </li>
    );
}
