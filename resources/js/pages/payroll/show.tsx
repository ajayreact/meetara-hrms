import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
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
interface RunDetail {
    id: number;
    period: string;
    status: string;
    rawStatus: string;
    warnings: Warning[];
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

    return (
        <HrmsLayout workspace={workspace} title="Payroll review">
            <PageIntro title={`Payroll ${run.period}`} subtitle="Calculation uses the saved salary record. Approval and processing stay blocked until statutory readiness is proven." />
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
            <div className="mt-4">
                <SectionCard title="Employees">
                    {run.items.length === 0 ? <p className="text-sm text-slate-500">No calculated employees yet.</p> : (
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full min-w-[1100px] text-left text-sm">
                                <thead className="bg-blue-50 text-xs text-slate-600">
                                    <tr>
                                        {['Employee', 'Basic', 'HRA', 'Special', 'PF', 'ESI', 'TDS', 'Unpaid leave', 'Gross', 'Deductions', 'Net', 'Working days', 'Recorded attendance', 'Approved leave'].map((heading) => (
                                            <th key={heading} className="px-3 py-3 font-medium whitespace-nowrap">{heading}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {run.items.map((item) => (
                                        <tr key={item.id} className="border-t border-slate-100">
                                            <td className="px-3 py-3 whitespace-nowrap">{item.employee} · {item.code}</td>
                                            <td className="px-3 py-3">{item.basic}</td>
                                            <td className="px-3 py-3">{item.hra}</td>
                                            <td className="px-3 py-3">{item.special}</td>
                                            <td className="px-3 py-3">{run.deductionsConfigured ? item.pf : 'Not configured'}</td>
                                            <td className="px-3 py-3">{run.deductionsConfigured ? item.esi : 'Not configured'}</td>
                                            <td className="px-3 py-3">{run.deductionsConfigured ? item.tds : 'Not configured'}</td>
                                            <td className="px-3 py-3">{run.deductionsConfigured ? item.lop : 'Not configured'}</td>
                                            <td className="px-3 py-3 whitespace-nowrap">{item.currency ?? ''} {item.gross}</td>
                                            <td className="px-3 py-3">{item.deductions}</td>
                                            <td className="px-3 py-3">{item.net}</td>
                                            <td className="px-3 py-3">{item.workingDays}</td>
                                            <td className="px-3 py-3">{item.presentDays}</td>
                                            <td className="px-3 py-3">{item.leaveDays}</td>
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
