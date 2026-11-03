import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const messages: Record<string, string> = {
    invalid: 'This invitation link is not valid.',
    expired: 'This invitation has expired. Ask HR to send a new one.',
    accepted: 'This invitation has already been used.',
    inactive: 'This invitation cannot be accepted while the company is inactive.',
    authenticated: 'Sign out before setting your password.',
};

export default function AcceptEmployeeInvitation({
    state,
    email = '',
    employeeName = '',
    companyName = '',
    expiresAt = '',
}: {
    state: string;
    email?: string;
    employeeName?: string;
    companyName?: string;
    expiresAt?: string;
}) {
    const form = useForm({ password: '', password_confirmation: '' });
    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(window.location.pathname, { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-[#eef3f9] px-4 py-10">
            <Head title="Set your password" />
            <div className="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
                <img src="/brand/veltrix-logo.png" alt="Veltrix" className="mb-6 h-10 w-auto object-contain" />
                <h1 className="text-2xl font-semibold text-slate-950">Set your password</h1>
                {state !== 'ready' ? (
                    <p className="mt-3 text-sm leading-6 text-slate-600">{messages[state] ?? messages.invalid}</p>
                ) : (
                    <form className="mt-6 space-y-4" onSubmit={submit}>
                        <p className="text-sm leading-6 text-slate-600">
                            {employeeName}, choose a password for your {companyName} account. The email address is fixed to this invitation. This link expires on {expiresAt}.
                        </p>
                        <div className="grid gap-1.5">
                            <Label htmlFor="employee-invitation-email">Email</Label>
                            <Input id="employee-invitation-email" value={email} readOnly />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="employee-invitation-password">Password</Label>
                            <Input id="employee-invitation-password" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="new-password" />
                            <p className="text-xs text-slate-500">Use at least 8 characters with upper and lower case letters and a number.</p>
                            <InputError message={form.errors.password} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="employee-invitation-password-confirmation">Confirm password</Label>
                            <Input id="employee-invitation-password-confirmation" type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} autoComplete="new-password" />
                        </div>
                        <InputError message={'invitation' in form.errors ? form.errors.invitation : undefined} />
                        <Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Saving…' : 'Activate account'}</Button>
                    </form>
                )}
            </div>
        </div>
    );
}
