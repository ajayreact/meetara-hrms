import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface PaymentRow {
    id: number;
    employee: string;
    period: string;
    amount: string;
    currency: string | null;
    status: string;
    method: string | null;
    reference: string | null;
    paidOn: string | null;
    note: string | null;
}

const filters = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'paid', label: 'Paid' },
    { value: 'reversed', label: 'Reversed' },
];

export default function PaymentsPage({ workspace, payments = [], filter = '', notice, error }: HrmsPageProps & { payments?: PaymentRow[]; filter?: string; notice?: string | null; error?: string | null }) {
    return (
        <HrmsLayout workspace={workspace} title="Payments">
            <PageIntro title="Payments" subtitle="Recording a payment confirms it in Meetara. It does not send money to a bank." />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <SectionCard title="Payroll payments">
                <div className="mb-4 flex flex-wrap gap-2">
                    {filters.map((item) => (
                        <Link key={item.value || 'all'} href={item.value ? `/payments?status=${item.value}` : '/payments'} className={`rounded-full px-3 py-1 text-xs ${filter === item.value ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700'}`}>{item.label}</Link>
                    ))}
                </div>
                {payments.length === 0 ? <p className="text-sm text-slate-500">No payments are waiting.</p> : (
                    <ul className="grid gap-4 xl:grid-cols-2">
                        {payments.map((payment) => <PaymentEditor key={payment.id} payment={payment} />)}
                    </ul>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}

function PaymentEditor({ payment }: { payment: PaymentRow }) {
    const form = useForm({ paid_on: payment.paidOn ?? '', method: payment.method ?? 'bank_transfer', reference: payment.reference ?? '', note: '' });
    const [confirmPay, setConfirmPay] = useState(false);

    return (
        <li className="rounded-xl border border-slate-100 p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="font-medium">{payment.employee} · {payment.period} · {payment.currency ?? ''} {payment.amount}</span>
                <StatusBadge value={payment.status} />
            </div>
            {(payment.paidOn || payment.method || payment.reference) && (
                <p className="mt-2 text-slate-600">{payment.paidOn ?? 'No date'} · {payment.method ?? 'No method'}{payment.reference ? ` · ${payment.reference}` : ''}</p>
            )}
            {payment.note && <p className="mt-1 text-slate-600">Note: {payment.note}</p>}
            {payment.status === 'Pending' && (
                <form className="mt-3 grid gap-2 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post(`/payments/${payment.id}`); }}>
                    <Input aria-label="Payment date" type="date" value={form.data.paid_on} onChange={(event) => form.setData('paid_on', event.target.value)} />
                    <select aria-label="Payment method" className="h-10 rounded-md border border-input px-3" value={form.data.method} onChange={(event) => form.setData('method', event.target.value)}>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="cash">Cash</option>
                        <option value="other">Other</option>
                    </select>
                    <Input aria-label="Reference" value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} placeholder="Reference" />
                    <label className="flex items-center gap-2 md:col-span-2"><input type="checkbox" checked={confirmPay} onChange={(event) => setConfirmPay(event.target.checked)} /> This records a confirmation inside Meetara. It is not a bank transfer.</label>
                    <InputError message={form.errors.paid_on || form.errors.reference || form.errors.method} />
                    <Button type="submit" disabled={form.processing || !confirmPay}>{form.processing ? 'Working…' : 'Record payment'}</Button>
                </form>
            )}
            {payment.status === 'Paid' && (
                <form className="mt-3 flex flex-wrap gap-2" onSubmit={(event) => { event.preventDefault(); form.post(`/payments/${payment.id}/reverse`); }}>
                    <Input aria-label="Reversal note" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} placeholder="Reason for reversal" />
                    <Button type="submit" variant="outline" disabled={form.processing}>{form.processing ? 'Working…' : 'Reverse'}</Button>
                    <InputError message={form.errors.note} />
                </form>
            )}
            {payment.status === 'Reversed' && <p className="mt-2 text-slate-500">This confirmation was reversed. A second payment row is not created.</p>}
        </li>
    );
}
