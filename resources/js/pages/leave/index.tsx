import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { useForm } from '@inertiajs/react';

interface Balance {
    id: number;
    code: string;
    name: string;
    booked: string;
    remaining: string;
    recorded: boolean;
}

interface LeaveTypeOption {
    id: number;
    name: string;
    code: string;
}

interface HistoryEvent {
    action: string;
    note: string | null;
    at: string;
}

interface LeaveRow {
    id: number;
    employee: string | null;
    type: string | null;
    start: string;
    end: string;
    days: string;
    reason: string | null;
    status: string;
    reviewerNote: string | null;
    canCancel: boolean;
    canReview: boolean;
    history: HistoryEvent[];
}

interface PolicyRow {
    id: number;
    name: string;
    accrual: string;
    applicableAfterDays: number;
    type: string | null;
}

export default function LeavePage({
    workspace,
    balances = [],
    types = [],
    requests = [],
    queue = [],
    policies = [],
    canApply = false,
    canReview = false,
    canManagePolicies = false,
    halfDaySupported = false,
    notice,
    error,
}: HrmsPageProps & {
    balances?: Balance[];
    types?: LeaveTypeOption[];
    requests?: LeaveRow[];
    queue?: LeaveRow[];
    policies?: PolicyRow[];
    canApply?: boolean;
    canReview?: boolean;
    canManagePolicies?: boolean;
    halfDaySupported?: boolean;
    notice?: string | null;
    error?: string | null;
}) {
    const form = useForm({
        leave_type_id: types[0]?.id ?? '',
        start_date: '',
        end_date: '',
        reason: '',
    });

    return (
        <HrmsLayout workspace={workspace} title="Leave">
            <PageIntro title="Leave" subtitle={`${workspace.companyName ?? 'Company'} leave types, balances, and requests.`} />
            {(notice || error) && (
                <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>
            )}
            <div className="grid gap-4 xl:grid-cols-[minmax(16rem,0.8fr)_minmax(0,1.2fr)]">
                <SectionCard title="Balances">
                    {balances.length === 0 ? (
                        <p className="text-sm text-slate-500">No leave balances are recorded for this account.</p>
                    ) : (
                        <ul className="space-y-3">
                            {balances.map((leave) => (
                                <li key={leave.id} className="flex items-center justify-between rounded-xl border border-slate-100 px-3 py-2 text-sm">
                                    <span>
                                        <span className="font-medium">{leave.name}</span>
                                        <span className="block text-xs text-slate-500">{leave.recorded ? `Booked ${leave.booked}` : 'No balance recorded'}</span>
                                    </span>
                                    <span className="font-semibold">{leave.recorded ? `${leave.remaining} left` : '—'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
                <SectionCard title="Apply">
                    {canApply ? (
                        <form
                            className="grid gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/leave');
                            }}
                        >
                            <label className="grid gap-1.5 text-sm">
                                <Label htmlFor="leave-type">Leave type</Label>
                                <select
                                    id="leave-type"
                                    className="h-10 rounded-md border border-input px-3"
                                    value={form.data.leave_type_id}
                                    onChange={(event) => form.setData('leave_type_id', Number(event.target.value))}
                                >
                                    {types.map((type) => (
                                        <option key={type.id} value={type.id}>{type.name}</option>
                                    ))}
                                </select>
                            </label>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="grid gap-1.5 text-sm"><Label htmlFor="from">From</Label><Input id="from" type="date" value={form.data.start_date} onChange={(event) => form.setData('start_date', event.target.value)} /></label>
                                <label className="grid gap-1.5 text-sm"><Label htmlFor="to">To</Label><Input id="to" type="date" value={form.data.end_date} onChange={(event) => form.setData('end_date', event.target.value)} /></label>
                            </div>
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="reason">Reason</Label><Input id="reason" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} /></label>
                            {form.errors.reason && <p className="text-sm text-rose-600">{form.errors.reason}</p>}
                            <p className="text-xs text-slate-500">Working days exclude the company weekend and holidays on your calendar. {halfDaySupported ? 'Half days are available.' : 'Half-day leave is not configured.'}</p>
                            <Button type="submit" disabled={form.processing || types.length === 0}>{form.processing ? 'Submitting…' : 'Submit request'}</Button>
                        </form>
                    ) : (
                        <p className="text-sm text-slate-500">This account cannot submit leave requests.</p>
                    )}
                </SectionCard>
            </div>
            <div className={`mt-4 grid gap-4 ${canReview ? 'xl:grid-cols-2' : ''}`}>
                <SectionCard title="History">
                    <RequestTable rows={requests} empty="No leave requests yet." showCancel />
                </SectionCard>
                {canReview && (
                    <SectionCard title="Review queue">
                        <RequestTable rows={queue} empty="No requests are waiting for you." showReview />
                    </SectionCard>
                )}
            </div>
            {canManagePolicies && (
                <div className="mt-4">
                    <SectionCard title="Leave policies">
                        {policies.length === 0 ? (
                            <p className="text-sm text-slate-500">No leave policies are recorded.</p>
                        ) : (
                            <ul className="space-y-3">
                                {policies.map((policy) => (
                                    <PolicyEditor key={policy.id} policy={policy} />
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>
            )}
        </HrmsLayout>
    );
}

function RequestTable({ rows, empty, showCancel = false, showReview = false }: { rows: LeaveRow[]; empty: string; showCancel?: boolean; showReview?: boolean }) {
    if (rows.length === 0) {
        return <p className="text-sm text-slate-500">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto rounded-xl border border-slate-200">
            <table className="w-full min-w-[40rem] text-left text-sm">
                <thead className="bg-blue-50 text-xs text-slate-600">
                    <tr>
                        {showReview && <th className="px-3 py-3 font-medium">Employee</th>}
                        <th className="px-3 py-3 font-medium">Type</th>
                        <th className="px-3 py-3 font-medium">Dates</th>
                        <th className="px-3 py-3 font-medium">Days</th>
                        <th className="px-3 py-3 font-medium">Status</th>
                        <th className="px-3 py-3 font-medium">Approval</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((item) => (
                        <tr key={item.id} className="border-t border-slate-100 align-top">
                            {showReview && <td className="px-3 py-3">{item.employee}</td>}
                            <td className="px-3 py-3">{item.type ?? '—'}</td>
                            <td className="px-3 py-3 whitespace-nowrap">{item.start} – {item.end}</td>
                            <td className="px-3 py-3">{item.days}</td>
                            <td className="px-3 py-3"><StatusBadge value={item.status} /></td>
                            <td className="max-w-xs px-3 py-3">
                                <p className="text-slate-600">{item.reviewerNote || item.reason || '—'}</p>
                                {item.history.length > 0 && (
                                    <ul className="mt-1 space-y-1 text-xs text-slate-500">
                                        {item.history.map((event) => (
                                            <li key={`${item.id}-${event.action}-${event.at}`}>{event.action} · {event.at}</li>
                                        ))}
                                    </ul>
                                )}
                                {showCancel && item.canCancel && <CancelButton id={item.id} />}
                                {showReview && item.canReview && <ReviewButtons id={item.id} />}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function CancelButton({ id }: { id: number }) {
    const form = useForm({ note: '' });

    return (
        <Button type="button" variant="outline" className="mt-2" disabled={form.processing} onClick={() => form.post(`/leave/${id}/cancel`)}>
            Cancel request
        </Button>
    );
}

function ReviewButtons({ id }: { id: number }) {
    const form = useForm({ note: '' });

    return (
        <div className="mt-2 grid gap-2">
            <Input aria-label="Decision note" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} placeholder="Reason" />
            <div className="flex gap-2">
                <Button type="button" disabled={form.processing} onClick={() => form.post(`/leave/${id}/approve`)}>Approve</Button>
                <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.post(`/leave/${id}/reject`)}>Reject</Button>
            </div>
        </div>
    );
}

function PolicyEditor({ policy }: { policy: PolicyRow }) {
    const form = useForm({ applicable_after_days: policy.applicableAfterDays });

    return (
        <li className="flex flex-wrap items-end justify-between gap-3 rounded-xl border border-slate-100 px-3 py-2 text-sm">
            <span>
                <span className="block font-medium">{policy.type ?? policy.name}</span>
                <span className="text-xs text-slate-500">Accrual {policy.accrual}</span>
            </span>
            <form
                className="flex items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/leave/policies/${policy.id}`);
                }}
            >
                <label className="grid gap-1 text-xs text-slate-500">
                    Available after days
                    <Input className="h-9 w-24" type="number" min={0} max={365} value={form.data.applicable_after_days} onChange={(event) => form.setData('applicable_after_days', Number(event.target.value))} />
                </label>
                <Button type="submit" variant="outline" disabled={form.processing}>Save</Button>
            </form>
        </li>
    );
}
