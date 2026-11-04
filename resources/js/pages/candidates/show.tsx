import { FormEvent, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';

interface Submission {
    id: number;
    reference: string;
    status: string;
    statusLabel: string;
    transitions: string[];
    statuses: Record<string, string>;
    submittedAt: string;
    noticeVersion: string;
    consentedAt: string;
    personal: Record<string, string>;
    employment: Record<string, string>;
    authorization: Record<string, string>;
    ssnMask: string;
    banking: { bankStatus: string; accountMask: string; routingMask: string };
    canRevealBanking: boolean;
    training: Record<string, string>;
    documents: Array<{ id: number; label: string; image: boolean }>;
    missing: string[];
    reviewNote: string;
    changeRequest: string;
    operations: { paymentReceived: boolean; amountPaid: string; paymentMethod: string; trainingAgreementSent: boolean; workDay: string };
    paymentMethods: Record<string, string>;
    events: Array<{ action: string; subject: string; actor: string; at: string }>;
}

function Facts({ values }: { values: Record<string, string> }) {
    return (
        <dl className="grid gap-3 sm:grid-cols-2">
            {Object.entries(values).map(([label, value]) => (
                <div key={label}>
                    <dt className="text-xs text-slate-500">{label}</dt>
                    <dd className="text-sm text-slate-800">{value || 'Not provided'}</dd>
                </div>
            ))}
        </dl>
    );
}

export default function CandidateShowPage({ workspace, submission, notice }: HrmsPageProps & { submission: Submission; notice?: string | null }) {
    const [ssn, setSsn] = useState<string | null>(null);
    const [bank, setBank] = useState<{ bankName: string; accountNumber: string; routingNumber: string } | null>(null);
    const [identity, setIdentity] = useState<{ phone: string; dateOfBirth: string; address: string; i94Number: string; eadNumber: string } | null>(null);
    const [password, setPassword] = useState('');
    const [confirmError, setConfirmError] = useState<string | null>(null);
    const status = useForm({ status: submission.transitions[0] ?? '', note: '' });
    const operations = useForm({
        payment_received: submission.operations.paymentReceived,
        amount_paid: submission.operations.amountPaid,
        payment_method: submission.operations.paymentMethod,
        training_agreement_sent: submission.operations.trainingAgreementSent,
        work_day: submission.operations.workDay,
    });

    const csrf = () => decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

    const confirmPassword = async () => {
        const response = await fetch('/confirm-password', {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ password }),
        });
        setPassword('');
        if (!response.ok) {
            setConfirmError('The password was not accepted.');
            return false;
        }
        setConfirmError(null);
        return true;
    };

    const reveal = async () => {
        const response = await fetch(`/candidates/${submission.id}/ssn`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
            body: '{}',
        });
        if (response.status === 423) {
            setConfirmError('Confirm your password before revealing this value. The confirmation expires after 15 minutes.');
            return;
        }
        if (!response.ok) {
            return;
        }
        const body = await response.json() as { ssn?: string };
        setSsn(body.ssn ?? null);
    };

    const revealIdentity = async () => {
        const response = await fetch(`/candidates/${submission.id}/identity`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
            body: '{}',
        });
        if (!response.ok) {
            return;
        }
        const body = await response.json() as { phone?: string; dateOfBirth?: string; address?: string; i94Number?: string; eadNumber?: string };
        if (body.phone && body.dateOfBirth && body.address && body.i94Number && body.eadNumber) {
            setIdentity({ phone: body.phone, dateOfBirth: body.dateOfBirth, address: body.address, i94Number: body.i94Number, eadNumber: body.eadNumber });
        }
    };

    const revealBank = async () => {
        const response = await fetch(`/candidates/${submission.id}/banking`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
            body: '{}',
        });
        if (response.status === 423) {
            setConfirmError('Confirm your password before revealing bank details. The confirmation expires after 15 minutes.');
            return;
        }
        if (!response.ok) {
            return;
        }
        const body = await response.json() as { bankName?: string; accountNumber?: string; routingNumber?: string };
        if (body.bankName && body.accountNumber && body.routingNumber) {
            setBank({ bankName: body.bankName, accountNumber: body.accountNumber, routingNumber: body.routingNumber });
        }
    };

    return (
        <HrmsLayout workspace={workspace} title={`Submission ${submission.reference}`}>
            <PageIntro title={submission.reference} subtitle="Approval does not create an employee, payroll run, or payment." actions={<Link href="/candidates" className="text-sm text-blue-700">All submissions</Link>} />
            {notice && <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{notice}</p>}
            {confirmError && (
                <form className="mb-4 grid max-w-md gap-2 rounded-lg border border-slate-200 bg-white p-3" onSubmit={(event) => { event.preventDefault(); void confirmPassword(); }}>
                    <p className="text-sm text-slate-700">{confirmError}</p>
                    <Label htmlFor="candidate-confirm-password">Password</Label>
                    <Input id="candidate-confirm-password" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} />
                    <Button type="submit" variant="outline">Confirm password</Button>
                </form>
            )}
            <div className="grid gap-4">
                <SectionCard title="Review" extra={<StatusBadge value={submission.statusLabel} />}>
                    <p className="text-sm text-slate-600">Submitted {submission.submittedAt}. Notice {submission.noticeVersion} accepted {submission.consentedAt}.</p>
                    {submission.changeRequest && <p className="mt-3 text-sm text-slate-700">Requested correction: {submission.changeRequest}</p>}
                    <p className="mt-3 text-sm text-slate-500">Email delivery is not connected. A requested correction stays in this record and is not emailed.</p>
                    {submission.transitions.length > 0 && (
                        <form className="mt-4 grid gap-3 md:grid-cols-[16rem_1fr_auto]" onSubmit={(event: FormEvent) => { event.preventDefault(); status.post(`/candidates/${submission.id}/status`); }}>
                            <select aria-label="Review status" className="h-10 rounded-md border border-input px-3 text-sm" value={status.data.status} onChange={(event) => status.setData('status', event.target.value)}>
                                {submission.transitions.map((value) => <option key={value} value={value}>{submission.statuses[value]}</option>)}
                            </select>
                            <Input aria-label="Review note" value={status.data.note} onChange={(event) => status.setData('note', event.target.value)} placeholder="Note for HR. Do not enter a Social Security number." />
                            <Button type="submit" disabled={status.processing}>Save review</Button>
                            <InputError message={status.errors.status ?? status.errors.note} />
                        </form>
                    )}
                    {submission.status === 'approved' && <p className="mt-3 text-sm text-slate-700">This approval did not create an employee. Use Employees when you are ready to start that separate record.</p>}
                </SectionCard>
                <SectionCard title="Personal information">
                    <Facts values={{
                        ...submission.personal,
                        ...(identity ? { 'Primary contact number': identity.phone, 'Date of birth': identity.dateOfBirth, 'Current address': identity.address } : {}),
                    }} />
                    <div className="mt-3">
                        {identity ? <Button type="button" variant="outline" onClick={() => setIdentity(null)}>Hide protected details</Button> : <Button type="button" variant="outline" onClick={() => { void revealIdentity(); }}>Reveal protected details</Button>}
                    </div>
                </SectionCard>
                <SectionCard title="Employment information"><Facts values={submission.employment} /></SectionCard>
                <SectionCard title="Banking">
                    <p className="text-sm text-slate-800">Bank name: {bank?.bankName ?? submission.banking.bankStatus}</p>
                    <p className="mt-2 text-sm text-slate-800">Account number: {bank?.accountNumber ?? submission.banking.accountMask}</p>
                    <p className="mt-2 text-sm text-slate-800">Routing number: {bank?.routingNumber ?? submission.banking.routingMask}</p>
                    {submission.canRevealBanking ? (
                        <div className="mt-3">
                            {bank ? <Button type="button" variant="outline" onClick={() => setBank(null)}>Hide bank details</Button> : <Button type="button" variant="outline" onClick={() => { void revealBank(); }}>Reveal bank details</Button>}
                        </div>
                    ) : <p className="mt-3 text-sm text-slate-500">Only a Head of Operations can reveal these bank details.</p>}
                </SectionCard>
                <SectionCard title="Work authorization">
                    <Facts values={{
                        ...submission.authorization,
                        ...(identity ? { 'I-94 number': identity.i94Number, 'EAD card document number': identity.eadNumber } : {}),
                    }} />
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <p className="text-sm text-slate-800">Social Security number <span className="font-medium">{ssn ?? submission.ssnMask}</span></p>
                        {ssn ? <Button type="button" variant="outline" onClick={() => setSsn(null)}>Hide</Button> : <Button type="button" variant="outline" onClick={() => { void reveal(); }}>Reveal full Social Security number</Button>}
                    </div>
                </SectionCard>
                <SectionCard title="Student training information"><Facts values={submission.training} /></SectionCard>
                <SectionCard title="Documents">
                    {submission.missing.length > 0 && <p className="mb-3 text-sm text-rose-700">Missing required documents: {submission.missing.join(', ')}.</p>}
                    <p className="mb-3 text-sm text-slate-500">Opening a document requires a password confirmation from this account. The file is not shown on this page.</p>
                    <ul className="space-y-3">
                        {submission.documents.map((document) => (
                            <li key={document.id} className="flex flex-wrap items-center gap-3">
                                <span className="text-sm text-slate-800">{document.label}</span>
                                <a className="text-sm text-blue-700" href={`/candidates/${submission.id}/documents/${document.id}`}>Open</a>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
                <SectionCard title="Internal HR fields">
                    <p className="mb-3 text-sm text-slate-500">These fields are not on the public form. Saving them does not create a payment or payroll record.</p>
                    <form className="grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); operations.post(`/candidates/${submission.id}/operations`); }}>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={operations.data.payment_received} onChange={(event) => operations.setData('payment_received', event.target.checked)} /> Payment received</label>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={operations.data.training_agreement_sent} onChange={(event) => operations.setData('training_agreement_sent', event.target.checked)} /> Training agreement sent</label>
                        <label className="grid gap-1.5 text-sm"><Label htmlFor="amount-paid">Amount paid</Label><Input id="amount-paid" value={operations.data.amount_paid} onChange={(event) => operations.setData('amount_paid', event.target.value)} /><InputError message={operations.errors.amount_paid} /></label>
                        <label className="grid gap-1.5 text-sm">
                            <Label htmlFor="payment-method">Payment method</Label>
                            <select id="payment-method" className="h-10 rounded-md border border-input px-3 text-sm" value={operations.data.payment_method} onChange={(event) => operations.setData('payment_method', event.target.value)}>
                                <option value="">Not recorded</option>
                                {Object.entries(submission.paymentMethods).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                            <InputError message={operations.errors.payment_method} />
                        </label>
                        <label className="grid gap-1.5 text-sm"><Label htmlFor="work-day">Work day</Label><Input id="work-day" type="date" value={operations.data.work_day} onChange={(event) => operations.setData('work_day', event.target.value)} /><InputError message={operations.errors.work_day} /></label>
                        <div className="flex items-end"><Button type="submit" disabled={operations.processing}>Save internal fields</Button></div>
                    </form>
                </SectionCard>
                <SectionCard title="History">
                    <ul className="space-y-2 text-sm text-slate-700">
                        {submission.events.map((event) => <li key={`${event.action}-${event.at}`}>{event.at}: {event.action}{event.subject ? ` (${event.subject})` : ''} · {event.actor}</li>)}
                    </ul>
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
