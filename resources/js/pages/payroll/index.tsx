import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { PayrollNav } from '@/components/hrms/payroll-nav';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { CircleCheck, FileSpreadsheet, Receipt, TriangleAlert, Upload, Users } from 'lucide-react';
import { useState } from 'react';

interface Period {
    year: number | null;
    month: number | null;
    found: boolean;
    status: string | null;
    rawStatus: string | null;
    source: string | null;
    imported: number;
    validated: number;
    errors: number;
    confirmed: number;
    generated: number;
}

interface HistoryRow {
    id: number;
    period: string;
    uploadedAt: string | null;
    uploadedBy: string | null;
    employees: number;
    gross: string;
    deductions: string;
    net: string;
    importStatus: string;
    payslipStatus: string;
    legacy: boolean;
    rawStatus: string;
}

export default function PayrollOverview({ workspace, overview, history = [], canImport = false, importReady = false, notice, error }: HrmsPageProps & { overview: { currencyNote: string; period: Period }; history?: HistoryRow[]; canImport?: boolean; importReady?: boolean; notice?: string | null; error?: string | null }) {
    const period = overview.period;
    const [year, setYear] = useState(period.year ?? new Date().getFullYear());
    const [month, setMonth] = useState(period.month ?? new Date().getMonth() + 1);
    const upload = useForm<{ year: number; month: number; file: File | null }>({ year, month, file: null });
    const [dropActive, setDropActive] = useState(false);
    const [localFileError, setLocalFileError] = useState<string | null>(null);
    const monthChosen = period.year !== null && period.month !== null;
    const legacy = period.source === 'calculated';
    const selectedMatches = period.year === year && period.month === month;
    const monthClosed = selectedMatches && (legacy || period.rawStatus === 'processed');
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function chooseFile(file: File | null) {
        if (file && file.size > 5 * 1024 * 1024) {
            upload.setData('file', null);
            setLocalFileError('This file is larger than 5 MB.');

            return;
        }

        const extension = file?.name.split('.').pop()?.toLowerCase() ?? '';

        if (file && !['csv', 'txt', 'xlsx'].includes(extension)) {
            upload.setData('file', null);
            setLocalFileError('Upload a CSV or XLSX file.');

            return;
        }

        setLocalFileError(null);
        upload.setData('file', file);
    }
    const next = !monthChosen
        ? 'Select the payroll month, then upload the completed Excel or CSV from the external payroll system.'
        : legacy
            ? 'This month has a legacy payroll record calculated inside Veltrix HRMS. It was not replaced.'
            : period.rawStatus === 'import_review'
                ? 'Review the uploaded rows. Payslips are created only after you confirm the import.'
                : period.rawStatus === 'processed'
                    ? 'This month is confirmed. Download the payslips from Payslips.'
                    : 'Upload the completed payroll file for this month.';

    return (
        <HrmsLayout workspace={workspace} title="Payroll">
            <PageIntro title="Payroll" subtitle="Record a completed monthly payroll file and generate payslips. Veltrix HRMS does not calculate salary or send payments." />
            <PayrollNav workspace={workspace} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="mb-4 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-950">{next}</div>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <MetricCard tone="blue" icon={Receipt} label="Selected month" value={monthChosen ? `${period.year}-${String(period.month).padStart(2, '0')}` : '—'} />
                <MetricCard tone="blue" icon={Users} label="Employees imported" value={monthChosen ? String(period.imported) : '—'} />
                <MetricCard tone="green" icon={CircleCheck} label="Validated" value={monthChosen ? String(period.validated) : '—'} />
                <MetricCard tone="orange" icon={TriangleAlert} label="Rows with errors" value={monthChosen ? String(period.errors) : '—'} />
                <MetricCard tone="green" icon={CircleCheck} label="Confirmed records" value={monthChosen ? String(period.confirmed) : '—'} />
                <MetricCard tone="blue" icon={FileSpreadsheet} label="Generated payslips" value={monthChosen ? String(period.generated) : '—'} />
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                <SectionCard title="Upload monthly payroll">
                    <form className="grid gap-4" onSubmit={(event) => { event.preventDefault(); if (!canImport || monthClosed) { router.get('/payroll', { year, month }, { preserveState: true }); return; } upload.transform((data) => ({ ...data, year, month })); upload.post('/payroll/imports', { forceFormData: true, preserveScroll: true }); }}>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payroll-year">Year</Label><Input id="payroll-year" type="number" value={year} onChange={(event) => setYear(Number(event.target.value))} /></label>
                            <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payroll-month">Month</Label><Input id="payroll-month" type="number" min={1} max={12} value={month} onChange={(event) => setMonth(Number(event.target.value))} /></label>
                            <Button type="button" variant="outline" onClick={() => router.get('/payroll', { year, month }, { preserveState: true })}>Show month</Button>
                        </div>
                        <p className="text-sm font-medium text-slate-800">Selected period: {monthNames[month - 1] ?? month} {year}</p>
                        {canImport ? (
                            <>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" variant="outline" onClick={() => { window.location.href = '/payroll/template'; }}>Download Sample Excel</Button>
                                    <Button type="button" variant="outline" onClick={() => { window.location.href = '/payroll/template?format=csv'; }}>Download CSV template</Button>
                                </div>
                                {monthClosed ? (
                                    <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{legacy ? 'This month already has a legacy payroll record. A new import was not accepted.' : 'A confirmed payroll import already exists for this month. Upload is closed so the confirmed amounts are not replaced.'}</p>
                                ) : (
                                    <div
                                        className={`rounded-xl border border-dashed px-4 py-6 text-center ${dropActive ? 'border-blue-500 bg-blue-50' : 'border-slate-300 bg-slate-50'}`}
                                        onDragOver={(event) => { event.preventDefault(); setDropActive(true); }}
                                        onDragLeave={() => setDropActive(false)}
                                        onDrop={(event) => { event.preventDefault(); setDropActive(false); chooseFile(event.dataTransfer.files?.[0] ?? null); }}
                                    >
                                        <Upload className="mx-auto size-5 text-blue-700" aria-hidden="true" />
                                        <p className="mt-2 text-sm font-medium text-slate-900">Drop the payroll file here</p>
                                        <p className="mt-1 text-xs text-slate-500">CSV or XLSX, up to 5 MB. Employee ID and Net Salary are required.</p>
                                        <label className="mt-3 inline-flex cursor-pointer">
                                            <span className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-800">Browse file</span>
                                            <input className="sr-only" type="file" accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={(event) => chooseFile(event.target.files?.[0] ?? null)} />
                                        </label>
                                        {upload.data.file && <p className="mt-3 text-sm text-slate-700">Selected file: {upload.data.file.name}</p>}
                                    </div>
                                )}
                                {(localFileError || upload.errors.file) && <p className="text-sm text-rose-700" role="alert">{localFileError ?? upload.errors.file}</p>}
                                {upload.progress && <p className="text-sm text-slate-600">Uploading… {upload.progress.percentage}%</p>}
                                {!importReady && <p className="text-sm text-amber-800">Payroll import storage is not available on this database yet.</p>}
                                {!monthClosed && <div><Button type="submit" disabled={upload.processing || !importReady || upload.data.file === null}>{upload.processing ? 'Uploading…' : 'Upload Monthly Payroll Excel'}</Button></div>}
                                <p className="text-sm text-slate-500">Remove the sample rows before uploading. Invalid rows stay visible and are not confirmed until the file is corrected.</p>
                            </>
                        ) : (
                            <div className="grid gap-3">
                                <p className="text-sm text-slate-600">Head of Operations, HR & Assistant Operations, or Accounts uploads and confirms the monthly file. Employees download the generated PDFs from My Payslips.</p>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" variant="outline" onClick={() => { window.location.href = '/payroll/template'; }}>Download Sample Excel</Button>
                                    <Button type="button" variant="outline" onClick={() => { window.location.href = '/payroll/template?format=csv'; }}>Download CSV template</Button>
                                </div>
                            </div>
                        )}
                    </form>
                </SectionCard>
                <SectionCard title="What happens next">
                    <ol className="list-decimal space-y-2 pl-4 text-sm text-slate-700">
                        <li>Select the month and upload the external payroll file.</li>
                        <li>Check employee matches, amounts and row errors.</li>
                        <li>Confirm the import. Amounts are stored as uploaded.</li>
                        <li>Payslips are generated and appear in My Payslips.</li>
                    </ol>
                </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Payroll history">
                    {history.length === 0 ? <p className="text-sm text-slate-500">No payroll records yet. Opening this page does not create one.</p> : (
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full min-w-[880px] text-left text-sm">
                                <thead className="bg-blue-50 text-xs text-slate-600">
                                    <tr>
                                        <th className="px-3 py-3">Period</th>
                                        <th className="px-3 py-3">Uploaded</th>
                                        <th className="px-3 py-3">Uploaded by</th>
                                        <th className="px-3 py-3">Employees</th>
                                        <th className="px-3 py-3">Gross</th>
                                        <th className="px-3 py-3">Deductions</th>
                                        <th className="px-3 py-3">Net</th>
                                        <th className="px-3 py-3">Import</th>
                                        <th className="px-3 py-3">Payslips</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.map((row) => (
                                        <tr key={row.id} className="border-t border-slate-100">
                                            <td className="px-3 py-3">{row.legacy ? (workspace.permissions.payrollView ? <Link href={`/payroll/runs/${row.id}`} className="text-blue-700">{row.period}</Link> : row.period) : <Link href={`/payroll/imports/${row.id}`} className="text-blue-700">{row.period}</Link>}</td>
                                            <td className="px-3 py-3">{row.uploadedAt ?? '—'}</td>
                                            <td className="px-3 py-3">{row.uploadedBy ?? '—'}</td>
                                            <td className="px-3 py-3">{row.employees}</td>
                                            <td className="px-3 py-3">{row.gross}</td>
                                            <td className="px-3 py-3">{row.deductions}</td>
                                            <td className="px-3 py-3">{row.net}</td>
                                            <td className="px-3 py-3"><StatusBadge value={row.importStatus} /></td>
                                            <td className="px-3 py-3"><StatusBadge value={row.payslipStatus} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
