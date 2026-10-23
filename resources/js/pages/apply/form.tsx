import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';

interface PublicDocument {
    name: string;
    label: string;
    hint: string;
    required: boolean;
}

interface PublicForm {
    open: boolean;
    companyName: string | null;
    notice: string[];
    documents: PublicDocument[];
    accept: string;
    captcha?: { required: boolean; siteKey: string | null };
}

const fields: Array<{ name: string; label: string; section: string; type?: string; multiline?: boolean }> = [
    { name: 'first_name', label: 'First name', section: 'personal' },
    { name: 'last_name', label: 'Last name', section: 'personal' },
    { name: 'gender', label: 'Gender', section: 'personal' },
    { name: 'email', label: 'Email', section: 'personal', type: 'email' },
    { name: 'phone', label: 'Primary contact number', section: 'personal', type: 'tel' },
    { name: 'date_of_birth', label: 'Date of birth', section: 'personal', type: 'date' },
    { name: 'current_address', label: 'Current address', section: 'personal', multiline: true },
    { name: 'university_name', label: 'University name', section: 'personal' },
    { name: 'job_title', label: 'Job title', section: 'employment' },
    { name: 'employment_type', label: 'Employment type', section: 'employment' },
    { name: 'work_location', label: 'Work location', section: 'employment' },
    { name: 'hourly_rate', label: 'Hourly rate', section: 'employment', type: 'number' },
    { name: 'hours_per_week', label: 'Hours per week', section: 'employment', type: 'number' },
    { name: 'start_date', label: 'Start date', section: 'employment', type: 'date' },
    { name: 'experience', label: 'Total professional experience', section: 'employment' },
    { name: 'responsibility_1', label: 'Job responsibility 1', section: 'employment', multiline: true },
    { name: 'responsibility_2', label: 'Job responsibility 2', section: 'employment', multiline: true },
    { name: 'responsibility_3', label: 'Job responsibility 3', section: 'employment', multiline: true },
    { name: 'responsibility_4', label: 'Job responsibility 4', section: 'employment', multiline: true },
    { name: 'responsibility_5', label: 'Job responsibility 5', section: 'employment', multiline: true },
    { name: 'responsibility_6', label: 'Job responsibility 6', section: 'employment', multiline: true },
    { name: 'bank_name', label: 'Bank name', section: 'banking' },
    { name: 'work_authorization', label: 'Work authorization', section: 'authorization' },
    { name: 'i94_number', label: 'I-94 number', section: 'authorization' },
    { name: 'ead_number', label: 'EAD card document number', section: 'authorization' },
    { name: 'work_authorization_expires_on', label: 'Work authorization expiry date', section: 'authorization', type: 'date' },
    { name: 'student_role', label: 'Student role description', section: 'training', multiline: true },
    { name: 'goals', label: 'Goals and objectives', section: 'training', multiline: true },
    { name: 'oversight', label: 'Employer oversight', section: 'training', multiline: true },
    { name: 'evaluation_methods', label: 'Training and evaluation methods', section: 'training', multiline: true },
];

const sections = [
    ['personal', 'Personal information'],
    ['employment', 'Employment information'],
    ['banking', 'Banking'],
    ['authorization', 'Work authorization'],
    ['training', 'Student training information'],
    ['documents', 'Supporting documents'],
    ['consent', 'Consent'],
] as const;

