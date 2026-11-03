import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function Login({ status, canResetPassword }: { status?: string; canResetPassword: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-[#eef3f9] px-4 py-10">
            <Head title="Log in" />
            <div className="grid w-full max-w-5xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm md:grid-cols-[1.1fr_0.9fr]">
                <div className="hidden flex-col justify-between bg-slate-950 bg-[radial-gradient(circle_at_top_left,rgba(37,99,235,0.35),transparent_42%)] p-10 text-white md:flex">
                    <img src="/brand/veltrix-logo.png" alt="Veltrix" className="h-10 w-auto max-w-[180px] bg-white object-contain p-1" />
                    <div>
                        <p className="text-sm text-blue-200">Human Resource Management</p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">One workspace for every company you support.</h1>
                        <p className="mt-3 max-w-sm text-sm leading-6 text-slate-300">Attendance, leave, holidays, payslips, and help desk for the company assigned to your account.</p>
                    </div>
                    <p className="text-xs text-slate-400">Access is issued by your company administrator.</p>
                </div>
                <div className="p-6 sm:p-10">
                    <img src="/brand/veltrix-logo.png" alt="Veltrix" className="mb-6 h-10 w-auto object-contain md:hidden" />
                    <h2 className="text-2xl font-semibold text-slate-900">Log in</h2>
                    <p className="mt-1 text-sm text-slate-500">Use the work email and password for your Veltrix HRMS account.</p>
                    {status && <p className="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{status}</p>}
                    <form className="mt-6 space-y-4" onSubmit={submit}>
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">Work email</Label>
                            <Input id="email" type="email" required autoFocus autoComplete="email" value={data.email} onChange={(event) => setData('email', event.target.value)} placeholder="name@company.com" />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-1.5">
                            <div className="flex items-center">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && (
                                    <Link href={route('password.request')} className="ml-auto text-sm text-blue-600" tabIndex={5}>
                                        Forgot password?
                                    </Link>
                                )}
                            </div>
                            <Input id="password" type="password" required autoComplete="current-password" value={data.password} onChange={(event) => setData('password', event.target.value)} />
                            <InputError message={errors.password} />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox id="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                            <Label htmlFor="remember">Remember me</Label>
                        </div>
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Log in
                        </Button>
                    </form>
                    <p className="mt-6 text-sm text-slate-500">Accounts are created by a company administrator. Public registration is closed.</p>
                </div>
            </div>
        </div>
    );
}
