import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PayrollNav } from '@/components/hrms/payroll-nav';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link } from '@inertiajs/react';

interface PayslipRow {
    id: number;
    employee: string;
    period: string;
    currency: string | null;
    gross: string;
    deductions: string;
    deductionsConfigured: boolean;
    net: string;
    paymentStatus: string;
}

export default function PayslipsPage({ workspace, payslips = [] }: HrmsPageProps & { payslips?: PayslipRow[] }) {
    return (
        <HrmsLayout workspace={workspace} title="Payslips">
            <PageIntro title="Payslips" subtitle="Only processed payroll can be viewed or downloaded. Draft, unapproved, and unprocessed runs stay unavailable." />
            <PayrollNav workspace={workspace} />
            <SectionCard title="Finalized payslips">
                {payslips.length === 0 ? (
                    <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-slate-500">
                        {workspace.permissions.payrollView
                            ? 'No processed payslips yet. Payslips appear after a payroll run is processed.'
                            : 'No processed payslip is available for your employee record.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="bg-blue-50 text-xs text-slate-600"><tr><th className="px-3 py-3">Employee</th><th className="px-3 py-3">Period</th><th className="px-3 py-3">Gross</th><th className="px-3 py-3">Deductions</th><th className="px-3 py-3">Net</th><th className="px-3 py-3">Payment</th><th className="px-3 py-3">Actions</th></tr></thead>
                            <tbody>
                                {payslips.map((payslip) => (
                                    <tr key={payslip.id} className="border-t border-slate-100">
                                        <td className="px-3 py-3">{payslip.employee}</td>
                                        <td className="px-3 py-3">{payslip.period}</td>
                                        <td className="px-3 py-3 whitespace-nowrap">{payslip.currency ?? ''} {payslip.gross}</td>
                                        <td className="px-3 py-3">{payslip.deductionsConfigured ? payslip.deductions : 'Not configured'}</td>
                                        <td className="px-3 py-3">{payslip.net}</td>
                                        <td className="px-3 py-3"><StatusBadge value={payslip.paymentStatus} /></td>
                                        <td className="space-x-2 px-3 py-3 whitespace-nowrap">
                                            <Button asChild size="sm" variant="outline"><Link href={`/payslips/${payslip.id}`}>View</Link></Button>
                                            <Button asChild size="sm"><a href={`/payslips/${payslip.id}/pdf`}>PDF</a></Button>
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