export default function CandidateFormPage({ token, form, uploadNotice }: { token: string; form: PublicForm; uploadNotice?: string | null }) {
    const [showSsn, setShowSsn] = useState(false);
    const [showAccount, setShowAccount] = useState(false);
    const [showRouting, setShowRouting] = useState(false);
    const [dirty, setDirty] = useState(false);
    const submission = useForm<Record<string, string | boolean | File | null>>({
        first_name: '', last_name: '', gender: '', email: '', phone: '', date_of_birth: '', current_address: '', university_name: '',
        job_title: '', employment_type: '', work_location: '', hourly_rate: '', hours_per_week: '', start_date: '', experience: '',
        responsibility_1: '', responsibility_2: '', responsibility_3: '', responsibility_4: '', responsibility_5: '', responsibility_6: '',
        bank_name: '', account_number: '', routing_number: '',
        work_authorization: '', i94_number: '', ead_number: '', work_authorization_expires_on: '', ssn: '',
        student_role: '', goals: '', oversight: '', evaluation_methods: '', consent: false, website: '', captcha_token: '',
        document_passport: null, document_visa: null, document_i94: null, document_drivers_license: null, document_ead_front: null, document_ead_back: null, document_masters_degree: null, document_opt_i20: null, document_resume: null,
    });

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (!dirty) {
                return;
            }
            event.preventDefault();
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const captchaSiteKey = form.captcha?.required ? form.captcha.siteKey : null;
    const captchaHost = useRef<HTMLDivElement>(null);
    const captchaWidget = useRef<string | null>(null);

    useEffect(() => {
        if (!captchaSiteKey || !captchaHost.current) {
            return;
        }

        let cancelled = false;
        const host = captchaHost.current;

        const renderWidget = () => {
            if (cancelled || !host.isConnected || !window.turnstile || captchaWidget.current) {
                return;
            }

            captchaWidget.current = window.turnstile.render(host, {
                sitekey: captchaSiteKey,
                callback: (value: string) => submission.setData('captcha_token', value),
                'expired-callback': () => submission.setData('captcha_token', ''),
                'error-callback': () => submission.setData('captcha_token', ''),
            });
        };

        const scriptId = 'cf-turnstile-api';
        const existing = document.getElementById(scriptId) as HTMLScriptElement | null;

        if (window.turnstile) {
            renderWidget();
        } else if (existing) {
            existing.addEventListener('load', renderWidget);
        } else {
            const script = document.createElement('script');
            script.id = scriptId;
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.addEventListener('load', renderWidget);
            document.head.appendChild(script);
        }

        return () => {
            cancelled = true;
            if (captchaWidget.current && window.turnstile) {
                window.turnstile.remove(captchaWidget.current);
            }
            captchaWidget.current = null;
            submission.setData('captcha_token', '');
        };
    }, [captchaSiteKey]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (captchaSiteKey && String(submission.data.captcha_token ?? '') === '') {
            submission.setError('captcha_token', 'Complete the verification check before submitting.');
            setDirty(true);
            return;
        }

        setDirty(false);
        submission.post(`/apply/${token}`, {
            forceFormData: true,
            preserveState: true,
            onError: () => setDirty(true),
            onSuccess: () => {
                submission.reset();
                submission.setData('ssn', '');
                submission.setData('bank_name', '');
                submission.setData('account_number', '');
                submission.setData('routing_number', '');
            },
        });
    };

    return (
        <main className="mx-auto max-w-3xl px-4 py-8">
            <Head title="Candidate form" />
            <p className="text-sm text-slate-500">{form.companyName}</p>
            <h1 className="mt-1 text-2xl font-semibold text-slate-950">Candidate onboarding form</h1>
            <p className="mt-2 text-sm leading-6 text-slate-600">No account is required. The submission is reviewed by authorized staff at this company.</p>
            <nav className="mt-4 flex gap-2 overflow-x-auto pb-2" aria-label="Form sections">
                {sections.map(([id, label]) => (
                    <a key={id} href={`#${id}`} className="shrink-0 rounded-full border border-slate-200 px-3 py-1 text-sm text-slate-700">{label}</a>
                ))}
            </nav>
            {uploadNotice && <p className="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{uploadNotice}</p>}
            {submission.errors.form && <p className="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{submission.errors.form}</p>}
            {submission.progress && <p className="mt-4 text-sm text-slate-600" role="status">Uploading {submission.progress.percentage}%</p>}
            <form className="mt-4 grid gap-4" autoComplete="off" onSubmit={submit} onChange={() => setDirty(true)}>
                {sections.slice(0, 5).map(([id, label]) => (
                    <section key={id} id={id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h2 className="text-base font-semibold text-slate-950">{label}</h2>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {fields.filter((field) => field.section === id).map((field) => (
                                <label key={field.name} className={`grid gap-1.5 text-sm ${field.multiline ? 'sm:col-span-2' : ''}`}>
                                    <Label htmlFor={field.name}>{field.label}</Label>
                                    {field.multiline ? (
                                        <textarea id={field.name} className="min-h-24 rounded-md border border-input px-3 py-2 text-sm" value={String(submission.data[field.name] ?? '')} onChange={(event) => submission.setData(field.name, event.target.value)} />
                                    ) : (
                                        <Input id={field.name} type={field.type ?? 'text'} value={String(submission.data[field.name] ?? '')} onChange={(event) => submission.setData(field.name, event.target.value)} />
                                    )}
                                    <InputError message={submission.errors[field.name]} />
                                </label>
                            ))}
                            {id === 'banking' && (
                                <>
                                    <div className="grid gap-1.5 text-sm">
                                        <Label htmlFor="account_number">Account number</Label>
                                        <div className="flex gap-2">
                                            <Input id="account_number" type={showAccount ? 'text' : 'password'} inputMode="numeric" autoComplete="off" value={String(submission.data.account_number ?? '')} onChange={(event) => submission.setData('account_number', event.target.value)} />
                                            <Button type="button" variant="outline" aria-pressed={showAccount} onClick={() => setShowAccount((current) => !current)}>{showAccount ? 'Hide' : 'Show'}</Button>
                                        </div>
                                        <InputError message={submission.errors.account_number} />
                                    </div>
                                    <div className="grid gap-1.5 text-sm">
                                        <Label htmlFor="routing_number">Routing number</Label>
                                        <div className="flex gap-2">
                                            <Input id="routing_number" type={showRouting ? 'text' : 'password'} inputMode="numeric" autoComplete="off" value={String(submission.data.routing_number ?? '')} onChange={(event) => submission.setData('routing_number', event.target.value)} />
                                            <Button type="button" variant="outline" aria-pressed={showRouting} onClick={() => setShowRouting((current) => !current)}>{showRouting ? 'Hide' : 'Show'}</Button>
                                        </div>
                                        <InputError message={submission.errors.routing_number} />
                                    </div>
                                </>
                            )}
                            {id === 'authorization' && (
                                <div className="grid gap-1.5 text-sm sm:col-span-2">
                                    <Label htmlFor="ssn">Full Social Security number</Label>
                                    <div className="flex gap-2">
                                        <Input id="ssn" type={showSsn ? 'text' : 'password'} inputMode="numeric" autoComplete="off" value={String(submission.data.ssn ?? '')} onChange={(event) => submission.setData('ssn', event.target.value)} />
                                        <Button type="button" variant="outline" aria-pressed={showSsn} onClick={() => setShowSsn((current) => !current)}>{showSsn ? 'Hide' : 'Show'}</Button>
                                    </div>
                                    <InputError message={submission.errors.ssn} />
                                </div>
                            )}
                        </div>
                    </section>
                ))}
                <section id="documents" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 className="text-base font-semibold text-slate-950">Supporting documents</h2>
                    <p className="mt-1 text-sm text-slate-500">{form.accept}</p>
                    <div className="mt-3 grid gap-3">
                        {form.documents.map((document) => (
                            <label key={document.name} className="grid gap-1.5 text-sm">
                                <Label htmlFor={document.name}>{document.label}{document.required ? '' : ' (optional)'}</Label>
                                <span className="text-slate-500">{document.hint} {form.accept}</span>
                                <Input id={document.name} type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={(event) => submission.setData(document.name, event.target.files?.[0] ?? null)} />
                                <InputError message={submission.errors[document.name]} />
                            </label>
                        ))}
                    </div>
                </section>
                <section id="consent" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 className="text-base font-semibold text-slate-950">Privacy notice</h2>
                    <ul className="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-600">
                        {form.notice.map((line) => <li key={line}>{line}</li>)}
                    </ul>
                    <label className="mt-4 flex items-start gap-2 text-sm">
                        <input type="checkbox" checked={Boolean(submission.data.consent)} onChange={(event) => submission.setData('consent', event.target.checked)} />
                        <span>I have read this notice and I agree to submit this information and these documents for review.</span>
                    </label>
                    <InputError message={submission.errors.consent} />
                    {captchaSiteKey && (
                        <div className="mt-4 grid gap-1.5">
                            <div ref={captchaHost} />
                            <InputError message={submission.errors.captcha_token} />
                        </div>
                    )}
                    <label className="absolute h-0 w-0 overflow-hidden" aria-hidden="true">
                        Website
                        <input name="website" tabIndex={-1} autoComplete="off" value={String(submission.data.website ?? '')} onChange={(event) => submission.setData('website', event.target.value)} />
                    </label>
                    <Button className="mt-4" type="submit" disabled={submission.processing}>{submission.processing ? 'Submitting…' : 'Submit for review'}</Button>
                </section>
            </form>
        </main>
    );
}

interface TurnstileApi {
    render: (host: HTMLElement, options: Record<string, unknown>) => string;
    remove: (widgetId: string) => void;
}

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}
