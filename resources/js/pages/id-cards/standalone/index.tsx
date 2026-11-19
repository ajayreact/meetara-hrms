import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { FileUpload } from '@/components/ui/file-upload';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { IdCard, Search } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface CardDetails {
    id: number | null;
    fullName: string;
    cardNumber: string;
    designation: string;
    department: string;
    email: string;
    phone: string;
    country: string;
    bloodGroup: string;
    emergencyContactNumber: string;
    dateOfBirth: string;
    hasPhoto: boolean;
    photoUrl: string | null;
    status: string;
    updatedAt: string | null;
}

interface IssueState {
    ready: boolean;
    blockers: string[];
    canIssue: boolean;
    canReissue: boolean;
    canDeactivate: boolean;
    hasPdf: boolean;
    pdfUrl: string | null;
    verifyUrl: string | null;
    issuedAt: string | null;
    cardNumber: string | null;
    preview: {
        front: {
            companyDisplayName: string;
            hasLogo: boolean;
            logoUrl: string | null;
            logoOnDark?: boolean;
            hasPhoto: boolean;
            photoUrl: string | null;
            name: string;
            employeeCode: string;
            designation: string;
            department: string;
            dateOfBirth: string;
        };
        back: {
            email: string;
            bloodGroup: string;
            emergencyContactNumber: string;
            footerAddress: string;
            verifyUrl: string;
            hasSignature: boolean;
            signatureUrl: string | null;
            hasLogo?: boolean;
            logoUrl?: string | null;
        };
    } | null;
}

const field = 'mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100';

