import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link } from '@inertiajs/react';

interface Payslip {
    id: number;
    company: string | null;
    employee: string;
    code: string;
    title: string | null;
    department: string | null;
    joined: string | null;
    period: string;
    currency: string | null;
    basic: string;
    hra: string;
    special: string;
    gross: string;
    deductions: string;
    deductionsConfigured: boolean;
    deductionNotice: string;
    net: string;
    paidOn: string | null;
    paymentStatus: string;
    paymentMethod: string | null;
    reference: string;
    generated: string;
}

export default function PayslipShow({ workspace, payslip }: HrmsPageProps & { payslip: Payslip }) {
    const money = (amount: string) => `${payslip.currency ?? ''} ${amount}`.trim();

    return (
        <HrmsLayout workspace={workspace} title="Payslip">
            <PageIntro title="Payslip" subtitle="Generated from the processed payroll record." />
            <div className="mb-4 flex gap-3">
                <Link href="/payslips" className="text-sm text-blue-600">Back</Link>
                <Button asChild size="sm"><a href={`/payslips/${payslip.id}/pdf`}>Download PDF</a></Button>
            </div>
            <article className="payslip-sheet mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div className="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
                    <img src="/brand/veltrix-logo.png" alt="Veltrix" className="h-10 w-auto" />
                    <div className="text-right text-sm">
                        <p className="font-semibold">{payslip.company}</p>
                        <p className="text-slate-500">{payslip.reference}</p>
                    </div>
                </div>
                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-slate-500">Employee</dt><dd className="font-medium">{payslip.employee} · {payslip.code}</dd></div>
                    <div><dt className="text-slate-500">Role</dt><dd className="font-medium">{payslip.title ?? 'Not recorded'}{payslip.department ? ` · ${payslip.department}` : ''}</dd></div>
                    <div><dt className="text-slate-500">Period</dt><dd className="font-medium">{payslip.period}</dd></div>
                    <div><dt className="text-slate-500">Joined</dt><dd className="font-medium">{payslip.joined ?? 'Not recorded'}</dd></div>
                    <div><dt className="text-slate-500">Payment status</dt><dd className="mt-1"><StatusBadge value={payslip.paymentStatus} /></dd></div>
                    <div><dt className="text-slate-500">Payment date</dt><dd className="font-medium">{payslip.paidOn ?? 'Not recorded'}</dd></div>
                </dl>
                <div className="mt-6 grid gap-4 sm:grid-cols-2">
                    <table className="w-full text-sm"><tbody>
                        <tr><th className="border-b border-slate-100 py-2 text-left" colSpan={2}>Earnings</th></tr>
                        <tr className="border-b border-slate-100"><td className="py-2">Basic</td><td className="py-2 text-right">{money(payslip.basic)}</td></tr>
                        <tr className="border-b border-slate-100"><td className="py-2">HRA</td><td className="py-2 text-right">{money(payslip.hra)}</td></tr>
                        <tr><td className="py-2">Special allowance</td><td className="py-2 text-right">{money(payslip.special)}</td></tr>
                    </tbody></table>
                    <table className="w-full text-sm"><tbody>
                        <tr><th className="border-b border-slate-100 py-2 text-left" colSpan={2}>Deductions</th></tr>
                        <tr className="border-b border-slate-100"><td colSpan={2} className="py-2 text-slate-600">{payslip.deductionNotice}</td></tr>
                        {payslip.deductionsConfigured && <tr><td className="py-2">Configured deductions</td><td className="py-2 text-right">{money(payslip.deductions)}</td></tr>}
                    </tbody></table>
                </div>
                <dl className="mt-6 grid gap-2 border-t border-slate-100 pt-4 text-sm sm:grid-cols-3">
                    <div className="flex justify-between"><dt>Gross</dt><dd>{money(payslip.gross)}</dd></div>
                    <div className="flex justify-between"><dt>Deductions</dt><dd>{money(payslip.deductions)}</dd></div>
                    <div className="flex justify-between font-semibold"><dt>Net payable</dt><dd>{money(payslip.net)}</dd></div>
                </dl>
                <p className="mt-4 text-xs text-slate-500">Generated {payslip.generated}. Currency: {payslip.currency ?? 'not recorded'}. Method: {payslip.paymentMethod ?? 'not recorded'}. A recorded payment is not a bank transfer.</p>
            </article>
        </HrmsLayout>
    );
}
