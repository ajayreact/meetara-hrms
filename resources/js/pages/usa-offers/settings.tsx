import { FormEvent, useMemo, useState } from 'react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';

interface Designation {
    id: number;
    name: string;
    group: string;
    groupKey: string;
    active: boolean;
    used: boolean;
    count: number;
    responsibilities: string[];
}

interface StationeryState {
    letterhead: boolean;
    letterheadName: string;
    signature: boolean;
    signatureName: string;
}

const control = 'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm';

interface CompanyProfile {
    company_name?: string;
    address_line?: string;
    city_line?: string;
    country_line?: string;
    website?: string;
    email?: string;
    phone?: string;
    e_verify?: string;
    federal_id?: string;
    manager_name?: string;
    manager_title?: string;
    manager_email?: string;
    manager_phone?: string;
    hr_name?: string;
    hr_title?: string;
    hr_phone?: string;
}

export default function UsaOfferSettings({ workspace, designations = [], groups = {}, stationery, profile, notice }: HrmsPageProps & {
    designations?: Designation[];
    groups?: Record<string, string>;
    profile?: CompanyProfile;
    stationery?: StationeryState;
    notice?: string;
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const visible = useMemo(() => designations.filter((designation) => designation.name.toLowerCase().includes(query.trim().toLowerCase())), [designations, query]);
    const added = useForm({ name: '', group: 'software', responsibilities: '' });
    const company = useForm({
        company_name: profile?.company_name ?? '',
        address_line: profile?.address_line ?? '',
        city_line: profile?.city_line ?? '',
        country_line: profile?.country_line ?? '',
        website: profile?.website ?? '',
        email: profile?.email ?? '',
        phone: profile?.phone ?? '',
        e_verify: profile?.e_verify ?? '',
        federal_id: profile?.federal_id ?? '',
        manager_name: profile?.manager_name ?? '',
        manager_title: profile?.manager_title ?? '',
        manager_email: profile?.manager_email ?? '',
        manager_phone: profile?.manager_phone ?? '',
        hr_name: profile?.hr_name ?? '',
        hr_title: profile?.hr_title ?? '',
        hr_phone: profile?.hr_phone ?? '',
    });

    function addDesignation(event: FormEvent) {
        event.preventDefault();
        added.post(route('hrms.usa-offers.designations.add'));
    }

    return (
        <HrmsLayout workspace={workspace}>
            <PageIntro title="USA offer settings" subtitle="Company letterhead and HR signature are used on new USA offers. Previously generated PDFs stay as they were stored." />
            {notice && <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
            <div className="mb-4"><Link className="text-sm text-blue-700" href={route('hrms.usa-offers')}>Back to USA offers</Link></div>
            <StationeryUploads stationery={stationery} />
            <div className="mt-4">
            <SectionCard title="Company profile used on new letters">
                <p className="text-sm text-slate-600">New USA letters print the company name, address, EIN, contact, manager, and signatory saved here. A letter is not generated while the name, address, or federal ID is blank. Previously generated PDFs stay stored and are not rebuilt.</p>
                <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); company.post(route('hrms.usa-offers.settings.save')); }}>
                    <label className="text-sm text-slate-700">Company name<input className={control} value={company.data.company_name} onChange={(event) => company.setData('company_name', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Address<input className={control} value={company.data.address_line} onChange={(event) => company.setData('address_line', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">City and region<input className={control} value={company.data.city_line} onChange={(event) => company.setData('city_line', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Country<input className={control} value={company.data.country_line} onChange={(event) => company.setData('country_line', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Website<input className={control} value={company.data.website} onChange={(event) => company.setData('website', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Email<input className={control} value={company.data.email} onChange={(event) => company.setData('email', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Phone<input className={control} value={company.data.phone} onChange={(event) => company.setData('phone', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">E-Verify<input className={control} value={company.data.e_verify} onChange={(event) => company.setData('e_verify', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Federal ID (EIN)<input className={control} value={company.data.federal_id} onChange={(event) => company.setData('federal_id', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Reporting manager<input className={control} value={company.data.manager_name} onChange={(event) => company.setData('manager_name', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Manager title<input className={control} value={company.data.manager_title} onChange={(event) => company.setData('manager_title', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Manager email<input className={control} value={company.data.manager_email} onChange={(event) => company.setData('manager_email', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Manager phone<input className={control} value={company.data.manager_phone} onChange={(event) => company.setData('manager_phone', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Signatory name<input className={control} value={company.data.hr_name} onChange={(event) => company.setData('hr_name', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Signatory title<input className={control} value={company.data.hr_title} onChange={(event) => company.setData('hr_title', event.target.value)} /></label>
                    <label className="text-sm text-slate-700">Signatory phone<input className={control} value={company.data.hr_phone} onChange={(event) => company.setData('hr_phone', event.target.value)} /></label>
                    <div className="md:col-span-2">
                        <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white" type="submit" disabled={company.processing}>Save company profile</button>
                    </div>
                </form>
            </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Designations">
                    <input className={control} placeholder="Search designations" value={query} onChange={(event) => setQuery(event.target.value)} />
                    <p className="mt-3 text-sm text-slate-600">{visible.length} shown. Used designations stay on file and can be deactivated.</p>
                    <ul className="mt-3 divide-y divide-slate-100">
                        {visible.map((designation) => (
                            <DesignationRow key={designation.id} designation={designation} open={open === designation.id} onOpen={() => setOpen(open === designation.id ? null : designation.id)} />
                        ))}
                    </ul>
                </SectionCard>
            </div>
            <form className="mt-4" onSubmit={addDesignation}>
                <SectionCard title="Add a designation">
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="text-sm text-slate-700">Name<input className={control} value={added.data.name} onChange={(event) => added.setData('name', event.target.value)} /></label>
                        <label className="text-sm text-slate-700">Group
                            <select className={control} value={added.data.group} onChange={(event) => added.setData('group', event.target.value)}>
                                {Object.entries(groups).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </select>
                        </label>
                    </div>
                    <label className="mt-3 block text-sm text-slate-700">Default responsibilities
                        <textarea className={control} rows={5} value={added.data.responsibilities} onChange={(event) => added.setData('responsibilities', event.target.value)} />
                    </label>
                    {added.errors.name && <p className="mt-2 text-sm text-rose-700">{added.errors.name}</p>}
                    <button className="mt-3 rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700" type="submit" disabled={added.processing}>Add designation</button>
                </SectionCard>
            </form>
        </HrmsLayout>
    );
}

function StationeryUploads({ stationery }: { stationery?: StationeryState }) {
    const letterhead = useForm<{ letterhead: File | null }>({ letterhead: null });
    const signature = useForm<{ signature: File | null }>({ signature: null });

    function uploadLetterhead(event: FormEvent) {
        event.preventDefault();
        letterhead.post(route('hrms.usa-offers.settings.letterhead'), { forceFormData: true });
    }

    function uploadSignature(event: FormEvent) {
        event.preventDefault();
        signature.post(route('hrms.usa-offers.settings.signature'), { forceFormData: true });
    }

    return (
        <SectionCard title="Company Letterhead & Signature">
            <p className="text-sm text-slate-600">Upload the company letterhead and the HR signature separately. Both are stored privately for this company. Replacing a file does not change offers that were already generated.</p>
            {(!stationery?.letterhead || !stationery?.signature) && <p className="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">A letterhead and an HR signature are both required before a USA offer can be previewed or generated.</p>}
            <div className="mt-4 grid gap-4 md:grid-cols-2">
                <form onSubmit={uploadLetterhead}>
                    <h3 className="text-sm font-medium text-slate-950">Company letterhead</h3>
                    <p className="mt-1 text-xs text-slate-500">PNG, JPG, or JPEG up to 5 MB. A PDF is accepted only when this server can convert it.</p>
                    {stationery?.letterhead && (
                        <img className="mt-3 max-h-32 rounded-lg border border-slate-200 bg-white object-contain" src={route('hrms.usa-offers.settings.letterhead.preview')} alt="Current company letterhead" />
                    )}
                    {stationery?.letterheadName && <p className="mt-2 text-xs text-slate-600">{stationery.letterheadName}</p>}
                    <input className="mt-3 block text-sm" type="file" accept="image/png,image/jpeg,.pdf" onChange={(event) => letterhead.setData('letterhead', event.target.files?.[0] ?? null)} />
                    {letterhead.errors.letterhead && <p className="mt-2 text-sm text-rose-700">{letterhead.errors.letterhead}</p>}
                    <button className="mt-3 rounded-xl border border-slate-200 px-3 py-1.5 text-sm" type="submit" disabled={letterhead.processing}>{stationery?.letterhead ? 'Replace letterhead' : 'Upload letterhead'}</button>
                </form>
                <form onSubmit={uploadSignature}>
                    <h3 className="text-sm font-medium text-slate-950">HR signature</h3>
                    <p className="mt-1 text-xs text-slate-500">PNG, JPG, or JPEG up to 2 MB. It prints above the signatory line.</p>
                    {stationery?.signature && (
                        <img className="mt-3 max-h-24 rounded-lg border border-slate-200 bg-white object-contain" src={route('hrms.usa-offers.settings.signature.preview')} alt="Current HR signature" />
                    )}
                    {stationery?.signatureName && <p className="mt-2 text-xs text-slate-600">{stationery.signatureName}</p>}
                    <input className="mt-3 block text-sm" type="file" accept="image/png,image/jpeg" onChange={(event) => signature.setData('signature', event.target.files?.[0] ?? null)} />
                    {signature.errors.signature && <p className="mt-2 text-sm text-rose-700">{signature.errors.signature}</p>}
                    <button className="mt-3 rounded-xl border border-slate-200 px-3 py-1.5 text-sm" type="submit" disabled={signature.processing}>{stationery?.signature ? 'Replace signature' : 'Upload signature'}</button>
                </form>
            </div>
        </SectionCard>
    );
}

function DesignationRow({ designation, open, onOpen }: { designation: Designation; open: boolean; onOpen: () => void }) {
    const form = useForm({
        responsibilities: designation.responsibilities.join('\n'),
        active: designation.active ? 1 : 0,
    });

    function save(event: FormEvent) {
        event.preventDefault();
        form.post(route('hrms.usa-offers.designations.update', designation.id));
    }

    return (
        <li className="py-3">
            <button className="flex w-full items-center justify-between gap-3 text-left" type="button" onClick={onOpen}>
                <span>
                    <span className="block text-sm font-medium text-slate-950">{designation.name}</span>
                    <span className="text-xs text-slate-500">{designation.group} · {designation.count} responsibilities{designation.used ? ' · used by an offer' : ''}{designation.active ? '' : ' · inactive'}</span>
                </span>
                <span className="text-xs text-blue-700">{open ? 'Hide' : 'View'}</span>
            </button>
            {open && (
                <form className="mt-3" onSubmit={save}>
                    <textarea className={control} rows={8} value={form.data.responsibilities} onChange={(event) => form.setData('responsibilities', event.target.value)} />
                    <label className="mt-2 flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={Boolean(form.data.active)} onChange={(event) => form.setData('active', event.target.checked ? 1 : 0)} />
                        Active
                    </label>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button className="rounded-xl bg-blue-700 px-3 py-1.5 text-sm text-white" type="submit">Save default</button>
                        <button className="rounded-xl border border-slate-200 px-3 py-1.5 text-sm" type="button" onClick={() => router.post(route('hrms.usa-offers.designations.restore', designation.id))}>Restore default</button>
                    </div>
                </form>
            )}
        </li>
    );
}
