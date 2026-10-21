import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface HistoryRow {
    id: number;
    effectiveFrom: string;
    currency: string | null;
    basic: string;
    hra: string;
    special: string;
}

interface SalaryRow {
    id: number;
    name: string;
    code: string;
    status: string;
    history: HistoryRow[];
}

export default function SalaryPage({ workspace, rows = [], suggestedCurrency = null, notice, error }: HrmsPageProps & { rows?: SalaryRow[]; suggestedCurrency?: string | null; notice?: string | null; error?: string | null }) {
    const [confirmSave, setConfirmSave] = useState(false);
    const form = useForm({
        employee_id: rows[0]?.id ?? '',
        basic: '',
        hra: '0.00',
        special_allowance: '0.00',
        effective_from: '',
        currency: suggestedCurrency ?? '',
    });

    return (
        <HrmsLayout workspace={workspace} title="Salary">
            <PageIntro title="Salary" subtitle="Each change is a new effective date. Earlier salary records stay in place." />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
                <SectionCard title="New salary record">
                    <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); form.post('/salary'); }}>
                        <label className="grid gap-1.5 text-sm">
                            <Label htmlFor="salary-employee">Employee</Label>
                            <select id="salary-employee" className="h-10 rounded-md border border-input px-3" value={form.data.employee_id} onChange={(event) => form.setData('employee_id', Number(event.target.value))}>
                                {rows.map((row) => <option key={row.id} value={row.id}>{row.name} · {row.code}</option>)}
                            </select>
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="basic">Basic</Label><Input id="basic" value={form.data.basic} onChange={(event) => form.setData('basic', event.target.value)} /><InputError message={form.errors.basic} /></label>
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="hra">HRA</Label><Input id="hra" value={form.data.hra} onChange={(event) => form.setData('hra', event.target.value)} /><InputError message={form.errors.hra} /></label>
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="special">Special allowance</Label><Input id="special" value={form.data.special_allowance} onChange={(event) => form.setData('special_allowance', event.target.value)} /><InputError message={form.errors.special_allowance} /></label>
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="effective">Effective from</Label><Input id="effective" type="date" value={form.data.effective_from} onChange={(event) => form.setData('effective_from', event.target.value)} /><InputError message={form.errors.effective_from} /></label>
                        </div>
                        <label className="grid max-w-40 gap-1.5 text-sm"><Label htmlFor="currency">Currency</Label><Input id="currency" maxLength={3} value={form.data.currency} onChange={(event) => form.setData('currency', event.target.value.toUpperCase())} placeholder="Three-letter code" /><InputError message={form.errors.currency} /></label>
                        <p className="text-xs text-slate-500">Earlier salary records stay in place. Recurring deductions and statutory rates are not configured, so they are not calculated here.{suggestedCurrency ? ` The company default for a new record is ${suggestedCurrency}.` : ''}</p>
                        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={confirmSave} onChange={(event) => setConfirmSave(event.target.checked)} /> Save this as a new salary record. It does not replace earlier effective dates.</label>
                        <Button type="submit" disabled={form.processing || rows.length === 0 || !confirmSave}>{form.processing ? 'Working…' : 'Save salary record'}</Button>
                    </form>
                </SectionCard>
                <SectionCard title="History" extra={<Input aria-label="Search employees" className="h-9 w-40" placeholder="Search" onChange={(event) => router.get('/salary', { search: event.target.value }, { preserveState: true, replace: true })} />}>
                    {rows.length === 0 ? <p className="text-sm text-slate-500">No employees are available for salary records.</p> : (
                        <ul className="space-y-4">
                            {rows.map((row) => (
                                <li key={row.id} className="rounded-xl border border-slate-100 p-3 text-sm">
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="font-medium">{row.name} · {row.code}</span>
                                        <StatusBadge value={row.status} />
                                    </div>
                                    {row.history.length === 0 ? <p className="mt-2 text-slate-500">No salary record yet.</p> : (
                                        <div className="mt-2 overflow-x-auto">
                                            <table className="w-full min-w-[28rem] text-left text-xs">
                                                <thead className="text-slate-500">
                                                    <tr>
                                                        <th className="py-1 font-medium">Effective</th>
                                                        <th className="py-1 font-medium">Currency</th>
                                                        <th className="py-1 font-medium">Basic</th>
                                                        <th className="py-1 font-medium">HRA</th>
                                                        <th className="py-1 font-medium">Special</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {row.history.map((item, index) => (
                                                        <tr key={item.id} className="border-t border-slate-100">
                                                            <td className="py-1.5">{item.effectiveFrom}{index === 0 ? ' · Latest' : ''}</td>
                                                            <td>{item.currency ?? 'No currency'}</td>
                                                            <td>{item.basic}</td>
                                                            <td>{item.hra}</td>
                                                            <td>{item.special}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
