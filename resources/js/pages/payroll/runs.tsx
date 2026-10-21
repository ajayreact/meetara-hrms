import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface RunRow {
    id: number;
    period: string;
    status: string;
    employees: number;
    gross: string;
    net: string;
}

const statuses = [
    { value: '', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'calculated', label: 'Calculated' },
    { value: 'under_review', label: 'Under review' },
    { value: 'approved', label: 'Approved' },
    { value: 'processed', label: 'Processed' },
];

export default function PayrollRuns({ workspace, runs = [], filter = '', year = null, month = null, canPrepare = false, notice, error }: HrmsPageProps & { runs?: RunRow[]; filter?: string; year?: number | null; month?: number | null; canPrepare?: boolean; notice?: string | null; error?: string | null }) {
    const form = useForm({ year: new Date().getFullYear(), month: new Date().getMonth() + 1 });
    const [filterYear, setFilterYear] = useState(year ? String(year) : '');
    const [filterMonth, setFilterMonth] = useState(month ? String(month) : '');

    return (
        <HrmsLayout workspace={workspace} title="Payroll runs">
            <PageIntro title="Payroll runs" subtitle="One run is allowed for each company and month. Gross pay is basic salary plus HRA plus special allowance." />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className={`grid gap-4 ${canPrepare ? 'xl:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)]' : ''}`}>
            {canPrepare && (
                <SectionCard title="Open a month">
                    <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); form.post('/payroll/runs'); }}>
                        <label className="grid w-28 gap-1 text-sm"><Label htmlFor="year">Year</Label><Input id="year" type="number" value={form.data.year} onChange={(event) => form.setData('year', Number(event.target.value))} /></label>
                        <label className="grid w-28 gap-1 text-sm"><Label htmlFor="month">Month</Label><Input id="month" type="number" min={1} max={12} value={form.data.month} onChange={(event) => form.setData('month', Number(event.target.value))} /></label>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Working…' : 'Open run'}</Button>
                    </form>
                </SectionCard>
            )}
                <SectionCard title="Company runs">
                    <form className="mb-4 flex flex-wrap items-end gap-3" onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/payroll/runs', {
                            status: filter || undefined,
                            year: filterYear || undefined,
                            month: filterMonth || undefined,
                        }, { preserveState: true });
                    }}>
                        <label className="grid w-28 gap-1 text-sm"><Label htmlFor="filter-year">Year</Label><Input id="filter-year" type="number" value={filterYear} onChange={(event) => setFilterYear(event.target.value)} /></label>
                        <label className="grid w-28 gap-1 text-sm"><Label htmlFor="filter-month">Month</Label><Input id="filter-month" type="number" min={1} max={12} value={filterMonth} onChange={(event) => setFilterMonth(event.target.value)} /></label>
                        <Button type="submit" variant="outline">Filter</Button>
                    </form>
                    <div className="mb-4 flex flex-wrap gap-2">
                        {statuses.map((status) => (
                            <Link key={status.value || 'all'} href={status.value ? `/payroll/runs?status=${status.value}` : '/payroll/runs'} className={`rounded-full px-3 py-1 text-xs ${filter === status.value ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700'}`}>{status.label}</Link>
                        ))}
                    </div>
                    {runs.length === 0 ? <p className="text-sm text-slate-500">No payroll runs yet.</p> : (
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full min-w-[640px] text-left text-sm">
                                <thead className="bg-blue-50 text-xs text-slate-600"><tr><th className="px-3 py-3">Period</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Employees</th><th className="px-3 py-3">Gross</th><th className="px-3 py-3">Net</th></tr></thead>
                                <tbody>
                                    {runs.map((run) => (
                                        <tr key={run.id} className="border-t border-slate-100 hover:bg-blue-50/60">
                                            <td className="px-3 py-3"><Link href={`/payroll/runs/${run.id}`} className="font-medium text-blue-700">{run.period}</Link></td>
                                            <td className="px-3 py-3"><StatusBadge value={run.status} /></td>
                                            <td className="px-3 py-3">{run.employees}</td>
                                            <td className="px-3 py-3">{run.gross}</td>
                                            <td className="px-3 py-3">{run.net}</td>
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
