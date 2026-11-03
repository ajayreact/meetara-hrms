import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const messages: Record<string, string> = {
    invalid: 'This invitation link is not valid.',
    expired: 'This invitation has expired. Ask a Super Admin to send a new one.',
    revoked: 'This invitation is no longer available.',
    accepted: 'This invitation has already been used.',
    inactive: 'This invitation cannot be accepted while the company is inactive.',
    authenticated: 'Sign out before accepting an invitation.',
};

export default function AcceptInvitation({
    state,
    email = '',
    companyName = '',
}: {
    state: string;
    email?: string;
    companyName?: string;
}) {
    const form = useForm({ name: '', password: '', password_confirmation: '' });
    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(window.location.pathname, { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-[#eef3f9] px-4 py-10">
            <Head title="Accept invitation" />
            <div className="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
                <img src="/brand/veltrix-logo.png" alt="Veltrix" className="mb-6 h-10 w-auto object-contain" />
                <h1 className="text-2xl font-semibold text-slate-950">Accept invitation</h1>
                {state !== 'ready' ? (
                    <p className="mt-3 text-sm leading-6 text-slate-600">{messages[state] ?? messages.invalid}</p>
                ) : (
                    <form className="mt-6 space-y-4" onSubmit={submit}>
                        <p className="text-sm leading-6 text-slate-600">Create the Company Admin account for {companyName}. The email address is fixed to this invitation.</p>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invitation-email">Email</Label>
                            <Input id="invitation-email" value={email} readOnly />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invitation-name">Your name</Label>
                            <Input id="invitation-name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoComplete="name" />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invitation-password">Password</Label>
                            <Input id="invitation-password" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="new-password" />
                            <InputError message={form.errors.password} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="invitation-password-confirmation">Confirm password</Label>
                            <Input id="invitation-password-confirmation" type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} autoComplete="new-password" />
                        </div>
                        <InputError message={'invitation' in form.errors ? form.errors.invitation : undefined} />
                        <Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Saving…' : 'Create account'}</Button>
                    </form>
                )}
            </div>
        </div>
    );
}
