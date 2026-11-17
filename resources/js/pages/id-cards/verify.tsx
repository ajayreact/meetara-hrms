import { Head } from '@inertiajs/react';

interface Verification {
    valid: boolean;
    status: string;
    name: string;
    employeeCode: string;
    designation: string;
    department: string;
    companyName: string;
    country: string;
    cardNumber: string;
    message: string;
}

export default function IdCardVerify({ verification }: { verification: Verification }) {
    return (
        <div className="min-h-screen bg-slate-100 px-4 py-10 text-slate-900">
            <Head title="ID card verification" />
            <div className="mx-auto w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400">ID card verification</p>
                <h1 className="mt-2 text-xl font-semibold">{verification.valid ? 'Verified card' : 'Card not valid'}</h1>
                <p className={`mt-2 text-sm ${verification.valid ? 'text-emerald-700' : 'text-rose-700'}`}>{verification.message}</p>

                {(verification.name || verification.employeeCode) && (
                    <dl className="mt-6 grid gap-3 text-sm">
                        {[
                            ['Name', verification.name],
                            ['ID / Card number', verification.employeeCode || verification.cardNumber],
                            ['Designation', verification.designation || '—'],
                            ['Department', verification.department || '—'],
                            ['Company', verification.companyName || '—'],
                            ['Status', verification.status],
                        ].map(([label, value]) => (
                            <div key={label} className="flex items-start justify-between gap-4 border-t border-slate-100 pt-3 first:border-t-0 first:pt-0">
                                <dt className="text-slate-500">{label}</dt>
                                <dd className="text-right font-medium text-slate-950">{value}</dd>
                            </div>
                        ))}
                    </dl>
                )}

                <p className="mt-6 text-[11px] leading-5 text-slate-400">
                    {verification.valid
                        ? 'This public page shows limited professional details only. Sensitive card fields are never shown here.'
                        : 'Invalid or deactivated cards do not disclose identity on this page.'}
                </p>
            </div>
        </div>
    );
}
