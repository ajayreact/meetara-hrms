import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PayrollNav } from '@/components/hrms/payroll-nav';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Warning { level: string; message: string }
interface Item {
    id: number;
    employee: string;
    code: string;
    department: string;
    designation: string;
    payrollStatus: string;
    payslipStatus: string;
    currency: string | null;
    basic: string;
    hra: string;
    special: string;
    pf: string;
    esi: string;
    tds: string;
    lop: string;
    gross: string;
    deductions: string;
    net: string;
    workingDays: string;
    presentDays: string;
    leaveDays: string;
}
interface StatutoryItem { key: string; label: string; state: string; action: string }
interface StatutoryReadiness { ready: boolean; approvalBlocked: boolean; processingBlocked: boolean; items: StatutoryItem[] }
interface Member { name: string; code: string; reason: string }
interface Membership { source: string; included: Member[]; excluded: Member[] }
interface Totals { reliable: boolean; currency: string | null; employees: number; gross: string | null; net: string | null; note: string }
interface RunDetail {
    id: number;
    period: string;
    status: string;
    rawStatus: string;
    legacy?: boolean;
    warnings: Warning[];
    membership: Membership;
    totals: Totals | null;
    blocked: boolean;
    statutory: StatutoryReadiness;
    deductionsConfigured: boolean;
    reviewNote: string | null;
    items: Item[];
    history: { action: string; note: string | null; at: string }[];
}

