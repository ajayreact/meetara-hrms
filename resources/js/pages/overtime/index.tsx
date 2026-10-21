import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { useForm } from '@inertiajs/react';

interface Entry {
    id: number;
    employee: string;
    date: string;
    requestedHours: string;
    status: string;
    rawStatus: string;
    reason: string | null;
    payroll: string;
}

export default function OvertimePage({ workspace, entries = [], canReview = false, notice, error }: HrmsPageProps & { entries?: Entry[]; canReview?: boolean; notice?: string | null; error?: string | null }) {
    const form = useForm({ work_date: '', hours: '', reason: '' });
    const decision = useForm({ decision: '' });

    return (
        <HrmsLayout workspace={workspace} title="Overtime">
            <PageIntro title="Overtime" subtitle="Requests are saved. Approved hours are not paid until an overtime rate is configured." />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="grid gap-4 lg:grid-cols-[0.8fr_1.2fr]">
                <SectionCard title="Request">
                    <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); form.post('/overtime'); }}>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="ot-date">Date</Label><Input id="ot-date" type="date" value={form.data.work_date} onChange={(event) => form.setData('work_date', event.target.value)} /><InputError message={form.errors.work_date} /></label>
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="ot-hours">Hours</Label><Input id="ot-hours" value={form.data.hours} onChange={(event) => form.setData('hours', event.target.value)} placeholder="1 or 1.5" /><InputError message={form.errors.hours} /></label>
                        </div>
                        <label className="grid gap-1.5 text-sm"><Label htmlFor="ot-reason">Reason</Label><textarea id="ot-reason" className="min-h-20 rounded-md border border-input px-3 py-2" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} /><InputError message={form.errors.reason} /></label>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Working…' : 'Submit request'}</Button>
                    </form>
                </SectionCard>
                <SectionCard title="Requests">
                    {entries.length === 0 ? <p className="text-sm text-slate-500">No overtime requests yet.</p> : (
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full min-w-[40rem] text-left text-sm">
                                <thead className="bg-blue-50 text-xs text-slate-600">
                                    <tr>
                                        <th className="px-3 py-3 font-medium">Employee</th>
                                        <th className="px-3 py-3 font-medium">Date</th>
                                        <th className="px-3 py-3 font-medium">Hours</th>
                                        <th className="px-3 py-3 font-medium">Payroll</th>
                                        <th className="px-3 py-3 font-medium">Status</th>
                                        <th className="px-3 py-3 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((entry) => (
                                        <tr key={entry.id} className="border-t border-slate-100 align-top">
                                            <td className="px-3 py-3">
                                                <span className="font-medium">{entry.employee}</span>
                                                {entry.reason && <span className="mt-1 block text-xs text-slate-500">{entry.reason}</span>}
                                            </td>
                                            <td className="px-3 py-3 whitespace-nowrap">{entry.date}</td>
                                            <td className="px-3 py-3">{entry.requestedHours}</td>
                                            <td className="px-3 py-3">{entry.payroll}</td>
                                            <td className="px-3 py-3"><StatusBadge value={entry.status} /></td>
                                            <td className="px-3 py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    {canReview && entry.rawStatus === 'pending' && (
                                                        <>
                                                            <Button type="button" size="sm" disabled={decision.processing} onClick={() => { decision.transform(() => ({ decision: 'approved' })); decision.post(`/overtime/${entry.id}/decide`); }}>Approve</Button>
                                                            <Button type="button" size="sm" variant="outline" disabled={decision.processing} onClick={() => { decision.transform(() => ({ decision: 'rejected' })); decision.post(`/overtime/${entry.id}/decide`); }}>Reject</Button>
                                                        </>
                                                    )}
                                                    {entry.rawStatus === 'pending' && <Button type="button" size="sm" variant="outline" disabled={decision.processing} onClick={() => decision.post(`/overtime/${entry.id}/cancel`)}>Cancel</Button>}
                                                </div>
                                            </td>
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
