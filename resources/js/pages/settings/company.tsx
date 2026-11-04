import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import LetterStationery, { type Stationery } from './letter-stationery';

interface PayrollSettings {
    currency: string | null;
    currencyRule: string;
    period: string;
    periodNote: string;
    statutory: string;
    statutoryNote: string;
    unpaidLeave: string;
    unpaidLeaveNote: string;
    attendanceDeduction: string;
    attendanceNote: string;
    proration: string;
    prorationNote: string;
    salaryVisibility: string;
    salaryVisibilityNote: string;
}

interface GpsCaptureSettings {
    ready: boolean;
    enabled: boolean;
    notice: string;
}

interface CandidateFormSettings {
    ready: boolean;
    enabled: boolean;
    canEnable: boolean;
    publicUrl: string | null;
    notice: string;
}

interface I983EmployerProfile {
    ready: boolean;
    saved: boolean;
    legalName: string;
    website: string;
    street: string;
    suite: string;
    city: string;
    state: string;
    zip: string;
    ein: string;
    employeeCount: string;
    naics: string;
    oversight: string;
    measures: string;
}

interface I983Signatory {
    name: string;
    title: string;
    hasImage: boolean;
}

export default function CompanySettingsPage({ workspace, payrollSettings = null, gpsCapture = null, candidateForm = null, stationery = null, i983Employer = null, i983Signatory = null, notice, error }: HrmsPageProps & { payrollSettings?: PayrollSettings | null; gpsCapture?: GpsCaptureSettings | null; candidateForm?: CandidateFormSettings | null; stationery?: Stationery | null; i983Employer?: I983EmployerProfile | null; i983Signatory?: I983Signatory | null; notice?: string | null; error?: string | null }) {
    const platform = workspace.role === 'super_admin';
    const companyName = workspace.companyName ?? 'Not recorded';

    return (
        <HrmsLayout workspace={workspace} title="Settings">
            <PageIntro title={platform ? 'Platform settings' : 'Company settings'} subtitle={platform ? 'No platform-wide settings are stored. Company payroll settings stay on each company account.' : 'The company name comes from the company record and cannot be renamed here. Payroll currency is saved separately and does not change existing salary records.'} />
            {platform ? <PlatformSettings /> : (
                <>
                    {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
                    <div className={payrollSettings ? 'grid items-start gap-4 xl:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)]' : ''}>
                        <SectionCard title="Company name">
                            <p className="text-sm font-medium text-slate-800">{companyName}</p>
                            <p className="mt-2 text-sm text-slate-500">This is the saved company name. It is shown here and is not an editable field. The display name is not verified legal information.</p>
                            <p className="mt-3 text-sm"><Link className="text-blue-700 underline" href={route('hrms.offer-letters.templates')}>Offer letter templates</Link></p>
                        </SectionCard>
                        {payrollSettings && <PayrollSettingsForm settings={payrollSettings} notice={notice} error={error} />}
                        {gpsCapture && <GpsCaptureForm settings={gpsCapture} />}
                        {candidateForm && <CandidateFormSettingsForm settings={candidateForm} />}
                    </div>
                    {i983Employer && <I983EmployerForm profile={i983Employer} />}
                    {i983Signatory && <I983SignatoryForm signatory={i983Signatory} />}
                    {stationery && <LetterStationery stationery={stationery} />}
                </>
            )}
        </HrmsLayout>
    );
}

function PlatformSettings() {
    const areas = [
        ['Company directory', '/companies', 'Review saved companies, status, headcount, and the Head of Operations account.'],
        ['Event history', '/audit', 'Read workflow events. Notes that could contain pay amounts are not shown.'],
        ['Ticket desk', '/help-desk', 'Company tickets are not listed on a platform account.'],
    ];
    const unavailable = [
        'Billing and subscription management are not connected.',
        'Employee GPS capture stays off until a company admin enables it. Platform accounts do not receive those coordinates.',
        'Email, SMS, and push delivery are not connected.',
        'Monitoring, backup verification, and queue health are not connected.',
        'Environment, database, and session configuration cannot be changed here.',
    ];

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <SectionCard divided title="Platform administration">
                <ul className="space-y-3">
                    {areas.map(([label, href, detail]) => (
                        <li key={href}>
                            <Link href={href} className="text-sm font-medium text-blue-700">{label}</Link>
                            <p className="mt-1 text-sm text-slate-500">{detail}</p>
                        </li>
                    ))}
                </ul>
            </SectionCard>
            <SectionCard divided title="Not available">
                <ul className="space-y-2 text-sm text-slate-600">
                    {unavailable.map((item) => <li key={item}>{item}</li>)}
                </ul>
            </SectionCard>
        </div>
    );
}

