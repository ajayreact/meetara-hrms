import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, usePage } from '@inertiajs/react';

interface OfferRow {
    id: number;
    candidate: string;
    email: string;
    designation: string;
    payType: string;
    payAmount: string;
    workingHours: string;
    startDate: string;
    issueDate: string;
    status: string;
    createdAt: string;
    templateVersion: string;
}

interface StationeryState {
    letterhead: boolean;
    letterheadName: string;
    signature: boolean;
    signatureName: string;
}

const action = 'rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700';

function statusLabel(status: string): string {
    return status === 'generated' ? 'Generated' : 'Draft';
}

function rate(offer: OfferRow): string {
    return offer.payType === 'annual' ? `$${offer.payAmount} / year` : `$${offer.payAmount} / hour`;
}

export default function UsaOffers({ workspace, offers = [], designationCount = 0, stationery, notice }: HrmsPageProps & {
    offers?: OfferRow[];
    designationCount?: number;
    stationery?: StationeryState;
    notice?: string;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const ready = Boolean(stationery?.letterhead && stationery?.signature);

    return (
        <HrmsLayout workspace={workspace}>
            <PageIntro title="USA offers" subtitle="Save a draft, review it, then generate the PDF. Generated letters stay unchanged if settings are edited later." />
            {notice && <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
            {errors.stationery && <p className="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{errors.stationery}</p>}
            {!ready && <p className="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">Upload a company letterhead and an HR signature in USA offer settings before previewing or generating a PDF.</p>}
            <div className="mb-4 flex flex-wrap gap-3">
                <Link className="rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white" href={route('hrms.usa-offers.create')}>New USA offer</Link>
                <Link className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700" href={route('hrms.usa-offers.settings')}>USA offer settings</Link>
            </div>
            <SectionCard title="Designation library">
                <p className="text-sm text-slate-600">{designationCount} designations are available. Changing a default does not change offers that were already saved.</p>
            </SectionCard>
            <div className="mt-4">
                <SectionCard title="Offer letters">
                    {offers.length === 0 ? (
                        <p className="text-sm text-slate-600">No USA offers have been created.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="py-2 pr-3">Candidate</th>
                                        <th className="py-2 pr-3">Email</th>
                                        <th className="py-2 pr-3">Designation</th>
                                        <th className="py-2 pr-3">Hourly rate</th>
                                        <th className="py-2 pr-3">Working hours</th>
                                        <th className="py-2 pr-3">Start date</th>
                                        <th className="py-2 pr-3">Issue date</th>
                                        <th className="py-2 pr-3">Status</th>
                                        <th className="py-2 pr-3">Created</th>
                                        <th className="py-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {offers.map((offer) => (
                                        <tr key={offer.id}>
                                            <td className="py-3 pr-3 font-medium text-slate-950">{offer.candidate}</td>
                                            <td className="py-3 pr-3 text-slate-600">{offer.email || '—'}</td>
                                            <td className="py-3 pr-3">{offer.designation}</td>
                                            <td className="py-3 pr-3">{rate(offer)}</td>
                                            <td className="py-3 pr-3">{offer.workingHours}</td>
                                            <td className="py-3 pr-3">{offer.startDate}</td>
                                            <td className="py-3 pr-3">{offer.issueDate}</td>
                                            <td className="py-3 pr-3">{statusLabel(offer.status)}</td>
                                            <td className="py-3 pr-3">{offer.createdAt}</td>
                                            <td className="py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <Link className={action} href={route('hrms.usa-offers.show', offer.id)}>View</Link>
                                                    {offer.status !== 'generated' && <Link className={action} href={route('hrms.usa-offers.show', offer.id)}>Edit</Link>}
                                                    <a className={action} href={route('hrms.usa-offers.preview', offer.id)} target="_blank" rel="noreferrer">{offer.status === 'generated' ? 'Preview PDF' : 'Preview'}</a>
                                                    {offer.status !== 'generated' && <Link className={action} href={route('hrms.usa-offers.generate', offer.id)} method="post" as="button">Generate PDF</Link>}
                                                    {offer.status === 'generated' && <a className={action} href={route('hrms.usa-offers.download', offer.id)}>Download PDF</a>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
