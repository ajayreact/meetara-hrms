import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
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
    period: Period;
    activity: Activity[];
}

export default function PayrollOverview({ workspace, overview, notice, error }: HrmsPageProps & { overview: Overview; notice?: string | null; error?: string | null }) {
    const [year, setYear] = useState(overview.period.year ?? new Date().getFullYear());
    const [month, setMonth] = useState(overview.period.month ?? new Date().getMonth() + 1);

    return (
        <HrmsLayout workspace={workspace} title="Payroll">
            <PageIntro title="Payroll" subtitle={overview.currencyNote} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            {overview.runs === 0 ? (
                <SectionCard title="Payroll runs" extra={<Link href="/payroll/runs" className="text-sm text-blue-600">Open runs</Link>}>
                    <p className="text-sm text-slate-500">No payroll runs yet. Opening this page does not create a run.</p>
                </SectionCard>
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard tone="blue" icon={Receipt} label="Processed gross" value={overview.processedGross} />
                        <MetricCard tone="blue" icon={Receipt} label="Processed net" value={overview.processedNet} />
                        <MetricCard tone="green" icon={Users} label="Employees in processed runs" value={String(overview.employees)} />
                        <MetricCard tone="orange" icon={CircleCheck} label="Outstanding payments" value={String(overview.outstanding)} />
                    </div>
                    <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(16rem,0.9fr)]">
                        <SectionCard title="Selected month" extra={<Link href="/payroll/runs" className="text-sm text-blue-600">Run history</Link>}>
                            <form className="mb-4 flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); router.get('/payroll', { year, month }, { preserveState: true }); }}>
                                <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payroll-year">Year</Label><Input id="payroll-year" type="number" value={year} onChange={(event) => setYear(Number(event.target.value))} /></label>
                                <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payroll-month">Month</Label><Input id="payroll-month" type="number" min={1} max={12} value={month} onChange={(event) => setMonth(Number(event.target.value))} /></label>
                                <Button type="submit">Show month</Button>
                            </form>
                            {overview.period.year === null || overview.period.month === null ? (
                                <p className="text-sm text-slate-500">Choose a month to see the saved gross pay, net pay, and employees for that run.</p>
                            ) : overview.period.found ? (
                                <div className="space-y-2 text-sm text-slate-700">
                                    <p className="flex items-center gap-2">{overview.period.year}-{String(overview.period.month).padStart(2, '0')} {overview.period.status && <StatusBadge value={overview.period.status} />}</p>
                                    <p>Gross {overview.period.gross} · Net {overview.period.net}</p>
                                    <p>{overview.period.employees} employee{overview.period.employees === 1 ? '' : 's'} included</p>
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">No payroll run for {overview.period.year}-{String(overview.period.month).padStart(2, '0')}.</p>
                            )}
                        </SectionCard>
                        <div className="grid content-start gap-4">
                        <SectionCard title="By status" extra={<Clock3 className="size-4 text-slate-400" />}>
                            <ul className="divide-y divide-slate-100 text-sm text-slate-700">
                                {[['Draft', overview.draft], ['Calculated', overview.calculated], ['Under Review', overview.underReview], ['Approved', overview.approved], ['Processed', overview.processed]].map(([label, value]) => (
                                    <li key={String(label)} className="flex items-center justify-between gap-3 py-2"><StatusBadge value={String(label)} /><span className="font-semibold">{value}</span></li>
                                ))}
                                <li className="flex items-center justify-between gap-3 py-2"><span>Awaiting review</span><span className="font-semibold">{overview.review}</span></li>
                            </ul>
                            {workspace.permissions.settings && <Link href="/company/settings" className="mt-4 inline-block text-sm text-blue-600">Payroll settings</Link>}
                        </SectionCard>
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
                    </div>
                </>
            )}
        </HrmsLayout>
    );
}