function PayrollSettingsForm({ settings, notice, error }: { settings: PayrollSettings; notice?: string | null; error?: string | null }) {
    const form = useForm({ currency: settings.currency ?? '' });
    const rules = [
        ['Pay period', settings.period, settings.periodNote],
        ['Statutory deductions', settings.statutory, settings.statutoryNote],
        ['Unpaid leave', settings.unpaidLeave, settings.unpaidLeaveNote],
        ['Attendance deduction', settings.attendanceDeduction, settings.attendanceNote],
        ['Mid-month joining', settings.proration, settings.prorationNote],
        ['Salary visibility', settings.salaryVisibility, settings.salaryVisibilityNote],
    ];

    return (
            <SectionCard title="Payroll settings">
                {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
                <div className="grid gap-6 lg:grid-cols-[minmax(0,20rem)_1fr]">
                <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); form.put('/company/settings/payroll'); }}>
                    <label className="grid gap-1.5 text-sm">
                        <Label htmlFor="payroll-currency">Default currency for a new salary record</Label>
                        <Input id="payroll-currency" maxLength={3} value={form.data.currency} onChange={(event) => form.setData('currency', event.target.value.toUpperCase())} placeholder="Leave blank to require a currency each time" />
                        <InputError message={form.errors.currency} />
                    </label>
                    <p className="text-xs text-slate-500">{settings.currencyRule}</p>
                    <Button type="submit" disabled={form.processing}>{form.processing ? 'Working…' : 'Save currency default'}</Button>
                </form>
                <ul className="space-y-3 text-sm">
                    {rules.map(([label, status, detail]) => (
                        <li key={label}>
                            <p className="font-medium text-slate-800">{label}: {status}</p>
                            <p className="text-slate-500">{detail}</p>
                        </li>
                    ))}
                </ul>
                </div>
            </SectionCard>
    );
}