export default function StandaloneIdCardsPage({
    workspace,
    cards = [],
    selectedCardId = null,
    card,
    countries = [],
    bloodGroups = [],
    issue,
    query = '',
    notice,
}: HrmsPageProps & {
    cards?: CardDetails[];
    selectedCardId?: number | null;
    card: CardDetails;
    countries?: { value: string; label: string }[];
    bloodGroups?: string[];
    issue: IssueState;
    query?: string;
    notice?: string;
}) {
    const canIssue = workspace.permissions.idCardsIssue;
    const [search, setSearch] = useState(query);
    const form = useForm({
        full_name: card.fullName || '',
        card_number: card.cardNumber || '',
        designation: card.designation || '',
        department: card.department || '',
        email: card.email || '',
        phone: card.phone || '',
        country: card.country || 'india',
        blood_group: card.bloodGroup || '',
        emergency_contact_number: card.emergencyContactNumber || '',
        date_of_birth: card.dateOfBirth || '',
        photo: null as File | null,
    });
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);

    useEffect(() => {
        setSearch(query);
        form.setData({
            full_name: card.fullName || '',
            card_number: card.cardNumber || '',
            designation: card.designation || '',
            department: card.department || '',
            email: card.email || '',
            phone: card.phone || '',
            country: card.country || 'india',
            blood_group: card.bloodGroup || '',
            emergency_contact_number: card.emergencyContactNumber || '',
            date_of_birth: card.dateOfBirth || '',
            photo: null,
        });
        setPhotoPreview(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedCardId, card.id, card.updatedAt, query]);

    useEffect(() => {
        if (form.data.photo === null) {
            setPhotoPreview(null);

            return;
        }

        const url = URL.createObjectURL(form.data.photo);
        setPhotoPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [form.data.photo]);

    function runSearch(event: FormEvent) {
        event.preventDefault();
        router.get(route('hrms.id-cards.standalone'), { q: search, card: selectedCardId ?? undefined }, { preserveScroll: true });
    }

    function choose(row: CardDetails) {
        router.get(route('hrms.id-cards.standalone', { card: row.id, q: search || undefined }), {}, { preserveScroll: true });
    }

    function startCreate() {
        router.get(route('hrms.id-cards.standalone', { q: search || undefined }), {}, { preserveScroll: true });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (card.id) {
            form.post(route('hrms.id-cards.standalone.update', card.id), { forceFormData: true });
        } else {
            form.post(route('hrms.id-cards.standalone.store'), { forceFormData: true });
        }
    }

    const preview = issue.preview;
    const livePhoto = photoPreview
        ?? (preview?.front.photoUrl ? `${preview.front.photoUrl}?t=${encodeURIComponent(card.updatedAt ?? '')}` : null);
    const liveLogo = preview?.front.logoUrl
        ? `${preview.front.logoUrl}${preview.front.logoUrl.includes('?') ? '&' : '?'}t=${encodeURIComponent(card.updatedAt ?? '')}`
        : null;

    return (
        <HrmsLayout workspace={workspace} title="Standalone ID cards">
            <PageIntro
                title="Standalone ID cards"
                subtitle="Create print-ready ID cards for people who are not HRMS employees. Cards stay separate from payroll, attendance, and employee-linked workflows."
                actions={(
                    <div className="flex flex-wrap gap-3 text-sm">
                        <Link className="text-slate-500 underline" href={route('hrms.id-cards.standalone.import')}>Import Excel</Link>
                        {workspace.permissions.idCardsIssue && (
                            <Link className="text-slate-500 underline" href={route('hrms.id-cards.standalone.bulk')}>Bulk issue</Link>
                        )}
                        <Link className="text-slate-500 underline" href={route('hrms.id-cards')}>Employee cards</Link>
                        <Link className="text-slate-500 underline" href={route('hrms.id-cards.settings')}>Company details</Link>
                    </div>
                )}
            />
            {notice && <p className="mb-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.05fr)_minmax(20rem,0.95fr)]">
                <div className="grid gap-4">
                    <SectionCard title="Search & create" extra={<Search className="size-4 text-slate-400" aria-hidden="true" />}>
                        <form className="flex flex-wrap gap-2" onSubmit={runSearch}>
                            <input
                                className={`${field} mt-0 max-w-md flex-1`}
                                value={search}
                                placeholder="Name, card number, email, phone"
                                onChange={(event) => setSearch(event.target.value)}
                            />
                            <Button type="submit" variant="outline">Search</Button>
                            <Button type="button" variant="outline" onClick={startCreate}>New card</Button>
                        </form>
                        <div className="mt-3 overflow-hidden rounded-xl border border-slate-200">
                            {cards.length === 0 && <p className="px-3 py-2 text-sm text-slate-500">No standalone cards match this search.</p>}
                            {cards.map((row) => (
                                <button
                                    key={row.id}
                                    type="button"
                                    className={`block w-full border-t border-slate-100 px-3 py-2 text-left text-sm first:border-t-0 hover:bg-slate-50 ${selectedCardId === row.id ? 'bg-slate-50' : ''}`}
                                    onClick={() => choose(row)}
                                >
                                    <span className="font-medium text-slate-900">{row.fullName}</span>
                                    <span className="ml-2 text-slate-500">{row.cardNumber}</span>
                                    <span className="ml-2 text-[11px] uppercase tracking-wide text-blue-700">{row.status}</span>
                                </button>
                            ))}
                        </div>
                    </SectionCard>

                    <SectionCard title={card.id ? 'Edit standalone card' : 'Create standalone card'} extra={<IdCard className="size-4 text-slate-400" aria-hidden="true" />}>
                        <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit}>
                            <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                                Full name
                                <input className={field} value={form.data.full_name} onChange={(event) => form.setData('full_name', event.target.value)} />
                                {form.errors.full_name && <span className="text-rose-700">{form.errors.full_name}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Unique card number
                                <input className={field} value={form.data.card_number} onChange={(event) => form.setData('card_number', event.target.value)} />
                                {form.errors.card_number && <span className="text-rose-700">{form.errors.card_number}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Company profile
                                <select className={field} value={form.data.country} onChange={(event) => form.setData('country', event.target.value)}>
                                    {countries.map((item) => (
                                        <option key={item.value} value={item.value}>{item.label}</option>
                                    ))}
                                </select>
                                {form.errors.country && <span className="text-rose-700">{form.errors.country}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Designation
                                <input className={field} value={form.data.designation} onChange={(event) => form.setData('designation', event.target.value)} />
                                {form.errors.designation && <span className="text-rose-700">{form.errors.designation}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Department
                                <input className={field} value={form.data.department} onChange={(event) => form.setData('department', event.target.value)} />
                                {form.errors.department && <span className="text-rose-700">{form.errors.department}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Email
                                <input className={field} type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                                {form.errors.email && <span className="text-rose-700">{form.errors.email}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Phone number
                                <input className={field} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} />
                                {form.errors.phone && <span className="text-rose-700">{form.errors.phone}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Blood group
                                <select className={field} value={form.data.blood_group} onChange={(event) => form.setData('blood_group', event.target.value)}>
                                    <option value="">Select</option>
                                    {bloodGroups.map((group) => (
                                        <option key={group} value={group}>{group}</option>
                                    ))}
                                </select>
                                {form.errors.blood_group && <span className="text-rose-700">{form.errors.blood_group}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Date of birth
                                <input
                                    className={field}
                                    type="date"
                                    required
                                    value={form.data.date_of_birth}
                                    onChange={(event) => form.setData('date_of_birth', event.target.value)}
                                />
                                <span className="text-[11px] text-slate-400">Required. Printed on the card. Standalone cards are not linked to an employee profile.</span>
                                {form.errors.date_of_birth && <span className="text-rose-700">{form.errors.date_of_birth}</span>}
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Emergency contact
                                <input className={field} value={form.data.emergency_contact_number} onChange={(event) => form.setData('emergency_contact_number', event.target.value)} />
                                {form.errors.emergency_contact_number && <span className="text-rose-700">{form.errors.emergency_contact_number}</span>}
                            </label>
                            <div className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                                Photo
                                <FileUpload
                                    accept="image/png,image/jpeg"
                                    file={form.data.photo}
                                    hint="PNG or JPEG up to 2 MB. Stored privately under this company."
                                    error={form.errors.photo}
                                    onChange={(file) => form.setData('photo', file)}
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={form.processing}>{card.id ? 'Save changes' : 'Create card'}</Button>
                            </div>
                        </form>
                    </SectionCard>

                    {card.id && canIssue && (
                        <SectionCard title="Issue & print">
                            {issue.blockers.length > 0 && (
                                <ul className="mb-3 list-disc space-y-1 pl-5 text-sm text-amber-900">
                                    {issue.blockers.map((blocker) => <li key={blocker}>{blocker}</li>)}
                                </ul>
                            )}
                            {form.errors.card && <p className="mb-3 text-sm text-rose-700">{form.errors.card}</p>}
                            <div className="flex flex-wrap gap-2">
                                {issue.canIssue && (
                                    <Button type="button" onClick={() => router.post(route('hrms.id-cards.standalone.issue', card.id))}>
                                        {issue.hasPdf ? 'Re-generate PDF' : 'Issue ID card PDF'}
                                    </Button>
                                )}
                                {issue.hasPdf && issue.pdfUrl && (
                                    <a className="inline-flex h-9 items-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-900 hover:bg-slate-50" href={issue.pdfUrl} target="_blank" rel="noreferrer">
                                        Open PDF
                                    </a>
                                )}
                                {issue.canReissue && (
                                    <Button type="button" variant="outline" onClick={() => router.post(route('hrms.id-cards.standalone.reissue', card.id))}>
                                        Reissue card
                                    </Button>
                                )}
                                {issue.canDeactivate && (
                                    <Button type="button" variant="outline" onClick={() => router.post(route('hrms.id-cards.standalone.deactivate', card.id))}>
                                        Deactivate
                                    </Button>
                                )}
                            </div>
                            {issue.cardNumber && <p className="mt-3 text-xs text-slate-500">Card {issue.cardNumber}{issue.issuedAt ? ` · issued ${issue.issuedAt}` : ''}</p>}
                        </SectionCard>
                    )}
                </div>

                <SectionCard title="Front / back preview">
                    {!preview ? (
                        <p className="text-sm text-slate-500">Save card details to preview the vertical CR80 layout.</p>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                            <div>
                                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Front</p>
                                <div className="mx-auto aspect-[54/86] w-full max-w-[13.5rem] overflow-hidden rounded-[1.1rem] border border-slate-200 bg-white shadow-sm">
                                    <div className="flex h-full flex-col items-center bg-gradient-to-b from-slate-50 to-white px-3 py-3 text-center">
                                        {liveLogo ? (
                                            <img src={liveLogo} alt="" className={`h-8 w-auto max-w-[8rem] rounded-md object-contain p-0.5 ${preview.front.logoOnDark ? 'bg-slate-950' : 'bg-white'}`} />
                                        ) : (
                                            <div className="flex h-10 w-10 items-center justify-center rounded-md border border-dashed border-slate-300 text-[10px] text-slate-400">Logo</div>
                                        )}
                                        <p className="mt-2 text-xs font-semibold text-slate-900">{preview.front.companyDisplayName || 'Company'}</p>
                                        {livePhoto ? (
                                            <img src={livePhoto} alt="" className="mt-3 h-24 w-[4.5rem] rounded-md object-cover" />
                                        ) : (
                                            <div className="mt-3 flex h-24 w-[4.5rem] items-center justify-center rounded-md border border-dashed border-slate-300 text-[10px] text-slate-400">Photo</div>
                                        )}
                                        <p className="mt-3 text-sm font-semibold text-slate-950">{form.data.full_name || preview.front.name}</p>
                                        <p className="mt-1 text-[11px] text-slate-500">{form.data.card_number || preview.front.employeeCode}</p>
                                        <p className="text-[11px] text-slate-500">{form.data.designation || preview.front.designation || '—'}</p>
                                        <p className="text-[11px] text-slate-500">{form.data.department || preview.front.department || '—'}</p>
                                        <p className="mt-2 text-[11px] text-slate-700">DOB {form.data.date_of_birth || preview.front.dateOfBirth || '—'}</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Back</p>
                                <div className="mx-auto aspect-[54/86] w-full max-w-[13.5rem] overflow-hidden rounded-[1.1rem] border border-slate-200 bg-white shadow-sm">
                                    <div className="flex h-full flex-col px-3 py-3 text-xs text-slate-600">
                                        {preview.back.logoUrl ? (
                                            <img src={`${preview.back.logoUrl}${preview.back.logoUrl.includes('?') ? '&' : '?'}t=${encodeURIComponent(card.updatedAt ?? '')}`} alt="" className="mx-auto mb-2 h-8 w-auto max-w-[8rem] object-contain" />
                                        ) : null}
                                        <p className="text-center text-[11px] font-semibold uppercase tracking-wide text-slate-900">Card details</p>
                                        <div className="mt-3 space-y-2">
                                            <p><span className="font-medium text-slate-900">Email</span><br />{form.data.email || preview.back.email || '—'}</p>
                                            <p><span className="font-medium text-slate-900">Blood group</span><br />{form.data.blood_group || preview.back.bloodGroup || '—'}</p>
                                            <p><span className="font-medium text-slate-900">Emergency</span><br />{form.data.emergency_contact_number || preview.back.emergencyContactNumber || '—'}</p>
                                        </div>
                                        <div className="mx-auto mt-4 flex h-20 w-20 items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 text-[10px] text-slate-400">QR</div>
                                        <p className="mt-2 text-center text-[10px] text-slate-400">Scan to verify</p>
                                        <div className="mt-3 text-center">
                                            {preview.back.hasSignature && preview.back.signatureUrl ? (
                                                <img src={`${preview.back.signatureUrl}${preview.back.signatureUrl.includes('?') ? '&' : '?'}t=${encodeURIComponent(card.updatedAt ?? '')}`} alt="HR authorized signature" className="mx-auto h-8 w-auto max-w-[7rem] object-contain" />
                                            ) : (
                                                <div className="mx-auto flex h-8 w-28 items-center justify-center rounded-md border border-dashed border-slate-300 text-[10px] text-slate-400">HR signature</div>
                                            )}
                                            <p className="mt-1 text-[10px] text-slate-400">Authorized Signatory (HR)</p>
                                        </div>
                                        <p className="mt-auto border-t border-slate-100 pt-2 text-center text-[10px] leading-4 text-slate-500 whitespace-pre-wrap">{preview.back.footerAddress || 'Company footer address'}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