export default function PayrollShow({ workspace, run, canPrepare = false, canApprove = false, notice, error }: HrmsPageProps & { run: RunDetail; canPrepare?: boolean; canApprove?: boolean; notice?: string | null; error?: string | null }) {
    const note = useForm({ note: '', confirm: '' });
    const [confirmProcess, setConfirmProcess] = useState(false);
    const [query, setQuery] = useState('');
    const [department, setDepartment] = useState('all');
    const [status, setStatus] = useState('all');
    const departments = [...new Set(run.items.map((item) => item.department).filter(Boolean))];
    const rows = run.items.filter((item) => {
        const haystack = `${item.employee} ${item.code}`.toLowerCase();
        return (query.trim() === '' || haystack.includes(query.trim().toLowerCase()))
            && (department === 'all' || item.department === department)
            && (status === 'all' || item.payrollStatus === status || item.payslipStatus === status);
    });

    return (
        <HrmsLayout workspace={workspace} title="Payroll review">
            <PageIntro title={`Payroll ${run.period}`} subtitle={run.legacy === false ? 'Imported payroll. The amounts were stored as uploaded and were not recalculated.' : 'Legacy payroll processing. This run was calculated inside Veltrix HRMS. New monthly payroll is uploaded from Excel.'} />
            <PayrollNav workspace={workspace} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)]">
            <SectionCard title="Status" extra={<StatusBadge value={run.status} />}>
                {run.warnings.length === 0 ? <p className="text-sm text-slate-500">No warnings yet. Calculate the run to review inputs.</p> : (
                    <ul className="space-y-2 text-sm">
                        {run.warnings.map((warning) => <li key={warning.message} className={warning.level === 'critical' ? 'text-rose-700' : 'text-amber-800'}>{warning.message}</li>)}
                    </ul>
                )}
                {run.reviewNote && <p className="mt-3 text-sm text-slate-600">Review note: {run.reviewNote}</p>}
                <div className="mt-4 flex flex-wrap gap-2">
                    {canPrepare && ['draft', 'calculated'].includes(run.rawStatus) && <Button type="button" disabled={note.processing} onClick={() => note.post(`/payroll/runs/${run.id}/calculate`)}>{note.processing ? 'Working…' : 'Calculate'}</Button>}
                    {canPrepare && run.rawStatus === 'calculated' && <Button type="button" variant="outline" disabled={note.processing} onClick={() => note.post(`/payroll/runs/${run.id}/review`)}>{note.processing ? 'Working…' : 'Send for review'}</Button>}
                    {canApprove && run.rawStatus === 'under_review' && <Button type="button" disabled={note.processing || run.statutory.ready !== true} onClick={() => note.post(`/payroll/runs/${run.id}/approve`)}>{note.processing ? 'Working…' : 'Approve'}</Button>}
                    {canPrepare && run.rawStatus === 'approved' && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={confirmProcess} onChange={(event) => setConfirmProcess(event.target.checked)} />
                            Confirm processing. This finalizes the saved calculation.
                        </label>
                    )}
                    {canPrepare && run.rawStatus === 'approved' && <Button type="button" disabled={note.processing || !confirmProcess || run.blocked} onClick={() => { note.transform((data) => ({ ...data, confirm: 'PROCESS' })); note.post(`/payroll/runs/${run.id}/process`); }}>{note.processing ? 'Working…' : 'Process payroll'}</Button>}
                    {canPrepare && run.rawStatus === 'processed' && <Button type="button" variant="outline" disabled={note.processing} onClick={() => note.post(`/payroll/runs/${run.id}/correct`)}>{note.processing ? 'Working…' : 'Open correction'}</Button>}
                </div>
                <label className="mt-3 grid max-w-xl gap-1 text-sm">
                    Note
                    <Input value={note.data.note} onChange={(event) => note.setData('note', event.target.value)} />
                    <InputError message={note.errors.note} />
                </label>
                {run.blocked && run.rawStatus !== 'processed' && <p className="mt-3 text-sm text-rose-700">Approval and processing stay off until statutory readiness is proven. A zero deduction is not a ready status.</p>}
                {run.rawStatus === 'processed' && <p className="mt-3 text-sm text-slate-600">This run is finalized. Ordinary edits are closed. A correction is a separate audited step.</p>}
            </SectionCard>
            <div className="grid content-start gap-4">
                <section className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3">
                    <h2 className="text-sm font-semibold text-rose-900">Statutory readiness</h2>
                    <p className="mt-2 text-sm text-rose-900">{run.statutory.ready ? 'Statutory readiness is proven.' : 'Statutory readiness is unresolved. Approval and processing stay blocked until each category is reviewed.'}</p>
                    <ul className="mt-2 space-y-2 text-sm text-rose-900">
                        {run.statutory.items.map((item) => (
                            <li key={item.key}>
                                <span className="font-medium">{item.label}</span>
                                <span> · {item.state === 'not_configured' ? 'Not configured' : item.state === 'incomplete' ? 'Incomplete' : 'Invalid'}</span>
                                <span className="block text-rose-800">{item.action}</span>
                            </li>
                        ))}
                    </ul>
                </section>
                <SectionCard title="Audit">
                    {run.history.length === 0 ? <p className="text-sm text-slate-500">No payroll actions yet.</p> : (
                        <ul className="divide-y divide-slate-100 text-sm text-slate-600">
                            {run.history.map((event) => (
                                <li key={`${event.action}-${event.at}`} className="py-2">
                                    <span className="font-medium text-slate-800">{event.action}</span>
                                    <span className="mt-1 block text-xs text-slate-500">{event.at}{event.note ? ` · ${event.note}` : ''}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <SectionCard title={run.membership.source === 'preview' ? 'Employees who would be included' : 'Employees included'}>
                    <p className="mb-3 text-sm text-slate-500">{run.membership.source === 'preview' ? 'This is a preview. Calculating the run is what saves the result.' : `${run.membership.included.length} employees are in the saved calculation.`}</p>
                    <MemberList rows={run.membership.included} empty="No employees are included." />
                </SectionCard>
                <SectionCard title="Employees excluded">
                    <MemberList rows={run.membership.excluded} empty="No employees are excluded." />
                </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Saved totals" extra={run.totals?.currency ?? undefined}>
                    {run.totals === null ? (
                        <p className="text-sm text-slate-500">No saved calculation yet, so gross pay and net payable are not shown.</p>
                    ) : (
                        <div className="space-y-1 text-sm text-slate-700">
                            <p>{run.totals.employees} employee{run.totals.employees === 1 ? '' : 's'}</p>
                            {run.totals.reliable && run.totals.gross ? <p>Gross {run.totals.currency} {run.totals.gross}</p> : <p>Gross is not combined.</p>}
                            {run.totals.net ? <p>Net payable {run.totals.currency} {run.totals.net}</p> : null}
                            <p className="text-slate-500">{run.totals.note}</p>
                        </div>
                    )}
                </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Employees">
                    {run.items.length === 0 ? <p className="text-sm text-slate-500">No calculated employees yet.</p> : (
                        <>
                            <div className="mb-3 flex flex-wrap gap-2">
                                <Input className="max-w-xs" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search name or employee ID" aria-label="Search employees" />
                                <select className="h-9 rounded-md border border-slate-200 bg-white px-3 text-sm" value={department} onChange={(event) => setDepartment(event.target.value)} aria-label="Department">
                                    <option value="all">All departments</option>
                                    {departments.map((name) => <option key={name} value={name}>{name}</option>)}
                                </select>
                                <select className="h-9 rounded-md border border-slate-200 bg-white px-3 text-sm" value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Status">
                                    <option value="all">All statuses</option>
                                    <option value="Available">Payslip available</option>
                                    <option value="Not generated">Payslip not generated</option>
                                </select>
                            </div>
                            <div className="overflow-x-auto rounded-xl border border-slate-200">
                                <table className="w-full min-w-[1100px] text-left text-sm">
                                    <thead className="bg-blue-50 text-xs text-slate-600">
                                        <tr>
                                            {['Employee ID', 'Employee', 'Department', 'Designation', 'Basic', 'HRA', 'Allowances', 'Gross', 'Deductions', 'Net', 'Payroll', 'Payslip'].map((heading) => (
                                                <th key={heading} className="px-3 py-3 font-medium whitespace-nowrap">{heading}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((item) => (
                                            <tr key={item.id} className="border-t border-slate-100">
                                                <td className="px-3 py-3 whitespace-nowrap">{item.code}</td>
                                                <td className="px-3 py-3 whitespace-nowrap">{item.employee}</td>
                                                <td className="px-3 py-3">{item.department || '—'}</td>
                                                <td className="px-3 py-3">{item.designation || '—'}</td>
                                                <td className="px-3 py-3">{item.basic}</td>
                                                <td className="px-3 py-3">{item.hra}</td>
                                                <td className="px-3 py-3">{item.special}</td>
                                                <td className="px-3 py-3 whitespace-nowrap">{item.currency ?? ''} {item.gross}</td>
                                                <td className="px-3 py-3">{run.deductionsConfigured ? item.deductions : 'Not configured'}</td>
                                                <td className="px-3 py-3">{run.deductionsConfigured || run.rawStatus === 'processed' ? item.net : 'Not finalized'}</td>
                                                <td className="px-3 py-3"><StatusBadge value={item.payrollStatus || run.status} /></td>
                                                <td className="px-3 py-3">{item.payslipStatus === 'Available' ? <a className="text-blue-700" href={`/payslips/${item.id}`}>Available</a> : 'Not generated'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {rows.length === 0 && <p className="mt-3 text-sm text-slate-500">No employees match this search.</p>}
                            <p className="mt-3 text-sm text-slate-500">Working days, recorded attendance, and approved leave stay on the saved calculation. PF, ESI, TDS, and unpaid leave stay unmarked until those rules are configured.</p>
                        </>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function MemberList({ rows, empty }: { rows: { name: string; code: string; reason: string }[]; empty: string }) {
    if (rows.length === 0) {
        return <p className="text-sm text-slate-500">{empty}</p>;
    }

    return (
        <ul className="divide-y divide-slate-100 text-sm">
            {rows.map((row) => (
                <li key={`${row.code}-${row.reason}`} className="py-2">
                    <span className="font-medium text-slate-800">{row.name} · {row.code}</span>
                    <span className="mt-0.5 block text-slate-500">{row.reason}</span>
                </li>
            ))}
        </ul>
    );
}