function CandidateFormSettingsForm({ settings }: { settings: CandidateFormSettings }) {
    const form = useForm({ enabled: settings.enabled, confirm_disable: false });
    const rotation = useForm({ confirm_rotate: false });
    const [copied, setCopied] = useState(false);

    return (
        <SectionCard title="Public candidate form" className="xl:col-span-2">
            <p className="text-sm leading-6 text-slate-600">{settings.notice}</p>
            {!settings.ready && <p className="mt-3 text-sm text-amber-800">Candidate form storage is not ready on this database, so the setting cannot be saved.</p>}
            {settings.ready && !settings.canEnable && <p className="mt-3 text-sm text-amber-800">The form cannot be enabled until malware scanning and the verification check are configured.</p>}
            {settings.enabled && settings.publicUrl && (
                <div className="mt-4 grid gap-2">
                    <Label htmlFor="candidate-public-url">Public form link</Label>
                    <div className="flex flex-wrap gap-2">
                        <Input id="candidate-public-url" readOnly value={settings.publicUrl} className="max-w-xl" />
                        <Button type="button" variant="outline" onClick={() => { void navigator.clipboard.writeText(settings.publicUrl ?? '').then(() => setCopied(true)); }}>{copied ? 'Copied' : 'Copy link'}</Button>
                    </div>
                </div>
            )}
            <form className="mt-4 grid gap-3" onSubmit={(event) => { event.preventDefault(); form.put(route('hrms.company-settings.candidate-form')); }}>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.enabled} onChange={(event) => form.setData('enabled', event.target.checked)} disabled={!settings.ready || (!settings.enabled && !settings.canEnable)} />
                    Accept new candidate submissions
                </label>
                {settings.enabled && !form.data.enabled && (
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" checked={form.data.confirm_disable} onChange={(event) => form.setData('confirm_disable', event.target.checked)} />
                        <span>Stop new submissions. Existing submissions and documents stay available to HR.</span>
                    </label>
                )}
                <InputError message={form.errors.confirm_disable} />
                <Button type="submit" disabled={form.processing || !settings.ready || (settings.enabled && !form.data.enabled && !form.data.confirm_disable)}>{form.processing ? 'Working…' : 'Save candidate form'}</Button>
            </form>
            <form className="mt-4 grid gap-3 border-t border-slate-200 pt-4" onSubmit={(event) => { event.preventDefault(); rotation.post(route('hrms.company-settings.candidate-form.rotate')); }}>
                <p className="text-sm text-slate-600">Replacing the public link makes the current link stop opening the form. Submissions already received stay in this company.</p>
                <label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" checked={rotation.data.confirm_rotate} onChange={(event) => rotation.setData('confirm_rotate', event.target.checked)} disabled={!settings.ready} />
                    <span>I understand the current public link will stop working.</span>
                </label>
                <InputError message={rotation.errors.confirm_rotate} />
                <Button type="submit" variant="outline" disabled={rotation.processing || !settings.ready || !rotation.data.confirm_rotate}>{rotation.processing ? 'Working…' : 'Replace public link'}</Button>
            </form>
        </SectionCard>
    );
}

function I983EmployerForm({ profile }: { profile: I983EmployerProfile }) {
    const form = useForm({
        legal_name: profile.legalName,
        website: profile.website,
        street: profile.street,
        suite: profile.suite,
        city: profile.city,
        state: profile.state,
        zip: profile.zip,
        ein: profile.ein,
        employee_count: profile.employeeCount,
        naics: profile.naics,
        oversight: profile.oversight,
        measures: profile.measures,
    });
    const fields: Array<[keyof typeof form.data, string, string]> = [
        ['legal_name', 'Legal employer name', 'Veltrix Digital Solutions Inc'],
        ['website', 'Website', 'https://veltrixds.com'],
        ['street', 'Street address', '37068 Grand River Ave'],
        ['suite', 'Suite', '1105'],
        ['city', 'City', 'Farmington'],
        ['state', 'State', 'MI'],
        ['zip', 'ZIP', '48335'],
        ['ein', 'EIN', '12-3456789'],
        ['employee_count', 'Number of full-time U.S. employees', '12'],
        ['naics', 'NAICS code', '541511'],
    ];

    return (
        <SectionCard title="U.S. employer information" className="mt-4">
            <p className="text-sm leading-6 text-slate-600">These details, including the employer oversight and measures and assessments text, are copied onto each new STEM I-983. Saving here does not change an I-983 that already exists. Salary, hourly rate, working hours, start date, and the training site stay on the individual case.</p>
            {!profile.ready && <p className="mt-3 text-sm text-amber-800">Employer information storage is not ready on this database, so this record cannot be saved.</p>}
            <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.put(route('hrms.company-settings.i983-employer')); }}>
                {fields.map(([key, label, placeholder]) => (
                    <label key={key} className="grid gap-1.5 text-sm">
                        <Label htmlFor={`i983-${key}`}>{label}</Label>
                        <Input id={`i983-${key}`} value={form.data[key]} placeholder={placeholder} maxLength={key === 'state' ? 2 : undefined} autoComplete={key === 'ein' ? 'off' : undefined} onChange={(event) => form.setData(key, key === 'state' ? event.target.value.toUpperCase() : event.target.value)} />
                        <InputError message={form.errors[key]} />
                    </label>
                ))}
                <label className="grid gap-1.5 text-sm md:col-span-2">
                    <Label htmlFor="i983-oversight">Employer oversight default</Label>
                    <textarea id="i983-oversight" className="min-h-28 rounded-md border border-slate-200 px-3 py-2" value={form.data.oversight} onChange={(event) => form.setData('oversight', event.target.value)} />
                    <InputError message={form.errors.oversight} />
                </label>
                <label className="grid gap-1.5 text-sm md:col-span-2">
                    <Label htmlFor="i983-measures">Measures and assessments default</Label>
                    <textarea id="i983-measures" className="min-h-28 rounded-md border border-slate-200 px-3 py-2" value={form.data.measures} onChange={(event) => form.setData('measures', event.target.value)} />
                    <InputError message={form.errors.measures} />
                </label>
                <div className="md:col-span-2">
                    <Button type="submit" disabled={form.processing || !profile.ready}>{form.processing ? 'Working…' : 'Save employer information'}</Button>
                </div>
            </form>
        </SectionCard>
    );
}

