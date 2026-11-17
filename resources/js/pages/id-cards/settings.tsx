import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';
import { Building2, IdCard } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface Profile {
    id: number | null;
    country: string;
    legalName: string;
    displayName: string;
    officeAddress: string;
    contactNumber: string;
    email: string;
    website: string;
    footerAddress: string;
    hasLogo: boolean;
    logoUrl: string | null;
    status: string;
    updatedAt: string | null;
}

interface Preview {
    displayName: string;
    legalName: string;
    hasLogo: boolean;
    logoUrl: string | null;
    footerAddress: string;
    officeAddress: string;
    contactNumber: string;
    email: string;
    website: string;
    country: string;
}

const field = 'mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100';

export default function IdCardSettings({
    workspace,
    country = 'india',
    countries = [],
    profile,
    preview,
    notice,
}: HrmsPageProps & {
    country?: string;
    countries?: { value: string; label: string }[];
    profile: Profile;
    preview: Preview;
    notice?: string;
}) {
    const form = useForm({
        country,
        legal_name: profile.legalName,
        display_name: profile.displayName,
        office_address: profile.officeAddress,
        contact_number: profile.contactNumber,
        email: profile.email,
        website: profile.website,
        footer_address: profile.footerAddress,
        logo: null as File | null,
    });
    const [logoPreview, setLogoPreview] = useState<string | null>(null);

    useEffect(() => {
        form.setData({
            country,
            legal_name: profile.legalName,
            display_name: profile.displayName,
            office_address: profile.officeAddress,
            contact_number: profile.contactNumber,
            email: profile.email,
            website: profile.website,
            footer_address: profile.footerAddress,
            logo: null,
        });
        setLogoPreview(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [country, profile.id, profile.updatedAt]);

    useEffect(() => {
        if (form.data.logo === null) {
            setLogoPreview(null);

            return;
        }

        const url = URL.createObjectURL(form.data.logo);
        setLogoPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [form.data.logo]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('hrms.id-cards.settings.save'), { forceFormData: true });
    }

    const livePreview = {
        displayName: form.data.display_name || preview.displayName || 'Company display name',
        legalName: form.data.legal_name || preview.legalName || 'Company legal name',
        footerAddress: form.data.footer_address || preview.footerAddress || 'Footer address appears on the back of the card.',
        officeAddress: form.data.office_address || preview.officeAddress,
        contactNumber: form.data.contact_number || preview.contactNumber,
        email: form.data.email || preview.email,
        website: form.data.website || preview.website,
        logoSrc: logoPreview ?? (profile.hasLogo ? `${profile.logoUrl}&t=${encodeURIComponent(profile.updatedAt ?? '')}` : null),
    };

    return (
        <HrmsLayout workspace={workspace} title="ID card company details">
            <PageIntro
                title="ID card company details"
                subtitle="Save India and USA branding separately. The selected country’s footer address and logo print on employee ID cards."
            />
            {notice && <p className="mb-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div className="flex rounded-xl bg-slate-100 p-1">
                    {countries.map((item) => (
                        <Link
                            key={item.value}
                            href={route('hrms.id-cards.settings', { country: item.value })}
                            className={`rounded-lg px-4 py-1.5 text-sm font-medium ${country === item.value ? 'bg-blue-700 text-white' : 'text-slate-600 hover:text-slate-900'}`}
                        >
                            {item.label}
                        </Link>
                    ))}
                </div>
                <p className="text-sm text-slate-500">{country === 'india' ? 'India profile' : 'USA profile'} · employee cards and PDFs come later</p>
            </div>

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)]">
                <SectionCard title="Company Details" extra={<Building2 className="size-4 text-slate-400" aria-hidden="true" />}>
                    <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit}>
                        <input type="hidden" value={form.data.country} readOnly />
                        <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                            Company legal name
                            <input className={field} value={form.data.legal_name} onChange={(event) => form.setData('legal_name', event.target.value)} />
                            {form.errors.legal_name && <span className="text-rose-700">{form.errors.legal_name}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                            Company display name
                            <input className={field} value={form.data.display_name} onChange={(event) => form.setData('display_name', event.target.value)} />
                            {form.errors.display_name && <span className="text-rose-700">{form.errors.display_name}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                            Office address
                            <textarea className={field} rows={3} value={form.data.office_address} onChange={(event) => form.setData('office_address', event.target.value)} />
                            {form.errors.office_address && <span className="text-rose-700">{form.errors.office_address}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500">
                            Contact number
                            <input className={field} value={form.data.contact_number} onChange={(event) => form.setData('contact_number', event.target.value)} />
                            {form.errors.contact_number && <span className="text-rose-700">{form.errors.contact_number}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500">
                            Company email
                            <input className={field} type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                            {form.errors.email && <span className="text-rose-700">{form.errors.email}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                            Website
                            <input className={field} value={form.data.website} onChange={(event) => form.setData('website', event.target.value)} />
                            {form.errors.website && <span className="text-rose-700">{form.errors.website}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                            Footer address
                            <textarea className={field} rows={3} value={form.data.footer_address} onChange={(event) => form.setData('footer_address', event.target.value)} />
                            <span className="text-[11px] text-slate-400">Printed on the back of the ID card.</span>
                            {form.errors.footer_address && <span className="text-rose-700">{form.errors.footer_address}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                            Company logo
                            <input
                                className="mt-1.5 block w-full text-sm text-slate-600"
                                type="file"
                                accept="image/png,image/jpeg"
                                onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)}
                            />
                            <span className="text-[11px] text-slate-400">PNG or JPEG up to 2 MB. Stored privately for this company.</span>
                            {form.errors.logo && <span className="text-rose-700">{form.errors.logo}</span>}
                        </label>
                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={form.processing}>{profile.id ? 'Save changes' : 'Save company details'}</Button>
                        </div>
                    </form>
                </SectionCard>

                <div className="grid gap-4">
                    <SectionCard title="ID card preview" extra={<IdCard className="size-4 text-slate-400" aria-hidden="true" />}>
                        <div className="mx-auto w-full max-w-[17rem]">
                            <div className="overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white shadow-sm">
                                <div className="bg-gradient-to-br from-slate-900 via-slate-800 to-blue-900 px-4 py-5 text-white">
                                    <div className="flex items-center gap-3">
                                        {livePreview.logoSrc ? (
                                            <img src={livePreview.logoSrc} alt="" className="h-10 w-10 rounded-lg bg-white object-contain p-1" />
                                        ) : (
                                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-white/10 text-[10px] font-semibold uppercase tracking-wide">Logo</span>
                                        )}
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold">{livePreview.displayName}</p>
                                            <p className="truncate text-[11px] text-slate-300">{livePreview.legalName}</p>
                                        </div>
                                    </div>
                                    <div className="mt-6 rounded-xl bg-white/10 px-3 py-4 text-center">
                                        <div className="mx-auto mb-3 flex h-20 w-16 items-center justify-center rounded-lg border border-dashed border-white/30 bg-white/5 text-[10px] text-slate-200">Photo</div>
                                        <p className="text-sm font-semibold">Employee name</p>
                                        <p className="mt-1 text-[11px] text-slate-300">EMP-000 · Designation</p>
                                        <p className="text-[11px] text-slate-300">Department</p>
                                    </div>
                                </div>
                                <div className="space-y-2 px-4 py-4 text-xs text-slate-600">
                                    <p><span className="font-medium text-slate-900">Back footer</span></p>
                                    <p className="whitespace-pre-wrap leading-5">{livePreview.footerAddress}</p>
                                    {(livePreview.contactNumber || livePreview.email || livePreview.website) && (
                                        <p className="border-t border-slate-100 pt-2 text-[11px] text-slate-500">
                                            {[livePreview.contactNumber, livePreview.email, livePreview.website].filter(Boolean).join(' · ')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </SectionCard>
                    <SectionCard title="Office contact">
                        <dl className="grid gap-2 text-sm text-slate-600">
                            <div>
                                <dt className="text-xs text-slate-400">Office address</dt>
                                <dd className="mt-0.5 whitespace-pre-wrap">{livePreview.officeAddress || '—'}</dd>
                            </div>
                        </dl>
                    </SectionCard>
                </div>
            </div>
        </HrmsLayout>
    );
}
