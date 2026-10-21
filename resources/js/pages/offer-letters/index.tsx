import { FormEvent } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { HrmsLayout } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';

interface Position {
    value: string;
    label: string;
}

interface EmployeeChoice {
    id: number;
    label: string;
}

interface Letter {
    id: number;
    employeeId: number | null;
    position: string;
    employeeName: string;
    status: string;
    version: number;
    monthly: string;
    annual: string;
    noticeMonths: number;
    preview: string | null;
}

interface IssuanceItem { key: string; label: string; state: string; action: string }
interface Issuance {
    ready: boolean;
    canIssue: boolean;
    status: string;
    draftNote: string;
    items: IssuanceItem[];
}

export default function OfferLetters({ workspace, ready, positions, employees = [], letters, issuance, notice, mailNote }: HrmsPageProps & {
    ready: boolean;
    positions: Position[];
    employees?: EmployeeChoice[];
    letters: Letter[];
    issuance: Issuance;
    notice?: string;
    mailNote: string;
}) {
    const form = useForm({
        position_code: positions[0]?.value ?? '',
        employee_id: '',
        monthly_salary: '',
        joining_date: '',
        notice_months: '3',
        hr_name: '',
        hr_signature: '',
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('hrms.offer-letters.store'));
    };
    const errors = usePage().props.errors as Record<string, string | undefined>;

    return (
        <HrmsLayout workspace={workspace} title="Offer letters">
            <SectionCard title="Offer letters">
                <p className="text-sm text-slate-600">{mailNote}</p>
                {notice && <p className="mt-2 text-sm text-slate-700">{notice}</p>}
                {errors.letter && <p className="mt-2 text-sm text-rose-700">{errors.letter}</p>}
                {ready && (
                    <div className="mt-3 rounded-lg border border-rose-100 bg-rose-50 px-3 py-3 text-sm text-rose-900">
                        <p className="font-medium">{issuance.status}</p>
                        <p className="mt-1">{issuance.draftNote}</p>
                        <ul className="mt-2 space-y-2">
                            {issuance.items.map((item) => (
                                <li key={item.key}>
                                    <span className="font-medium">{item.label}</span>
                                    <span> · {item.state === 'not_configured' ? 'Not configured' : item.state === 'incomplete' ? 'Incomplete' : 'Invalid'}</span>
                                    <span className="block">{item.action}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {!ready && <p className="mt-3 text-sm text-amber-800">Offer letter storage is not ready on this database.</p>}
                {ready && employees.length === 0 && <p className="mt-3 text-sm text-slate-600">No employees are available in this company.</p>}
                {ready && employees.length > 0 && (
                    <form onSubmit={save} className="mt-4 grid gap-3 sm:grid-cols-2">
                        <label className="text-sm" htmlFor="offer-position">Position
                            <select id="offer-position" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={form.data.position_code} onChange={(event) => form.setData('position_code', event.target.value)}>
                                {positions.map((position) => <option key={position.value} value={position.value}>{position.label}</option>)}
                            </select>
                        </label>
                        <label className="text-sm" htmlFor="offer-employee">Employee
                            <select id="offer-employee" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={form.data.employee_id} onChange={(event) => form.setData('employee_id', event.target.value)}>
                                <option value="">Select an employee</option>
                                {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.label}</option>)}
                            </select>
                            {form.errors.employee_id && <span className="mt-1 block text-rose-700">{form.errors.employee_id}</span>}
                        </label>
                        <label className="text-sm" htmlFor="offer-salary">Monthly salary (INR)
                            <input id="offer-salary" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={form.data.monthly_salary} onChange={(event) => form.setData('monthly_salary', event.target.value)} />
                            {form.errors.monthly_salary && <span className="mt-1 block text-rose-700">{form.errors.monthly_salary}</span>}
                        </label>
                        <label className="text-sm" htmlFor="offer-joining">Date of joining
                            <input id="offer-joining" type="date" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={form.data.joining_date} onChange={(event) => form.setData('joining_date', event.target.value)} />
                            {form.errors.joining_date && <span className="mt-1 block text-rose-700">{form.errors.joining_date}</span>}
                        </label>
                        <label className="text-sm" htmlFor="offer-notice">Notice period (months)
                            <input id="offer-notice" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={form.data.notice_months} onChange={(event) => form.setData('notice_months', event.target.value)} />
                        </label>
                        <label className="text-sm" htmlFor="offer-hr">HR name
                            <input id="offer-hr" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={form.data.hr_name} onChange={(event) => form.setData('hr_name', event.target.value)} />
                            {form.errors.hr_name && <span className="mt-1 block text-rose-700">{form.errors.hr_name}</span>}
                        </label>
                        <label className="text-sm sm:col-span-2" htmlFor="offer-signature">HR signature
                            <input id="offer-signature" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" value={form.data.hr_signature} onChange={(event) => form.setData('hr_signature', event.target.value)} />
                            {form.errors.hr_signature && <span className="mt-1 block text-rose-700">{form.errors.hr_signature}</span>}
                        </label>
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={form.processing}>Save draft</button>
                    </form>
                )}
            </SectionCard>
            {ready && letters.length === 0 && <p className="mt-4 text-sm text-slate-600">No offer letters yet.</p>}
            <div className="mt-4 grid gap-4 xl:grid-cols-2">
            {ready && letters.map((letter) => (
                <SectionCard key={letter.id} title={`${letter.position} · ${letter.employeeName}`}>
                    <p className="text-sm text-slate-600">Version {letter.version}. Monthly {letter.monthly}. Annual {letter.annual}. Notice {letter.noticeMonths} months.</p>
                    <p className="mt-1 text-sm text-slate-600">{letter.employeeId === null ? 'No employee record is linked.' : `Linked employee record ${letter.employeeId}.`}</p>
                    <div className="mt-2"><StatusBadge value={letter.status} /></div>
                    {letter.preview && (
                        <div className="mt-3">
                            {['Draft', 'Generated'].includes(letter.status) && <p className="mb-2 text-sm text-rose-800">{issuance.draftNote}</p>}
                            <pre className="whitespace-pre-wrap text-xs text-slate-700">{letter.preview}</pre>
                        </div>
                    )}
                    <div className="mt-3 flex flex-wrap gap-2">
                        {letter.status === 'Draft' && (
                            <button className="rounded-xl border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.preview', letter.id))}>Preview</button>
                        )}
                        {letter.status === 'Generated' && issuance.canIssue && (
                            <button className="rounded-xl bg-blue-700 px-3 py-2 text-sm text-white" type="button" onClick={() => router.post(route('hrms.offer-letters.issue', letter.id), { confirm: 'ISSUE' })}>Issue</button>
                        )}
                        {letter.status === 'Issued' && (
                            <>
                                <button className="rounded-xl border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.decide', letter.id), { status: 'accepted', confirm: 'ACCEPT' })}>Accept</button>
                                <button className="rounded-xl border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.decide', letter.id), { status: 'declined', confirm: 'DECLINE' })}>Decline</button>
                            </>
                        )}
                        {['Issued', 'Accepted', 'Declined'].includes(letter.status) && (
                            <>
                                <button className="rounded-xl border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.revise', letter.id), { confirm: 'REVISE' })}>New version</button>
                                <a className="rounded-xl border border-slate-200 px-3 py-2 text-sm" href={route('hrms.offer-letters.pdf', letter.id)}>Download</a>
                            </>
                        )}
                    </div>
                </SectionCard>
            ))}
            </div>
        </HrmsLayout>
    );
}
