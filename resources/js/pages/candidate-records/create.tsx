import { useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';

interface Options {
    genders: string[];
    employmentTypes: string[];
    workLocations: string[];
    authorizations: string[];
    yesNo: string[];
    workDays: string[];
}

const initial = {
    first_name: '',
    last_name: '',
    gender: '',
    email: '',
    phone: '',
    date_of_birth: '',
    current_address: '',
    university_name: '',
    ssn: '',
    job_title: '',
    employment_type: '',
    work_location: '',
    hourly_rate: '',
    hours_per_week: '',
    start_date: '',
    experience: '',
    responsibility_1: '',
    responsibility_2: '',
    responsibility_3: '',
    responsibility_4: '',
    responsibility_5: '',
    responsibility_6: '',
    bank_name: '',
    account_number: '',
    routing_number: '',
    work_authorization: '',
    i94_number: '',
    ead_number: '',
    work_authorization_expires_on: '',
    student_role: '',
    goals: '',
    oversight: '',
    evaluation_methods: '',
    payment_received: '',
    stated_amount: '',
    stated_payment_method: '',
    training_agreement_sent: '',
    work_day: '',
    ead_expiry_date: '',
    passport_expiry_date: '',
};

export default function RegisterCandidatePage({ workspace, options }: HrmsPageProps & { options: Options }) {
    const form = useForm(initial);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/candidate-records');
    };

    return (
        <HrmsLayout workspace={workspace} title="Register candidate">
            <PageIntro title="Register a candidate" subtitle="Documents can be added after the record is saved. Payment answers here do not create a ledger payment." />
            <form onSubmit={submit} className="grid gap-4">
                <SectionCard title="Personal">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="First Name" error={form.errors.first_name}><Input value={form.data.first_name} onChange={(event) => form.setData('first_name', event.target.value)} /></Field>
                        <Field label="Last Name" error={form.errors.last_name}><Input value={form.data.last_name} onChange={(event) => form.setData('last_name', event.target.value)} /></Field>
                        <Field label="Gender" error={form.errors.gender}><Select value={form.data.gender} options={options.genders} onChange={(value) => form.setData('gender', value)} /></Field>
                        <Field label="Email" error={form.errors.email}><Input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                        <Field label="Primary Contact Number" error={form.errors.phone}><Input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                        <Field label="Date of Birth (optional)" error={form.errors.date_of_birth}><Input type="date" value={form.data.date_of_birth} onChange={(event) => form.setData('date_of_birth', event.target.value)} /></Field>
                        <Field label="University Name" error={form.errors.university_name}><Input value={form.data.university_name} onChange={(event) => form.setData('university_name', event.target.value)} /></Field>
                        <Field label="Full SSN" error={form.errors.ssn}><Input value={form.data.ssn} onChange={(event) => form.setData('ssn', event.target.value)} autoComplete="off" /></Field>
                        <Field label="Current Address" error={form.errors.current_address} className="md:col-span-2"><textarea className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.current_address} onChange={(event) => form.setData('current_address', event.target.value)} /></Field>
                    </div>
                </SectionCard>
                <SectionCard title="Employment">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Job Title" error={form.errors.job_title}><Input value={form.data.job_title} onChange={(event) => form.setData('job_title', event.target.value)} /></Field>
                        <Field label="Employment Type" error={form.errors.employment_type}><Select value={form.data.employment_type} options={options.employmentTypes} onChange={(value) => form.setData('employment_type', value)} /></Field>
                        <Field label="Work Location" error={form.errors.work_location}><Select value={form.data.work_location} options={options.workLocations} onChange={(value) => form.setData('work_location', value)} /></Field>
                        <Field label="Hourly Rate (USD)" error={form.errors.hourly_rate}><Input value={form.data.hourly_rate} onChange={(event) => form.setData('hourly_rate', event.target.value)} /></Field>
                        <Field label="Hours per week" error={form.errors.hours_per_week}><Input value={form.data.hours_per_week} onChange={(event) => form.setData('hours_per_week', event.target.value)} /></Field>
                        <Field label="Start Date" error={form.errors.start_date}><Input type="date" value={form.data.start_date} onChange={(event) => form.setData('start_date', event.target.value)} /></Field>
                        <Field label="Total Professional Experience" error={form.errors.experience}><Input value={form.data.experience} onChange={(event) => form.setData('experience', event.target.value)} /></Field>
                        {[1, 2, 3, 5, 6].map((number) => (
                            <Field key={number} label={`Job Responsibility ${number}`} error={form.errors[`responsibility_${number}` as keyof typeof form.errors]} className="md:col-span-2">
                                <textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data[`responsibility_${number}` as 'responsibility_1']} onChange={(event) => form.setData(`responsibility_${number}` as 'responsibility_1', event.target.value)} />
                            </Field>
                        ))}
                        <Field label="Job Responsibility 4 (optional)" error={form.errors.responsibility_4} className="md:col-span-2">
                            <textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.responsibility_4} onChange={(event) => form.setData('responsibility_4', event.target.value)} />
                        </Field>
                    </div>
                </SectionCard>
                <SectionCard title="Bank">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Bank Name" error={form.errors.bank_name}><Input value={form.data.bank_name} onChange={(event) => form.setData('bank_name', event.target.value)} /></Field>
                        <Field label="Account Number" error={form.errors.account_number}><Input value={form.data.account_number} onChange={(event) => form.setData('account_number', event.target.value)} autoComplete="off" /></Field>
                        <Field label="Routing Number" error={form.errors.routing_number}><Input value={form.data.routing_number} onChange={(event) => form.setData('routing_number', event.target.value)} autoComplete="off" /></Field>
                    </div>
                </SectionCard>
                <SectionCard title="Work authorization">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Work Authorization" error={form.errors.work_authorization}><Select value={form.data.work_authorization} options={options.authorizations} onChange={(value) => form.setData('work_authorization', value)} /></Field>
                        <Field label="I-94 Number" error={form.errors.i94_number}><Input value={form.data.i94_number} onChange={(event) => form.setData('i94_number', event.target.value)} autoComplete="off" /></Field>
                        <Field label="EAD Card Document Number" error={form.errors.ead_number}><Input value={form.data.ead_number} onChange={(event) => form.setData('ead_number', event.target.value)} autoComplete="off" /></Field>
                        <Field label="Work Authorization Expiry Date (optional)" error={form.errors.work_authorization_expires_on}><Input type="date" value={form.data.work_authorization_expires_on} onChange={(event) => form.setData('work_authorization_expires_on', event.target.value)} /></Field>
                        <Field label="EAD Expiry Date (optional)" error={form.errors.ead_expiry_date}><Input type="date" value={form.data.ead_expiry_date} onChange={(event) => form.setData('ead_expiry_date', event.target.value)} /></Field>
                        <Field label="Passport Expiry Date (optional)" error={form.errors.passport_expiry_date}><Input type="date" value={form.data.passport_expiry_date} onChange={(event) => form.setData('passport_expiry_date', event.target.value)} /></Field>
                    </div>
                </SectionCard>
                <SectionCard title="Training (optional)">
                    <div className="grid gap-3">
                        <Field label="Student Role Description" error={form.errors.student_role}><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.student_role} onChange={(event) => form.setData('student_role', event.target.value)} /></Field>
                        <Field label="Goals and Objectives" error={form.errors.goals}><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.goals} onChange={(event) => form.setData('goals', event.target.value)} /></Field>
                        <Field label="Employer Oversight" error={form.errors.oversight}><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.oversight} onChange={(event) => form.setData('oversight', event.target.value)} /></Field>
                        <Field label="Training and Evaluation Methods" error={form.errors.evaluation_methods}><textarea className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.evaluation_methods} onChange={(event) => form.setData('evaluation_methods', event.target.value)} /></Field>
                    </div>
                </SectionCard>
                <SectionCard title="Form payment answers">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Payment Received" error={form.errors.payment_received}><Select value={form.data.payment_received} options={options.yesNo} onChange={(value) => form.setData('payment_received', value)} /></Field>
                        <Field label="Enter Amount Paid (USD)" error={form.errors.stated_amount}><Input value={form.data.stated_amount} onChange={(event) => form.setData('stated_amount', event.target.value)} /></Field>
                        <Field label="Payment Method" error={form.errors.stated_payment_method}><Input value={form.data.stated_payment_method} onChange={(event) => form.setData('stated_payment_method', event.target.value)} /></Field>
                    </div>
                </SectionCard>
                <SectionCard title="Operations">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Training Agreement Sent (optional)" error={form.errors.training_agreement_sent}><Select value={form.data.training_agreement_sent} options={['', ...options.yesNo]} onChange={(value) => form.setData('training_agreement_sent', value)} /></Field>
                        <Field label="Work Day" error={form.errors.work_day}><Select value={form.data.work_day} options={options.workDays} onChange={(value) => form.setData('work_day', value)} /></Field>
                    </div>
                </SectionCard>
                <Button type="submit" disabled={form.processing}>Save candidate</Button>
            </form>
        </HrmsLayout>
    );
}

function Field({ label, error, children, className = '' }: { label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={className}>
            <Label>{label}</Label>
            <div className="mt-1">{children}</div>
            <InputError message={error} />
        </div>
    );
}

function Select({ value, options, onChange }: { value: string; options: string[]; onChange: (value: string) => void }) {
    return (
        <select value={value} onChange={(event) => onChange(event.target.value)} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
            <option value="">Select</option>
            {options.filter((option) => option !== '').map((option) => <option key={option} value={option}>{option}</option>)}
        </select>
    );
}