function I983SignatoryForm({ signatory }: { signatory: I983Signatory }) {
    const form = useForm<{ signatory_name: string; signatory_title: string; signature: File | null }>({
        signatory_name: signatory.name,
        signatory_title: signatory.title,
        signature: null,
    });

    return (
        <SectionCard title="Authorized I-983 signatory" className="mt-4">
            <p className="text-sm leading-6 text-slate-600">This is the employer official for this company. A new I-983 copies the name and title. It does not use the name or email of the person who is signed in. Saving a new image does not change a PDF that is already stored, and it does not change the official name already copied onto an existing I-983.</p>
            {signatory.hasImage && <img alt="Saved employer signature" className="mt-4 h-16 rounded-lg border border-slate-200 bg-white p-2" src={route('hrms.i983.signature.show')} />}
            <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.i983.signature.save')); }}>
                <label className="grid gap-1.5 text-sm">
                    <Label htmlFor="i983-signatory-name">Signatory full name</Label>
                    <Input id="i983-signatory-name" value={form.data.signatory_name} onChange={(event) => form.setData('signatory_name', event.target.value)} />
                    <InputError message={form.errors.signatory_name} />
                </label>
                <label className="grid gap-1.5 text-sm">
                    <Label htmlFor="i983-signatory-title">Official title</Label>
                    <Input id="i983-signatory-title" value={form.data.signatory_title} onChange={(event) => form.setData('signatory_title', event.target.value)} />
                    <InputError message={form.errors.signatory_title} />
                </label>
                <label className="grid gap-1.5 text-sm md:col-span-2">
                    <Label htmlFor="i983-signatory-image">Signature image (PNG or JPEG)</Label>
                    <Input id="i983-signatory-image" type="file" accept="image/png,image/jpeg" onChange={(event) => form.setData('signature', event.target.files?.[0] ?? null)} />
                    <InputError message={form.errors.signature} />
                </label>
                <div className="md:col-span-2">
                    <Button type="submit" disabled={form.processing}>{form.processing ? 'Working…' : 'Save signatory'}</Button>
                </div>
            </form>
        </SectionCard>
    );
}

function GpsCaptureForm({ settings }: { settings: GpsCaptureSettings }) {
    const form = useForm({ enabled: settings.enabled });

    return (
        <SectionCard title="GPS attendance capture" className="xl:col-span-2">
            <p className="text-sm leading-6 text-slate-600">{settings.notice}</p>
            <p className="mt-3 text-sm text-slate-600">This switch does not replace approved public-network verification, and it does not make a browser location mandatory for clock-in.</p>
            {!settings.ready && <p className="mt-3 text-sm text-amber-800">GPS storage is not ready on this database, so the setting cannot be saved.</p>}
            <form className="mt-4 flex flex-wrap items-center gap-3" onSubmit={(event) => { event.preventDefault(); form.put(route('hrms.company-settings.gps')); }}>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.enabled} onChange={(event) => form.setData('enabled', event.target.checked)} disabled={!settings.ready} />
                    Save a browser location at clock-in and clock-out
                </label>
                <Button type="submit" disabled={form.processing || !settings.ready}>{form.processing ? 'Working…' : 'Save GPS setting'}</Button>
            </form>
        </SectionCard>
    );
}
