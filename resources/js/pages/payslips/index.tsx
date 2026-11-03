import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PayrollNav } from '@/components/hrms/payroll-nav';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface PayslipRow {
    id: number;
    employee: string;
    code: string;
    period: string;
    currency: string | null;
    gross: string;
    deductions: string;
    deductionsConfigured: boolean;
    net: string;
    paymentStatus: string;
    generatedAt: string | null;
}

interface Filters {
    year: number | null;
    month: number | null;
    search: string;
}

export default function PayslipsPage({ workspace, payslips = [], filters = { year: null, month: null, search: '' }, canDownloadAll = false, notice, error }: HrmsPageProps & { payslips?: PayslipRow[]; filters?: Filters; canDownloadAll?: boolean; notice?: string | null; error?: string | null }) {
    const mine = workspace.role === 'employee';
    const [year, setYear] = useState(filters.year ?? new Date().getFullYear());
    const [month, setMonth] = useState(filters.month ?? new Date().getMonth() + 1);
    const [search, setSearch] = useState(filters.search ?? '');
    const archive = `/payslips/archive?year=${year}&month=${month}`;

    return (
        <HrmsLayout workspace={workspace} title={mine ? 'My Payslips' : 'Payslips'}>
            <PageIntro title={mine ? 'My Payslips' : 'Payslips'} subtitle={mine ? 'Download the payslips generated for your employee record.' : 'Payslips are created from a confirmed payroll import. Salary records are not changed, and no payment is sent from here.'} />
            {!mine && <PayrollNav workspace={workspace} />}
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <SectionCard title={mine ? 'Available payslips' : 'Generated payslips'}>
                <form className="mb-4 flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); router.get('/payslips', { year, month, search }, { preserveState: true }); }}>
                    <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payslip-year">Year</Label><Input id="payslip-year" type="number" value={year} onChange={(event) => setYear(Number(event.target.value))} /></label>
                    <label className="grid w-28 gap-1 text-sm"><Label htmlFor="payslip-month">Month</Label><Input id="payslip-month" type="number" min={1} max={12} value={month} onChange={(event) => setMonth(Number(event.target.value))} /></label>
                    {!mine && <label className="grid min-w-56 flex-1 gap-1 text-sm"><Label htmlFor="payslip-search">Employee</Label><Input id="payslip-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name or employee ID" /></label>}
                    <Button type="submit">Show payslips</Button>
                    {canDownloadAll && <Button asChild variant="outline"><a href={archive}>Download all</a></Button>}
                </form>
                {payslips.length === 0 ? (
                    <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-slate-500">
                        {mine ? 'No processed payslip is available for your employee record.' : 'No processed payslips match this period. Payslips appear after a payroll run is processed.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="bg-blue-50 text-xs text-slate-600"><tr>{!mine && <th className="px-3 py-3">Employee ID</th>}{!mine && <th className="px-3 py-3">Employee</th>}<th className="px-3 py-3">Period</th><th className="px-3 py-3">Generated</th><th className="px-3 py-3">Net</th><th className="px-3 py-3">Payment</th><th className="px-3 py-3">Actions</th></tr></thead>
                            <tbody>
                                {payslips.map((payslip) => (
                                    <tr key={payslip.id} className="border-t border-slate-100">
                                        {!mine && <td className="px-3 py-3">{payslip.code}</td>}
                                        {!mine && <td className="px-3 py-3">{payslip.employee}</td>}
                                        <td className="px-3 py-3">{payslip.period}</td>
                                        <td className="px-3 py-3">{payslip.generatedAt ?? '—'}</td>
                                        <td className="px-3 py-3 whitespace-nowrap">{payslip.currency ?? ''} {payslip.net}</td>
                                        <td className="px-3 py-3"><StatusBadge value={payslip.paymentStatus} /></td>
                                        <td className="space-x-2 px-3 py-3 whitespace-nowrap">
                                            <Button asChild size="sm" variant="outline"><Link href={`/payslips/${payslip.id}`}>View</Link></Button>
                                            <Button asChild size="sm"><a href={`/payslips/${payslip.id}/pdf`}>Download PDF</a></Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}
