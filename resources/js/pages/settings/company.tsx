import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

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

export default function CompanySettingsPage({ workspace, payrollSettings = null, gpsCapture = null, notice, error }: HrmsPageProps & { payrollSettings?: PayrollSettings | null; gpsCapture?: GpsCaptureSettings | null; notice?: string | null; error?: string | null }) {
    const platform = workspace.role === 'super_admin';
    const companyName = workspace.companyName ?? 'Not recorded';

    return (
        <HrmsLayout workspace={workspace} title="Settings">
            <PageIntro title={platform ? 'Platform settings' : 'Company settings'} subtitle={platform ? 'No platform-wide settings are stored. Company payroll settings stay on each company account.' : 'The company name comes from the company record and cannot be renamed here. Payroll currency is saved separately and does not change existing salary records.'} />
            {platform ? <PlatformSettings /> : (
                <div className={payrollSettings ? 'grid items-start gap-4 xl:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)]' : ''}>
                    <SectionCard title="Company name">
                        <p className="text-sm font-medium text-slate-800">{companyName}</p>
                        <p className="mt-2 text-sm text-slate-500">This is the saved company name. It is shown here and is not an editable field.</p>
                    </SectionCard>
                    {payrollSettings && <PayrollSettingsForm settings={payrollSettings} notice={notice} error={error} />}
                    {gpsCapture && <GpsCaptureForm settings={gpsCapture} />}
                </div>
            )}
        </HrmsLayout>
    );
}

function PlatformSettings() {
    const areas = [
        ['Company directory', '/companies', 'Review saved companies, status, headcount, and the Company Admin account.'],
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
