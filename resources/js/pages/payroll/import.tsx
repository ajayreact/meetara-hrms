import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PayrollNav } from '@/components/hrms/payroll-nav';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

interface ImportRow {
    line: number;
    employeeCode: string;
    name: string;
    errors: string[];
    warnings: string[];
    supplied: Record<string, string>;
}

interface Review {
    id: number;
    period: string;
    status: string;
    rawStatus: string;
    fileName: string | null;
    uploadedAt: string | null;
    uploadedBy: string | null;
    headers: string[];
    mapping: Record<string, string>;
    fileError: string | null;
    rows: ImportRow[];
    errorCount: number;
    validCount: number;
    canConfirm: boolean;
    payslipStatus: string | null;
    fields: string[];
    failures: { id: number; code: string; error: string }[];
}

const labels: Record<string, string> = {
    employee_id: 'Employee ID',
    employee_name: 'Employee name',
    payroll_month: 'Payroll month',
    payroll_year: 'Payroll year',
    basic: 'Basic salary',
    hra: 'HRA',
    special: 'Special allowance',
    other_earnings: 'Other earnings',
    gross: 'Gross salary',
    pf: 'PF',
    esi: 'ESI',
    professional_tax: 'Professional tax',
    tds: 'TDS',
    other_deductions: 'Other deductions',
    deductions: 'Total deductions',
    net: 'Net salary',
    paid_days: 'Paid days',
    working_days: 'Working days',
    lwp: 'Leave without pay',
};

function hasError(row: ImportRow, text: string): boolean {
    return row.errors.some((error) => error.toLowerCase().includes(text));
}

