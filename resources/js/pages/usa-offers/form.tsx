import { FormEvent } from 'react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm, usePage } from '@inertiajs/react';

interface DesignationChoice {
    id: number;
    name: string;
    group: string;
    responsibilities: string[];
}

interface OfferDraft {
    id: number;
    designationId: string;
    designationName: string;
    responsibilities: string[];
    candidateName: string;
    candidateEmail: string;
    candidatePhone: string;
    candidateAddress: string;
    employmentType: string;
    payType: string;
    payAmount: string;
    workingHours: string;
    workArrangement: string;
    university: string;
    startDate: string;
    issueDate: string;
    status: string;
    templateVersion: string;
    locked: boolean;
}

interface StationeryState {
    letterhead: boolean;
    signature: boolean;
}

const control = 'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm';

function today(): string {
    const date = new Date();

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export default function UsaOfferForm({ workspace, offer = null, designations = [], stationery, notice }: HrmsPageProps & {
    offer?: OfferDraft | null;
    designations?: DesignationChoice[];
    stationery?: StationeryState;
    notice?: string;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const ready = Boolean(stationery?.letterhead && stationery?.signature);
    const first = designations[0];
    const form = useForm({
        designation_id: offer?.designationId || (first ? String(first.id) : ''),
        responsibilities: offer ? offer.responsibilities.join('\n') : (first?.responsibilities.join('\n') ?? ''),
        candidate_name: offer?.candidateName ?? '',
        candidate_email: offer?.candidateEmail ?? '',
        candidate_phone: offer?.candidatePhone ?? '',
        candidate_address: offer?.candidateAddress ?? '',
        employment_type: offer?.employmentType ?? '',
        pay_type: offer?.payType ?? 'hourly',
        pay_amount: offer?.payAmount ?? '',
        working_hours: offer?.workingHours ?? '20 hours per week',
        work_arrangement: offer?.workArrangement ?? 'completely remote position',
        university: offer?.university ?? '',
        start_date: offer?.startDate ?? '',
        issue_date: offer?.issueDate ?? today(),
    });

    function selectDesignation(id: string) {
        const selected = designations.find((designation) => String(designation.id) === id);
        form.setData({
            ...form.data,
            designation_id: id,
            responsibilities: selected ? selected.responsibilities.join('\n') : '',
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (offer) {
            form.post(route('hrms.usa-offers.update', offer.id));
            return;
        }

        form.post(route('hrms.usa-offers.store'));
    }

    return (
        <HrmsLayout workspace={workspace}>
            <PageIntro
                title={offer ? offer.candidateName || 'USA offer' : 'New USA offer'}
                subtitle={offer ? `${offer.designationName} · ${offer.status} · ${offer.templateVersion}` : 'The company template stays the same. Responsibilities load from the designation and can be edited for this offer.'}
            />
            {notice && <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
            {!offer && <p className="mb-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700">Save the draft first. The saved offer then appears in USA offers, where you can edit it, preview the PDF, and generate it. Saving does not create a PDF.</p>}
            {offer && !offer.locked && <p className="mb-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700">This draft is saved. Preview checks the letter without storing a PDF. Generate PDF stores the private file and locks the offer.</p>}
            {(!ready || errors.stationery) && <p className="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{errors.stationery || 'Upload a company letterhead and an HR signature in USA offer settings before previewing or generating.'}</p>}
            <form className="grid gap-4" onSubmit={submit}>
                <SectionCard title="Candidate">
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="text-sm text-slate-700">Name<input className={control} value={form.data.candidate_name} onChange={(event) => form.setData('candidate_name', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Email<input className={control} value={form.data.candidate_email} onChange={(event) => form.setData('candidate_email', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Phone<input className={control} value={form.data.candidate_phone} onChange={(event) => form.setData('candidate_phone', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Address<textarea className={control} rows={2} value={form.data.candidate_address} onChange={(event) => form.setData('candidate_address', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Employment type<input className={control} value={form.data.employment_type} onChange={(event) => form.setData('employment_type', event.target.value)} disabled={offer?.locked} /></label>
                    </div>
                    {form.errors.candidate_name && <p className="mt-2 text-sm text-rose-700">{form.errors.candidate_name}</p>}
                </SectionCard>
                <SectionCard title="Designation and responsibilities">
                    <label className="text-sm text-slate-700">
                        Designation
                        <select className={control} value={form.data.designation_id} onChange={(event) => selectDesignation(event.target.value)} disabled={offer?.locked}>
                            {designations.map((designation) => (
                                <option key={designation.id} value={designation.id}>{designation.group} · {designation.name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="mt-3 block text-sm text-slate-700">
                        Responsibilities for this offer
                        <textarea className={control} rows={10} value={form.data.responsibilities} onChange={(event) => form.setData('responsibilities', event.target.value)} disabled={offer?.locked} />
                    </label>
                    <p className="mt-2 text-xs text-slate-500">One responsibility on each line. Saving this offer does not change the designation default.</p>
                    {form.errors.responsibilities && <p className="mt-2 text-sm text-rose-700">{form.errors.responsibilities}</p>}
                </SectionCard>
                <SectionCard title="Offer terms">
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="text-sm text-slate-700">Pay type
                            <select className={control} value={form.data.pay_type} onChange={(event) => form.setData('pay_type', event.target.value)} disabled={offer?.locked}>
                                <option value="hourly">Hourly</option>
                                <option value="annual">Annual</option>
                            </select>
                        </label>
                        <label className="text-sm text-slate-700">Amount (USD)<input className={control} value={form.data.pay_amount} onChange={(event) => form.setData('pay_amount', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Working hours<input className={control} value={form.data.working_hours} onChange={(event) => form.setData('working_hours', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Work arrangement<input className={control} value={form.data.work_arrangement} onChange={(event) => form.setData('work_arrangement', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">University<input className={control} value={form.data.university} onChange={(event) => form.setData('university', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Start date<input className={control} type="date" value={form.data.start_date} onChange={(event) => form.setData('start_date', event.target.value)} disabled={offer?.locked} /></label>
                        <label className="text-sm text-slate-700">Issue date<input className={control} type="date" value={form.data.issue_date} onChange={(event) => form.setData('issue_date', event.target.value)} disabled={offer?.locked} /></label>
                    </div>
                </SectionCard>
                <div className="flex flex-wrap gap-3">
                    {!offer?.locked && <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={form.processing}>Save draft</button>}
                    {offer && <a className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700" href={route('hrms.usa-offers.preview', offer.id)} target="_blank" rel="noreferrer">Preview PDF</a>}
                    {offer && !offer.locked && <Link className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white" href={route('hrms.usa-offers.generate', offer.id)} method="post" as="button">Generate PDF</Link>}
                    {offer?.locked && <a className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white" href={route('hrms.usa-offers.download', offer.id)}>Download PDF</a>}
                    <Link className="rounded-xl px-4 py-2 text-sm text-slate-600" href={route('hrms.usa-offers')}>Back</Link>
                </div>
            </form>
        </HrmsLayout>
    );
}
