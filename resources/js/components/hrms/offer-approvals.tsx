import { router } from '@inertiajs/react';

export interface OfferApprovalState {
    legal: {
        legalName: string;
        legalAddress: string;
        registrationDetails: string;
        signatoryName: string;
        signatoryDesignation: string;
        hrEmail: string;
        updatedAt: string | null;
        own: boolean;
    } | null;
    templates: Array<{ id: number; name: string; updatedAt: string | null; own: boolean }>;
}

export function OfferApprovals({ approvals, className = '' }: { approvals: OfferApprovalState; className?: string }) {
    const waiting = approvals.legal !== null || approvals.templates.length > 0;

    return (
        <section className={`rounded-2xl border border-amber-200/80 bg-gradient-to-br from-white to-amber-50/40 p-4 shadow-sm ${className}`.trim()}>
            <h2 className="text-base font-semibold text-slate-950">Pending Approvals</h2>
            <p className="mt-1 text-sm leading-5 text-slate-600">Approve India legal details and Appointment Letters from People → Offer templates. Your changes activate immediately.</p>
            {!waiting && (
                <p className="mt-3 rounded-xl border border-dashed border-amber-200 bg-white/70 px-3 py-2.5 text-sm text-slate-500">No India legal details or appointment letters are waiting.</p>
            )}
            {approvals.legal && (
                <article className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium text-slate-950">Legal company details</p>
                            <p className="mt-1 text-sm text-slate-800">{approvals.legal.legalName}</p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{approvals.legal.legalAddress}</p>
                            <p className="mt-1 text-sm text-slate-600">{approvals.legal.signatoryName} · {approvals.legal.signatoryDesignation}</p>
                            <p className="text-sm text-slate-600">{approvals.legal.hrEmail}{approvals.legal.registrationDetails ? ` · ${approvals.legal.registrationDetails}` : ''}</p>
                        </div>
                        {approvals.legal.own ? (
                            <p className="max-w-xs text-sm text-slate-600">Waiting for Head of Operations to approve.</p>
                        ) : (
                            <button className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" type="button" onClick={() => router.post(route('hrms.offer-letters.legal.verify'), { confirm: 'VERIFY', country: 'india' })}>Approve legal details</button>
                        )}
                    </div>
                </article>
            )}
            {approvals.templates.map((template) => (
                <article key={template.id} className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium text-slate-950">Appointment Letter</p>
                            <p className="mt-1 text-sm text-slate-700">{template.name}</p>
                        </div>
                        {template.own ? (
                            <p className="max-w-xs text-sm text-slate-600">Waiting for Head of Operations to approve.</p>
                        ) : (
                            <button className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.approve', template.id), { confirm: 'APPROVE' })}>Approve</button>
                        )}
                    </div>
                </article>
            ))}
        </section>
    );
}