export default function PayrollImportReview({ workspace, review, canImport = false, notice, error }: HrmsPageProps & { review: Review; canImport?: boolean; notice?: string | null; error?: string | null }) {
    const mapping = useForm({ mapping: Object.fromEntries(review.fields.map((field) => [field, review.mapping[field] ?? ''])) });
    const confirm = useForm({});
    const matched = review.rows.filter((row) => row.employeeCode !== '' && !hasError(row, 'not found') && !hasError(row, 'required')).length;
    const unmatched = review.rows.filter((row) => hasError(row, 'not found')).length;
    const duplicates = review.rows.filter((row) => hasError(row, 'duplicate')).length;
    const missing = review.rows.filter((row) => hasError(row, 'required')).length;
    const invalid = review.rows.filter((row) => row.errors.some((item) => item.includes('must be') || item.includes('does not equal') || item.includes('does not match'))).length;
    const money = (field: 'gross' | 'deductions' | 'net') => review.rows.reduce((sum, row) => sum + (row.errors.length === 0 ? Number(row.supplied[field] ?? 0) : 0), 0);

    return (
        <HrmsLayout workspace={workspace} title="Review payroll import">
            <PageIntro title={`Review ${review.period}`} subtitle="Check the uploaded payroll file. Nothing is saved as payroll, and no payslip is created, until you confirm." />
            <PayrollNav workspace={workspace} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <SectionCard title={review.fileName ?? 'Uploaded file'} extra={<StatusBadge value={review.status} />}>
                <p className="text-sm text-slate-600">Uploaded by {review.uploadedBy ?? '—'} {review.uploadedAt ? `on ${review.uploadedAt}` : ''}. Invalid rows stay in this list and block confirmation.</p>
                <dl className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        ['Uploaded rows', review.rows.length],
                        ['Matched employees', matched],
                        ['Unmatched Employee IDs', unmatched],
                        ['Duplicate Employee IDs', duplicates],
                        ['Missing required values', missing],
                        ['Invalid amounts', invalid],
                        ['Valid rows', review.validCount],
                        ['Rows requiring correction', review.errorCount],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-xl border border-slate-200 px-3 py-2">
                            <dt className="text-xs text-slate-500">{label}</dt>
                            <dd className="text-lg font-semibold text-slate-950">{value}</dd>
                        </div>
                    ))}
                </dl>
                {review.rawStatus === 'import_review' && (
                    <p className="mt-4 text-sm text-slate-700">Valid rows total gross {money('gross').toFixed(2)}, deductions {money('deductions').toFixed(2)}, net {money('net').toFixed(2)}. Confirming stores these uploaded amounts and generates a payslip for each valid row. Meetara does not recalculate them.</p>
                )}
                {review.fileError && <p className="mt-2 text-sm text-rose-700">{review.fileError}</p>}
                {canImport && review.rawStatus === 'import_review' && (
                    <form className="mt-4 grid gap-3" onSubmit={(event) => { event.preventDefault(); mapping.post(`/payroll/imports/${review.id}/mapping`, { preserveScroll: true }); }}>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {review.fields.map((field) => (
                                <label key={field} className="grid gap-1 text-sm">
                                    <Label htmlFor={`map-${field}`}>{labels[field] ?? field}</Label>
                                    <select id={`map-${field}`} className="h-9 rounded-md border border-slate-200 bg-white px-2" value={mapping.data.mapping[field] ?? ''} onChange={(event) => mapping.setData('mapping', { ...mapping.data.mapping, [field]: event.target.value })}>
                                        <option value="">Not in file</option>
                                        {review.headers.filter(Boolean).map((header) => <option key={header} value={header}>{header}</option>)}
                                    </select>
                                </label>
                            ))}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" variant="outline" disabled={mapping.processing}>{mapping.processing ? 'Checking…' : 'Apply column mapping'}</Button>
                            <Button type="button" disabled={!review.canConfirm || confirm.processing} onClick={() => confirm.post(`/payroll/imports/${review.id}/confirm`)}>{confirm.processing ? 'Confirming…' : 'Confirm imported amounts'}</Button>
                        </div>
                    </form>
                )}
                {review.rawStatus === 'processed' && <p className="mt-3 text-sm text-slate-700">This import is confirmed. Payslip status: {review.payslipStatus ?? 'pending'}.</p>}
                {canImport && review.failures.length > 0 && (
                    <form className="mt-3" onSubmit={(event) => { event.preventDefault(); confirm.post(`/payroll/imports/${review.id}/payslips`); }}>
                        <p className="mb-2 text-sm text-rose-700">{review.failures.length} payslip{review.failures.length === 1 ? '' : 's'} could not be written. Successful payslips are left as they are.</p>
                        <Button type="submit" variant="outline" disabled={confirm.processing}>{confirm.processing ? 'Retrying…' : 'Retry failed payslips'}</Button>
                    </form>
                )}
            </SectionCard>
            <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="w-full min-w-[760px] text-left text-sm">
                    <thead className="bg-blue-50 text-xs text-slate-600">
                        <tr>
                            <th className="px-3 py-3">Row</th>
                            <th className="px-3 py-3">Employee ID</th>
                            <th className="px-3 py-3">Employee</th>
                            <th className="px-3 py-3">Gross</th>
                            <th className="px-3 py-3">Deductions</th>
                            <th className="px-3 py-3">Net</th>
                            <th className="px-3 py-3">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        {review.rows.map((row) => (
                            <tr key={row.line} className="border-t border-slate-100 align-top">
                                <td className="px-3 py-3">{row.line}</td>
                                <td className="px-3 py-3">{row.employeeCode || '—'}</td>
                                <td className="px-3 py-3">{row.name || '—'}</td>
                                <td className="px-3 py-3">{row.supplied.gross ?? '—'}</td>
                                <td className="px-3 py-3">{row.supplied.deductions ?? '—'}</td>
                                <td className="px-3 py-3">{row.supplied.net ?? '—'}</td>
                                <td className="px-3 py-3">
                                    {row.errors.length > 0 ? row.errors.map((item) => <p key={item} className="text-rose-700">{item}</p>) : <StatusBadge value="Valid" />}
                                    {row.warnings.map((warning) => <p key={warning} className="mt-1 text-amber-800">{warning}</p>)}
                                    {review.rawStatus === 'processed' && row.errors.length === 0 && <Link href="/payslips" className="mt-1 block text-blue-700">Open payslips</Link>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </HrmsLayout>
    );
}
