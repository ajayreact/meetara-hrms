import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PayrollNav } from '@/components/hrms/payroll-nav';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { CircleCheck, Clock3, Receipt, Users } from 'lucide-react';
import { useState } from 'react';

interface Period {
    year: number | null;
    month: number | null;
    found: boolean;
    status: string | null;
    gross: string;
    net: string;
    employees: number;
}

interface LatestRun {
    id: number;
    period: string;
    status: string;
    rawStatus: string;
}

interface Activity {
    action: string;
    period: string;
    at: string;
}

interface Overview {
    currencyNote: string;
    runs: number;
    draft: number;
    calculated: number;
    underReview: number;
    review: number;
    approved: number;
    processed: number;
    outstanding: number;
    processedGross: string;
    processedNet: string;
    employees: number;
    activeEmployees: number;
    payslips: number;
    latest: LatestRun | null;
    eligibility: { included: number; excluded: number } | null;
    period: Period;
    activity: Activity[];
}

export default function PayrollOverview({ workspace, overview, canPrepare = false, notice, error }: HrmsPageProps & { overview: Overview; canPrepare?: boolean; notice?: string | null; error?: string | null }) {
    const [year, setYear] = useState(overview.period.year ?? new Date().getFullYear());
    const [month, setMonth] = useState(overview.period.month ?? new Date().getMonth() + 1);
    const create = useForm({ year: new Date().getFullYear(), month: new Date().getMonth() + 1 });
    const periodLabel = overview.period.year && overview.period.month ? `${overview.period.year}-${String(overview.period.month).padStart(2, '0')}` : null;
    const showSavedGross = overview.period.found && overview.period.employees > 0;
    const showProcessedMoney = overview.processed > 0;

    return (
        <HrmsLayout workspace={workspace} title="Payroll">
            <PageIntro title="Payroll" subtitle={overview.currencyNote} />
            <PayrollNav workspace={workspace} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard tone="blue" icon={Users} label="Active employees" value={String(overview.activeEmployees)} />
                <MetricCard tone="orange" icon={Clock3} label="Awaiting review" value={String(overview.review)} />
                <MetricCard tone="green" icon={CircleCheck} label="Processed runs" value={String(overview.processed)} />
                <MetricCard tone="blue" icon={Receipt} label="Payslips available" value={String(overview.payslips)} />
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(16rem,0.85fr)]">
                <SectionCard title="Payroll period" extra={overview.latest ? <StatusBadge value={overview.latest.status} /> : undefined}>
                    <form className="mb-4 flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); router.get('/payroll', { year, month }, { preserveState: true }); }}>
                        <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payroll-year">Year</Label><Input id="payroll-year" type="number" value={year} onChange={(event) => setYear(Number(event.target.value))} /></label>
                        <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payroll-month">Month</Label><Input id="payroll-month" type="number" min={1} max={12} value={month} onChange={(event) => setMonth(Number(event.target.value))} /></label>
                        <Button type="submit">Show month</Button>
                    </form>
                    {overview.latest ? (
                        <p className="text-sm text-slate-700">Latest run: <Link href={`/payroll/runs/${overview.latest.id}`} className="font-medium text-blue-700">{overview.latest.period}</Link> · {overview.latest.status}</p>
                    ) : (
                        <p className="text-sm text-slate-500">No payroll run exists. Opening this page does not create one.</p>
                    )}
                    {periodLabel === null ? (
                        <p className="mt-3 text-sm text-slate-500">Choose a month to see whether a saved run exists. Totals appear only after a calculation is stored.</p>
                    ) : overview.period.found ? (
                        <div className="mt-3 space-y-1 text-sm text-slate-700">
                            <p className="flex items-center gap-2">{periodLabel} {overview.period.status && <StatusBadge value={overview.period.status} />}</p>
                            {overview.eligibility && <p>{overview.eligibility.included} would be included and {overview.eligibility.excluded} excluded for this month, using joining date and salary records.</p>}
                            {showSavedGross ? (
                                <>
                                    <p>Saved gross {overview.period.gross} for {overview.period.employees} employee{overview.period.employees === 1 ? '' : 's'}.</p>
                                    {overview.period.status === 'Processed' ? <p>Net payable {overview.period.net}.</p> : <p>Net payable is hidden until statutory deductions are configured and the run is processed.</p>}
                                </>
                            ) : (
                                <p>This run has no saved employee calculation yet.</p>
                            )}
                        </div>
                    ) : (
                        <p className="mt-3 text-sm text-slate-500">No payroll run for {periodLabel}.{overview.eligibility ? ` ${overview.eligibility.included} employees meet the inclusion rules for that month, and ${overview.eligibility.excluded} would be excluded.` : ''}</p>
                    )}
                    {showProcessedMoney ? (
                        <p className="mt-3 text-sm text-slate-700">Processed payroll: gross {overview.processedGross}, net {overview.processedNet}, across {overview.employees} payslip row{overview.employees === 1 ? '' : 's'}.</p>
                    ) : (
                        <p className="mt-3 text-sm text-slate-500">No processed payroll, so gross and net payable are not shown.</p>
                    )}
                </SectionCard>
                <div className="grid content-start gap-4">
                    <SectionCard title={overview.runs === 0 ? 'Create a payroll run' : 'Run actions'}>
                        {overview.runs === 0 && <p className="mb-3 text-sm text-slate-500">No payroll runs yet. A run is created only when an authorized user submits the month.</p>}
                        {canPrepare ? (
                            <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); create.post('/payroll/runs'); }}>
                                <label className="grid w-28 gap-1 text-sm"><Label htmlFor="create-year">Year</Label><Input id="create-year" type="number" value={create.data.year} onChange={(event) => create.setData('year', Number(event.target.value))} /></label>
                                <label className="grid w-28 gap-1 text-sm"><Label htmlFor="create-month">Month</Label><Input id="create-month" type="number" min={1} max={12} value={create.data.month} onChange={(event) => create.setData('month', Number(event.target.value))} /></label>
                                <Button type="submit" disabled={create.processing}>{create.processing ? 'Working…' : 'Create payroll run'}</Button>
                            </form>
                        ) : (
                            <p className="text-sm text-slate-500">Accounts can create a run. Company Admin reviews and approves it.</p>
                        )}
                        <div className="mt-3 flex flex-wrap gap-3 text-sm">
                            <Link href="/payroll/runs" className="text-blue-700">Open payroll runs</Link>
                            <Link href="/payroll/runs?status=review" className="text-blue-700">Open payroll review</Link>
                            <Link href="/payslips" className="text-blue-700">Open payslips</Link>
                        </div>
                    </SectionCard>
                    <SectionCard title="By status">
                        <ul className="divide-y divide-slate-100 text-sm text-slate-700">
                            {[['Draft', overview.draft], ['Calculated', overview.calculated], ['Under Review', overview.underReview], ['Approved', overview.approved], ['Processed', overview.processed], ['Awaiting review', overview.review], ['Outstanding payments', overview.outstanding]].map(([label, value]) => (
                                <li key={String(label)} className="flex items-center justify-between gap-3 py-2"><span>{label}</span><span className="font-semibold">{value}</span></li>
                            ))}
                        </ul>
                    </SectionCard>
                </div>
            </div>
            <div className="mt-4">
                <SectionCard title="Recent activity">
                    {overview.activity.length === 0 ? <p className="text-sm text-slate-500">No payroll actions yet.</p> : (
                        <ul className="divide-y divide-slate-100 text-sm text-slate-700">
                            {overview.activity.map((event) => (
                                <li key={`${event.action}-${event.at}`} className="flex items-start justify-between gap-3 py-2">
                                    <span>{event.action}{event.period ? ` · ${event.period}` : ''}</span>
                                    <time className="shrink-0 text-xs text-slate-500">{event.at}</time>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
