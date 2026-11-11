import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface AttendanceRow {
    id: number | null;
    name: string;
    office: string | null;
    date: string;
    clockIn: string | null;
    clockOut: string | null;
    breakLabel: string;
    workLabel: string;
    late: boolean;
    early: boolean;
    status: string;
    holidayName?: string | null;
    clockInIp: string | null;
    clockOutIp: string | null;
    clockInDistance: number | null;
    clockOutDistance: number | null;
    verification: string | null;
    networkStatus: string;
    recoverable: boolean;
}

interface Roster {
    date: string;
    offices: { id: number; name: string }[];
    rows: AttendanceRow[];
    counts: { showing: number; present: number; review: number; pendingCorrections?: number };
}

interface Correction {
    id: number;
    employee: string;
    code: string;
    department: string | null;
    date: string;
    type: string;
    reason: string;
    status: string;
    statusValue: string;
    requestedIn: string | null;
    requestedOut: string | null;
    existingIn: string | null;
    existingOut: string | null;
    reviewReason: string | null;
    submitted: string | null;
}

interface Person { id: number; name: string; code: string }
interface Authorization { id: number; employee: string; code: string; date: string; kind: string; reason: string | null }

export default function AttendanceManagePage({ workspace, timing, roster, filters, canCorrect, canExport, corrections = [], authorizations = [], people = [], notice }: HrmsPageProps & {
    timing?: TimingState;
    roster: Roster;
    filters: { date: string; office: string; status: string; employee: string };
    canCorrect: boolean;
    canExport: boolean;
    corrections?: Correction[];
    authorizations?: Authorization[];
    people?: Person[];
    notice?: string | null;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(roster.rows.find((row) => row.id)?.id ?? null);
    const [month, setMonth] = useState((filters.date || roster.date).slice(0, 7));
    const selected = roster.rows.find((row) => row.id === selectedId) ?? null;
    const [correct, setCorrect] = useState(false);
    const [detail, setDetail] = useState<Correction | null>(null);
    const form = useForm({ reason: '' });
    const review = useForm({ review_reason: '' });
    const holiday = useForm({ employee_id: '', work_date: '', kind: 'holiday', reason: '' });

    function apply(next: Partial<typeof filters>) {
        router.get(route('hrms.attendance'), { ...filters, date: filters.date || roster.date, ...next }, { preserveState: true, preserveScroll: true });
    }

    return (
        <HrmsLayout workspace={workspace} title="Attendance">
            <PageIntro title="Attendance" subtitle={`Saved attendance for ${workspace.companyName ?? 'your company'}. Network approval is checked on the server.`} />
            {timing && (
                <div className="mb-4 max-w-xl">
                    <TimingCard timing={timing} />
                </div>
            )}
            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Summary label="Showing" value={String(roster.counts.showing)} />
                <Summary label="In today" value={String(roster.counts.present)} />
                <Summary label="Needs review" value={String(roster.counts.review)} />
                {canCorrect && <Summary label="Pending corrections" value={String(roster.counts.pendingCorrections ?? 0)} />}
            </div>
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
            <SectionCard title="Employee attendance">
                <div className="mb-3 grid gap-2 md:grid-cols-4">
                    <Input type="date" value={filters.date || roster.date} aria-label="Date" onChange={(event) => apply({ date: event.target.value })} />
                    <Input value={filters.employee} onChange={(event) => apply({ employee: event.target.value })} placeholder="Employee" aria-label="Employee" />
                    <select aria-label="Office" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={filters.office} onChange={(event) => apply({ office: event.target.value })}>
                        <option value="">All offices</option>
                        {roster.offices.map((office) => <option key={office.id} value={office.id}>{office.name}</option>)}
                    </select>
                    <select aria-label="Status" className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={filters.status} onChange={(event) => apply({ status: event.target.value })}>
                        {['All statuses', 'Present', 'Late', 'On break', 'Early out', 'Absent', 'Recovered', 'Holiday', 'Holiday Work', 'Weekly Off'].map((option) => <option key={option}>{option}</option>)}
                    </select>
                </div>
                {notice && <p className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{notice}</p>}
                {roster.rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-slate-500">No attendance rows match these filters.</p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="w-full min-w-[880px] text-left text-sm">
                            <thead className="bg-blue-50 text-xs text-slate-600">
                                <tr>
                                    <th className="px-3 py-3 font-medium">Employee</th>
                                    <th className="px-3 py-3 font-medium">Clock in</th>
                                    <th className="px-3 py-3 font-medium">Clock out</th>
                                    <th className="px-3 py-3 font-medium">Break</th>
                                    <th className="px-3 py-3 font-medium">Work</th>
                                    <th className="px-3 py-3 font-medium">Flags</th>
                                    <th className="px-3 py-3 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {roster.rows.map((row) => (
                                    <tr key={`${row.date}-${row.name}`} className={`cursor-pointer border-t border-slate-100 hover:bg-blue-50/60 ${selectedId === row.id ? 'bg-blue-50' : ''}`} onClick={() => setSelectedId(row.id)}>
                                        <td className="px-3 py-3 font-medium">{row.name}<span className="block text-xs font-normal text-slate-500">{row.office ?? 'No office'}{row.holidayName ? ` · ${row.holidayName}` : ''}</span></td>
                                        <td className="px-3 py-3">{row.clockIn ?? '—'}</td>
                                        <td className="px-3 py-3">{row.clockOut ?? '—'}</td>
                                        <td className="px-3 py-3">{row.breakLabel}</td>
                                        <td className="px-3 py-3">{row.workLabel}</td>
                                        <td className="px-3 py-3 text-xs">{row.late && <span className="mr-2 text-amber-600">Late</span>}{row.early && <span className="text-rose-600">Early out</span>}{!row.late && !row.early && '—'}</td>
                                        <td className="px-3 py-3"><StatusBadge value={row.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <div className="mt-4 flex flex-wrap gap-2">
                    {canCorrect && (
                        <Button type="button" variant="outline" disabled={!selected?.recoverable} onClick={() => { form.reset(); form.clearErrors(); setCorrect(true); }}>
                            Close interrupted session
                        </Button>
                    )}
                    {canExport && (
                        <>
                            <Input type="month" aria-label="Attendance month" className="w-40" value={month} onChange={(event) => setMonth(event.target.value)} />
                            <Button type="button" variant="outline" disabled={month === ''} onClick={() => { window.location.href = route('hrms.attendance.export', { month, office: filters.office, employee: filters.employee }); }}>
                                Download month
                            </Button>
                            <Button type="button" variant="outline" onClick={() => { window.location.href = route('hrms.attendance.export', { date: filters.date || roster.date, office: filters.office, status: filters.status, employee: filters.employee }); }}>
                                Export day
                            </Button>
                        </>
                    )}
                </div>
            </SectionCard>
                <SectionCard title="Attendance detail">
                    {selected ? (
                        <dl className="grid gap-3 text-sm">
                            <Item label="Date" value={selected.date} />
                            <Item label="Employee" value={selected.name} />
                            <Item label="Clock in" value={selected.clockIn ?? '—'} />
                            <Item label="Clock out" value={selected.clockOut ?? '—'} />
                            <Item label="Break" value={selected.breakLabel} />
                            <Item label="Work" value={selected.workLabel} />
                            <Item label="Clock-in network" value={selected.clockInIp ? `${selected.clockInIp} · ${selected.networkStatus}` : selected.networkStatus} />
                            <Item label="Clock-out network" value={selected.clockOutIp ?? 'Not recorded'} />
                            <Item label="Verification" value={selected.verification ?? 'Not recorded'} />
                            {selected.clockInDistance !== null && <Item label="Clock-in distance" value={`${selected.clockInDistance} m`} />}
                            {selected.clockOutDistance !== null && <Item label="Clock-out distance" value={`${selected.clockOutDistance} m`} />}
                        </dl>
                    ) : (
                        <p className="text-sm text-slate-500">Select a saved attendance row to review it.</p>
                    )}
                    <p className="mt-3 text-xs text-slate-500">The clock-in and clock-out addresses are the addresses observed by the server. A browser-supplied address is not trusted.</p>
                </SectionCard>
            </div>
            <Dialog open={correct} onOpenChange={setCorrect}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close interrupted session</DialogTitle>
                        <DialogDescription>This sets a clock-out time for {selected?.name}. The original clock-in time is not changed.</DialogDescription>
                    </DialogHeader>
                    <Label htmlFor="reason">Reason</Label>
                    <Input id="reason" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    {(form.errors.reason || form.errors.attendance) && <p className="text-sm text-rose-600">{form.errors.reason ?? form.errors.attendance}</p>}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setCorrect(false)}>Cancel</Button>
                        <Button
                            type="button"
                            disabled={form.processing || !selected?.id}
                            onClick={() => selected?.id && form.post(route('hrms.attendance.recover', selected.id), { preserveScroll: true, onSuccess: () => setCorrect(false) })}
                        >
                            Close session
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {canCorrect && (
                <div className="mt-4 grid gap-4 xl:grid-cols-2">
                    <SectionCard title="Attendance correction requests">
                        {corrections.length === 0 ? <p className="text-sm text-slate-500">No correction requests.</p> : (
                            <div className="grid gap-2">
                                {corrections.map((item) => (
                                    <div key={item.id} className="rounded-xl border border-slate-100 px-3 py-2 text-sm">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium">{item.employee} · {item.code}</span>
                                            <StatusBadge value={item.status} />
                                        </div>
                                        <p className="text-xs text-slate-500">{item.department ?? 'No department'} · {item.date} · {item.type} · submitted {item.submitted ?? '—'}</p>
                                        <p className="mt-1 text-slate-600">{item.reason}</p>
                                        <p className="text-xs text-slate-500">Existing {item.existingIn ?? '—'} to {item.existingOut ?? '—'} · Requested {item.requestedIn ?? '—'} to {item.requestedOut ?? '—'}</p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <Button type="button" size="sm" variant="outline" onClick={() => { review.reset(); setDetail(item); }}>View details</Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </SectionCard>
                    <SectionCard title="Holiday work authorization">
                        <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); holiday.post(route('hrms.attendance.holiday-work'), { preserveScroll: true, onSuccess: () => holiday.reset('reason') }); }}>
                            <label className="grid gap-1 text-sm">Employee
                                <select className="h-10 rounded-md border px-3" value={holiday.data.employee_id} onChange={(event) => holiday.setData('employee_id', event.target.value)}>
                                    <option value="">Select employee</option>
                                    {people.map((person) => <option key={person.id} value={person.id}>{person.name} · {person.code}</option>)}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">Date<input type="date" className="h-10 rounded-md border px-3" value={holiday.data.work_date} onChange={(event) => holiday.setData('work_date', event.target.value)} /></label>
                            <label className="grid gap-1 text-sm">Kind
                                <select className="h-10 rounded-md border px-3" value={holiday.data.kind} onChange={(event) => holiday.setData('kind', event.target.value)}>
                                    <option value="holiday">Holiday</option>
                                    <option value="weekly_off">Weekly off</option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">Reason (optional)<input className="h-10 rounded-md border px-3" value={holiday.data.reason} onChange={(event) => holiday.setData('reason', event.target.value)} /></label>
                            {Object.values(holiday.errors)[0] && <p className="text-sm text-rose-600">{Object.values(holiday.errors)[0]}</p>}
                            <Button type="submit" disabled={holiday.processing}>Authorize this employee</Button>
                        </form>
                        <div className="mt-4 grid gap-2">
                            {authorizations.map((item) => (
                                <p key={item.id} className="text-sm text-slate-600">{item.employee} · {item.code} · {item.date} · {item.kind}{item.reason ? ` · ${item.reason}` : ''}</p>
                            ))}
                        </div>
                    </SectionCard>
                </div>
            )}
            <Dialog open={detail !== null} onOpenChange={(open) => { if (!open) setDetail(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Correction request</DialogTitle>
                        <DialogDescription>{detail?.employee} · {detail?.code} · {detail?.date}</DialogDescription>
                    </DialogHeader>
                    {detail && (
                        <div className="grid gap-2 text-sm">
                            <p>{detail.department ?? 'No department'} · {detail.type} · {detail.status}</p>
                            <p>Existing clock-in {detail.existingIn ?? '—'} · clock-out {detail.existingOut ?? '—'}</p>
                            <p>Requested clock-in {detail.requestedIn ?? '—'} · clock-out {detail.requestedOut ?? '—'}</p>
                            <p>Employee reason: {detail.reason}</p>
                            <p>Submitted {detail.submitted ?? '—'}</p>
                            {detail.reviewReason && <p>HR note: {detail.reviewReason}</p>}
                            {detail.statusValue === 'pending' && (
                                <>
                                    <Label htmlFor="review">HR reason</Label>
                                    <Input id="review" value={review.data.review_reason} onChange={(event) => review.setData('review_reason', event.target.value)} />
                                    {(review.errors.review_reason || review.errors.attendance) && <p className="text-sm text-rose-600">{review.errors.review_reason ?? review.errors.attendance}</p>}
                                </>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setDetail(null)}>Close</Button>
                        {detail?.statusValue === 'pending' && (
                            <>
                                <Button type="button" variant="outline" disabled={review.processing} onClick={() => review.post(route('hrms.attendance.corrections.reject', detail.id), { preserveScroll: true, onSuccess: () => setDetail(null) })}>Reject</Button>
                                <Button type="button" disabled={review.processing} onClick={() => review.post(route('hrms.attendance.corrections.approve', detail.id), { preserveScroll: true, onSuccess: () => setDetail(null) })}>Approve</Button>
                            </>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </HrmsLayout>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm"><p className="text-xs text-slate-500">{label}</p><p className="text-xl font-semibold">{value}</p></div>;
}

function Item({ label, value }: { label: string; value: string }) {
    return <div className="rounded-xl border border-slate-100 px-3 py-2"><dt className="text-xs text-slate-500">{label}</dt><dd className="font-medium">{value}</dd></div>;
}
