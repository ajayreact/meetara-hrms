import { FormEvent, ReactNode, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';

interface Expiry {
    label: string;
    tone: string;
    date: string | null;
    detail: string;
}

interface ChecklistItem {
    id: number;
    key: string;
    label: string;
    status: string;
    statusLabel: string;
    options: Record<string, string>;
    remark: string | null;
    updatedAt: string | null;
    updatedBy: string | null;
}

interface RecordView {
    id: number;
    reference: string;
    personal: {
        firstName: string;
        lastName: string;
        gender: string;
        email: string;
        phone: string;
        dateOfBirth: string | null;
        currentAddress: string;
        universityName: string;
        ssnMask: string;
    };
    employment: {
        jobTitle: string;
        employmentType: string;
        workLocation: string;
        hourlyRate: string;
        hoursPerWeek: number;
        startDate: string | null;
        experience: string;
        responsibility1: string;
        responsibility2: string;
        responsibility3: string;
        responsibility4: string | null;
        responsibility5: string;
        responsibility6: string;
    };
    bank: { bankName: string; accountMask: string; routingMask: string };
    authorization: { workAuthorization: string; i94Mask: string; eadMask: string; workAuthorizationExpiresOn: string | null };
    training: { studentRole: string | null; goals: string | null; oversight: string | null; evaluationMethods: string | null };
    statedPayment: { received: string; amount: string; method: string };
    operations: { trainingAgreementSent: string | null; workDay: string };
    ead: Expiry;
    passport: Expiry;
    completion: number;
    documents: Array<{ id: number; category: string; label: string; name: string; password: boolean; uploadedAt: string | null }>;
    payments: Array<{ id: number; amount: string; currency: string; paidOn: string | null; method: string; reference: string | null; notes: string | null; receipts: Array<{ id: number; name: string }> }>;
    checklist: ChecklistItem[];
    events: Array<{ id: number; detail: string; at: string | null; actor: string | null }>;
    registeredAt: string | null;
    updatedAt: string | null;
}

interface Options {
    genders: string[];
    employmentTypes: string[];
    workLocations: string[];
    authorizations: string[];
    yesNo: string[];
    workDays: string[];
    documents: Record<string, string>;
}

const csrf = () => decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

export default function CandidateRecordPage({ workspace, record, options, notice }: HrmsPageProps & { record: RecordView; options: Options; notice?: string | null }) {
    const [revealed, setRevealed] = useState<Record<string, string>>({});
    const [revealNotice, setRevealNotice] = useState('');
    const upload = useForm<{ category: string; label: string; file: File | null }>({ category: 'passport', label: '', file: null });
    const payment = useForm({ amount: '', currency: 'USD', paid_on: '', method: '', reference: '', notes: '' });
    const profile = useForm({
        first_name: record.personal.firstName,
        last_name: record.personal.lastName,
        gender: record.personal.gender,
        email: record.personal.email,
        phone: record.personal.phone,
        date_of_birth: record.personal.dateOfBirth ?? '',
        current_address: record.personal.currentAddress,
        university_name: record.personal.universityName,
        ssn: '',
        job_title: record.employment.jobTitle,
        employment_type: record.employment.employmentType,
        work_location: record.employment.workLocation,
        hourly_rate: record.employment.hourlyRate,
        hours_per_week: String(record.employment.hoursPerWeek),
        start_date: record.employment.startDate ?? '',
        experience: record.employment.experience,
        responsibility_1: record.employment.responsibility1,
        responsibility_2: record.employment.responsibility2,
        responsibility_3: record.employment.responsibility3,
        responsibility_4: record.employment.responsibility4 ?? '',
        responsibility_5: record.employment.responsibility5,
        responsibility_6: record.employment.responsibility6,
        bank_name: record.bank.bankName,
        account_number: '',
        routing_number: '',
        work_authorization: record.authorization.workAuthorization,
        i94_number: '',
        ead_number: '',
        student_role: record.training.studentRole ?? '',
        goals: record.training.goals ?? '',
        oversight: record.training.oversight ?? '',
        evaluation_methods: record.training.evaluationMethods ?? '',
        payment_received: record.statedPayment.received,
        stated_amount: record.statedPayment.amount,
        stated_payment_method: record.statedPayment.method,
        training_agreement_sent: record.operations.trainingAgreementSent ?? '',
        work_day: record.operations.workDay,
    });

    const reveal = async (field: string) => {
        setRevealNotice('');
        const response = await fetch(`/candidate-records/${record.id}/reveal`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ field }),
        });
        if (response.status === 423) {
            setRevealNotice('Confirm your password before revealing this value.');
            return;
        }
        if (!response.ok) {
            setRevealNotice('The value could not be revealed.');
            return;
        }
        const body = await response.json() as { value: string };
        setRevealed((current) => ({ ...current, [field]: body.value }));
    };

    const saveDates = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.put(`/candidate-records/${record.id}/expiries`, {
            work_authorization_expires_on: String(data.get('work_authorization_expires_on') ?? ''),
            ead_expiry_date: String(data.get('ead_expiry_date') ?? ''),
            passport_expiry_date: String(data.get('passport_expiry_date') ?? ''),
        }, { preserveScroll: true });
    };

    const saveChecklist = (event: FormEvent<HTMLFormElement>, itemId: number) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.put(`/candidate-records/${record.id}/checklist/${itemId}`, {
            status: String(data.get('status') ?? ''),
            remark: String(data.get('remark') ?? ''),
        }, { preserveScroll: true });
    };

    return (
        <HrmsLayout workspace={workspace} title={record.reference}>
            <PageIntro title={`${record.personal.firstName} ${record.personal.lastName}`} subtitle={`${record.reference} · Registered ${record.registeredAt ?? ''} · Updated ${record.updatedAt ?? ''} · Checklist ${record.completion}%`} />
            {(notice || revealNotice) && <p className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{revealNotice || notice}</p>}
            {revealNotice && <a className="mb-4 inline-block text-sm text-blue-700" href="/confirm-password">Confirm password</a>}

            <div className="grid gap-4">
                <SectionCard title="Basic details">
                    <div className="grid gap-4 md:grid-cols-2">
                        <Facts title="Personal" rows={[
                            ['First Name', record.personal.firstName],
                            ['Last Name', record.personal.lastName],
                            ['Gender', record.personal.gender],
                            ['Email', record.personal.email],
                            ['Primary Contact Number', record.personal.phone],
                            ['Date of Birth', record.personal.dateOfBirth ?? '—'],
                            ['University Name', record.personal.universityName],
                            ['Current Address', record.personal.currentAddress],
                        ]} />
                        <Facts title="Employment" rows={[
                            ['Job Title', record.employment.jobTitle],
                            ['Employment Type', record.employment.employmentType],
                            ['Work Location', record.employment.workLocation],
                            ['Hourly Rate', `${record.employment.hourlyRate} USD`],
                            ['Hours per week', String(record.employment.hoursPerWeek)],
                            ['Start Date', record.employment.startDate ?? '—'],
                            ['Total Professional Experience', record.employment.experience],
                        ]} />
                        <div>
                            <h3 className="mb-2 font-medium">Protected values</h3>
                            <Secret label="Full SSN" mask={record.personal.ssnMask} field="ssn" revealed={revealed.ssn} onReveal={reveal} />
                            <Secret label="Account Number" mask={record.bank.accountMask} field="account" revealed={revealed.account} onReveal={reveal} />
                            <Secret label="Routing Number" mask={record.bank.routingMask} field="routing" revealed={revealed.routing} onReveal={reveal} />
                            <Secret label="I-94 Number" mask={record.authorization.i94Mask} field="i94" revealed={revealed.i94} onReveal={reveal} />
                            <Secret label="EAD Card Document Number" mask={record.authorization.eadMask} field="ead_number" revealed={revealed.ead_number} onReveal={reveal} />
                            <p className="mt-2 text-sm">Bank Name: {record.bank.bankName}</p>
                            <p className="text-sm">Work Authorization: {record.authorization.workAuthorization}</p>
                        </div>
                        <div>
                            <h3 className="mb-2 font-medium">Training, payment answers, and operations</h3>
                            <p className="text-sm">Student Role: {record.training.studentRole || '—'}</p>
                            <p className="text-sm">Goals and Objectives: {record.training.goals || '—'}</p>
                            <p className="text-sm">Employer Oversight: {record.training.oversight || '—'}</p>
                            <p className="text-sm">Training and Evaluation Methods: {record.training.evaluationMethods || '—'}</p>
                            <p className="mt-2 text-sm">Payment Received: {record.statedPayment.received}</p>
                            <p className="text-sm">Enter Amount Paid: {record.statedPayment.amount} USD</p>
                            <p className="text-sm">Payment Method: {record.statedPayment.method}</p>
                            <p className="mt-2 text-sm">Training Agreement Sent: {record.operations.trainingAgreementSent || '—'}</p>
                            <p className="text-sm">Work Day: {record.operations.workDay}</p>
                            {[1, 2, 3, 4, 5, 6].map((number) => (
                                <p key={number} className="mt-2 text-sm">Job Responsibility {number}: {record.employment[`responsibility${number}` as 'responsibility1'] || '—'}</p>
                            ))}
                        </div>
                    </div>
                    <form className="mt-6 grid gap-3 border-t border-slate-100 pt-4 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); profile.put(`/candidate-records/${record.id}`, { preserveScroll: true }); }}>
                        <h3 className="md:col-span-2 font-medium">Update profile</h3>
                        <p className="md:col-span-2 text-sm text-slate-500">Leave a protected field blank to keep the current value. Expiry dates are saved separately and are not changed by this form.</p>
                        <Labeled label="First Name"><Input value={profile.data.first_name} onChange={(event) => profile.setData('first_name', event.target.value)} /></Labeled>
                        <Labeled label="Last Name"><Input value={profile.data.last_name} onChange={(event) => profile.setData('last_name', event.target.value)} /></Labeled>
                        <Labeled label="Gender">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={profile.data.gender} onChange={(event) => profile.setData('gender', event.target.value)}>
                                {options.genders.map((option) => <option key={option}>{option}</option>)}
                            </select>
                        </Labeled>
                        <Labeled label="Email"><Input value={profile.data.email} onChange={(event) => profile.setData('email', event.target.value)} /></Labeled>
                        <Labeled label="Primary Contact Number"><Input value={profile.data.phone} onChange={(event) => profile.setData('phone', event.target.value)} /></Labeled>
                        <Labeled label="Date of Birth"><Input type="date" value={profile.data.date_of_birth} onChange={(event) => profile.setData('date_of_birth', event.target.value)} /></Labeled>
                        <Labeled label="University Name"><Input value={profile.data.university_name} onChange={(event) => profile.setData('university_name', event.target.value)} /></Labeled>
                        <Labeled label="Current Address" ><textarea className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.current_address} onChange={(event) => profile.setData('current_address', event.target.value)} /></Labeled>
                        <Labeled label="Job Title"><Input value={profile.data.job_title} onChange={(event) => profile.setData('job_title', event.target.value)} /></Labeled>
                        <Labeled label="Employment Type">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={profile.data.employment_type} onChange={(event) => profile.setData('employment_type', event.target.value)}>
                                {options.employmentTypes.map((option) => <option key={option}>{option}</option>)}
                            </select>
                        </Labeled>
                        <Labeled label="Work Location">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={profile.data.work_location} onChange={(event) => profile.setData('work_location', event.target.value)}>
                                {options.workLocations.map((option) => <option key={option}>{option}</option>)}
                            </select>
                        </Labeled>
                        <Labeled label="Hourly Rate (USD)"><Input value={profile.data.hourly_rate} onChange={(event) => profile.setData('hourly_rate', event.target.value)} /></Labeled>
                        <Labeled label="Hours per week"><Input value={profile.data.hours_per_week} onChange={(event) => profile.setData('hours_per_week', event.target.value)} /></Labeled>
                        <Labeled label="Start Date"><Input type="date" value={profile.data.start_date} onChange={(event) => profile.setData('start_date', event.target.value)} /></Labeled>
                        <Labeled label="Total Professional Experience"><Input value={profile.data.experience} onChange={(event) => profile.setData('experience', event.target.value)} /></Labeled>
                        <Labeled label="Job Responsibility 1"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.responsibility_1} onChange={(event) => profile.setData('responsibility_1', event.target.value)} /></Labeled>
                        <Labeled label="Job Responsibility 2"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.responsibility_2} onChange={(event) => profile.setData('responsibility_2', event.target.value)} /></Labeled>
                        <Labeled label="Job Responsibility 3"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.responsibility_3} onChange={(event) => profile.setData('responsibility_3', event.target.value)} /></Labeled>
                        <Labeled label="Job Responsibility 4 (optional)"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.responsibility_4} onChange={(event) => profile.setData('responsibility_4', event.target.value)} /></Labeled>
                        <Labeled label="Job Responsibility 5"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.responsibility_5} onChange={(event) => profile.setData('responsibility_5', event.target.value)} /></Labeled>
                        <Labeled label="Job Responsibility 6"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.responsibility_6} onChange={(event) => profile.setData('responsibility_6', event.target.value)} /></Labeled>
                        <Labeled label="Bank Name"><Input value={profile.data.bank_name} onChange={(event) => profile.setData('bank_name', event.target.value)} /></Labeled>
                        <Labeled label="Full SSN"><Input value={profile.data.ssn} onChange={(event) => profile.setData('ssn', event.target.value)} autoComplete="off" /></Labeled>
                        <Labeled label="Account Number"><Input value={profile.data.account_number} onChange={(event) => profile.setData('account_number', event.target.value)} autoComplete="off" /></Labeled>
                        <Labeled label="Routing Number"><Input value={profile.data.routing_number} onChange={(event) => profile.setData('routing_number', event.target.value)} autoComplete="off" /></Labeled>
                        <Labeled label="Work Authorization">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={profile.data.work_authorization} onChange={(event) => profile.setData('work_authorization', event.target.value)}>
                                {options.authorizations.map((option) => <option key={option}>{option}</option>)}
                            </select>
                        </Labeled>
                        <Labeled label="I-94 Number"><Input value={profile.data.i94_number} onChange={(event) => profile.setData('i94_number', event.target.value)} autoComplete="off" /></Labeled>
                        <Labeled label="EAD Card Document Number"><Input value={profile.data.ead_number} onChange={(event) => profile.setData('ead_number', event.target.value)} autoComplete="off" /></Labeled>
                        <Labeled label="Student Role Description"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.student_role} onChange={(event) => profile.setData('student_role', event.target.value)} /></Labeled>
                        <Labeled label="Goals and Objectives"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.goals} onChange={(event) => profile.setData('goals', event.target.value)} /></Labeled>
                        <Labeled label="Employer Oversight"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.oversight} onChange={(event) => profile.setData('oversight', event.target.value)} /></Labeled>
                        <Labeled label="Training and Evaluation Methods"><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={profile.data.evaluation_methods} onChange={(event) => profile.setData('evaluation_methods', event.target.value)} /></Labeled>
                        <Labeled label="Payment Received">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={profile.data.payment_received} onChange={(event) => profile.setData('payment_received', event.target.value)}>
                                {options.yesNo.map((option) => <option key={option}>{option}</option>)}
                            </select>
                        </Labeled>
                        <Labeled label="Enter Amount Paid (USD)"><Input value={profile.data.stated_amount} onChange={(event) => profile.setData('stated_amount', event.target.value)} /></Labeled>
                        <Labeled label="Payment Method"><Input value={profile.data.stated_payment_method} onChange={(event) => profile.setData('stated_payment_method', event.target.value)} /></Labeled>
                        <Labeled label="Training Agreement Sent">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={profile.data.training_agreement_sent} onChange={(event) => profile.setData('training_agreement_sent', event.target.value)}>
                                <option value="">Not set</option>
                                {options.yesNo.map((option) => <option key={option}>{option}</option>)}
                            </select>
                        </Labeled>
                        <Labeled label="Work Day">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={profile.data.work_day} onChange={(event) => profile.setData('work_day', event.target.value)}>
                                {options.workDays.map((option) => <option key={option}>{option}</option>)}
                            </select>
                        </Labeled>
                        <div className="md:col-span-2"><Button type="submit" disabled={profile.processing}>Save profile</Button></div>
                        <InputError message={Object.values(profile.errors)[0]} />
                    </form>
                </SectionCard>

                <SectionCard title="Expiry dates">
                    <div className="mb-4 grid gap-3 md:grid-cols-3">
                        <ExpiryBlock label="Work authorization expiry" date={record.authorization.workAuthorizationExpiresOn} />
                        <ExpiryBlock label="EAD expiry" date={record.ead.date} expiry={record.ead} />
                        <ExpiryBlock label="Passport expiry" date={record.passport.date} expiry={record.passport} />
                    </div>
                    <form className="grid gap-3 md:grid-cols-3" onSubmit={saveDates}>
                        <Labeled label="Work Authorization Expiry Date"><Input type="date" name="work_authorization_expires_on" defaultValue={record.authorization.workAuthorizationExpiresOn ?? ''} /></Labeled>
                        <Labeled label="EAD Expiry Date"><Input type="date" name="ead_expiry_date" defaultValue={record.ead.date ?? ''} /></Labeled>
                        <Labeled label="Passport Expiry Date"><Input type="date" name="passport_expiry_date" defaultValue={record.passport.date ?? ''} /></Labeled>
                        <div><Button type="submit">Save dates</Button></div>
                    </form>
                </SectionCard>

                <SectionCard title="Documents and payments">
                    <form className="grid gap-3 md:grid-cols-4" onSubmit={(event) => { event.preventDefault(); upload.post(`/candidate-records/${record.id}/documents`, { forceFormData: true, preserveScroll: true }); }}>
                        <Labeled label="Document">
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={upload.data.category} onChange={(event) => upload.setData('category', event.target.value)}>
                                {Object.entries(options.documents).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </Labeled>
                        <Labeled label="Label"><Input value={upload.data.label} onChange={(event) => upload.setData('label', event.target.value)} placeholder="Required for Other" /></Labeled>
                        <Labeled label="File"><Input type="file" onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)} /></Labeled>
                        <div className="self-end"><Button type="submit" disabled={upload.processing}>Upload</Button></div>
                        <InputError message={upload.errors.file || upload.errors.label} />
                    </form>
                    <ul className="mt-4 divide-y divide-slate-100 text-sm">
                        {record.documents.map((document) => (
                            <li key={document.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>{document.label} · {document.name} · {document.uploadedAt}</span>
                                <a className="text-blue-700" href={`/candidate-records/${record.id}/documents/${document.id}`}>{document.password ? 'Download (password required)' : 'Download'}</a>
                            </li>
                        ))}
                        {record.documents.length === 0 && <li className="py-2 text-slate-500">No documents uploaded. The candidate can still be saved.</li>}
                    </ul>
                    <form className="mt-6 grid gap-3 border-t border-slate-100 pt-4 md:grid-cols-3" onSubmit={(event) => { event.preventDefault(); payment.post(`/candidate-records/${record.id}/payments`, { preserveScroll: true }); }}>
                        <h3 className="md:col-span-3 font-medium">USD payment ledger</h3>
                        <Labeled label="Amount (USD)"><Input value={payment.data.amount} onChange={(event) => payment.setData('amount', event.target.value)} /></Labeled>
                        <Labeled label="Paid on"><Input type="date" value={payment.data.paid_on} onChange={(event) => payment.setData('paid_on', event.target.value)} /></Labeled>
                        <Labeled label="Method"><Input value={payment.data.method} onChange={(event) => payment.setData('method', event.target.value)} /></Labeled>
                        <Labeled label="Reference"><Input value={payment.data.reference} onChange={(event) => payment.setData('reference', event.target.value)} /></Labeled>
                        <Labeled label="Notes"><Input value={payment.data.notes} onChange={(event) => payment.setData('notes', event.target.value)} /></Labeled>
                        <div className="self-end"><Button type="submit" disabled={payment.processing}>Record payment</Button></div>
                        <InputError message={payment.errors.amount || payment.errors.currency || payment.errors.paid_on} />
                    </form>
                    <div className="mt-4 grid gap-3">
                        {record.payments.map((item) => (
                            <div key={item.id} className="rounded-lg border border-slate-200 p-3 text-sm">
                                <p>{item.currency} {item.amount} on {item.paidOn} via {item.method}</p>
                                <p>Reference: {item.reference || '—'} · {item.notes || ''}</p>
                                <ul className="mt-2">
                                    {item.receipts.map((receipt) => (
                                        <li key={receipt.id}><a className="text-blue-700" href={`/candidate-records/${record.id}/receipts/${receipt.id}`}>{receipt.name}</a></li>
                                    ))}
                                </ul>
                                <form className="mt-2 flex flex-wrap items-center gap-2" onSubmit={(event) => {
                                    event.preventDefault();
                                    const file = new FormData(event.currentTarget).get('file');
                                    router.post(`/candidate-records/${record.id}/payments/${item.id}/receipts`, { file }, { forceFormData: true, preserveScroll: true });
                                }}>
                                    <Input type="file" name="file" aria-label={`Receipt for payment ${item.id}`} />
                                    <Button type="submit" variant="outline">Upload receipt</Button>
                                </form>
                            </div>
                        ))}
                        {record.payments.length === 0 && <p className="text-sm text-slate-500">No ledger payments. Form answers above are not payments.</p>}
                    </div>
                </SectionCard>

                <SectionCard title="Compliance checklist">
                    <p className="mb-3 text-sm text-slate-500">Statuses change only when someone saves them here. Uploaded documents do not verify a row. Completion is {record.completion}% of these 11 items.</p>
                    <div className="grid gap-3">
                        {record.checklist.map((item) => (
                            <form key={item.id} className="grid gap-2 rounded-lg border border-slate-200 p-3 md:grid-cols-4" onSubmit={(event) => saveChecklist(event, item.id)}>
                                <div>
                                    <p className="font-medium">{item.label}</p>
                                    <StatusBadge value={item.statusLabel} />
                                    <p className="mt-1 text-xs text-slate-500">{item.updatedBy || '—'} · {item.updatedAt || '—'}</p>
                                </div>
                                <select name="status" defaultValue={item.status} aria-label={`${item.label} status`} className="h-10 rounded-md border border-input bg-background px-3 text-sm">
                                    {Object.entries(item.options).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </select>
                                <Input name="remark" defaultValue={item.remark ?? ''} aria-label={`${item.label} remark`} placeholder="Remark" />
                                <Button type="submit">Save</Button>
                            </form>
                        ))}
                    </div>
                </SectionCard>

                <SectionCard title="Activity">
                    <ul className="divide-y divide-slate-100 text-sm">
                        {record.events.map((event) => (
                            <li key={event.id} className="py-2">{event.detail}<span className="text-slate-500"> · {event.actor || '—'} · {event.at}</span></li>
                        ))}
                    </ul>
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function Facts({ title, rows }: { title: string; rows: Array<[string, string]> }) {
    return (
        <div>
            <h3 className="mb-2 font-medium">{title}</h3>
            {rows.map(([label, value]) => <p key={label} className="text-sm"><span className="text-slate-500">{label}: </span>{value}</p>)}
        </div>
    );
}

function Secret({ label, mask, field, revealed, onReveal }: { label: string; mask: string; field: string; revealed?: string; onReveal: (field: string) => void }) {
    return (
        <p className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-slate-500">{label}:</span> {revealed || mask}
            <button type="button" className="text-blue-700" onClick={() => onReveal(field)}>Reveal</button>
        </p>
    );
}

function ExpiryBlock({ label, date, expiry }: { label: string; date: string | null; expiry?: Expiry }) {
    const alert = expiry?.tone === 'alert-expired' || expiry?.tone === 'alert-expiring';

    return (
        <div className={alert ? 'text-rose-700' : ''}>
            <p className="text-sm text-slate-500">{label}</p>
            <p className="font-medium">{date ?? 'Not set'}</p>
            {expiry && <div className="mt-1 flex flex-wrap items-center gap-2"><StatusBadge value={expiry.label} tone={expiry.tone} />{expiry.detail && <span className="text-sm">{expiry.detail}</span>}</div>}
            {!expiry && <p className="text-sm text-slate-500">Stored only. No alert window.</p>}
        </div>
    );
}

function Labeled({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <Label>{label}</Label>
            <div className="mt-1">{children}</div>
        </div>
    );
}
