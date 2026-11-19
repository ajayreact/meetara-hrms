import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { FileUpload } from '@/components/ui/file-upload';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { IdCard, Pencil, UserRound } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

interface CardDetails {
    id: number | null;
    country: string;
    bloodGroup: string;
    emergencyContactNumber: string;
    dateOfBirth: string;
    designation: string;
    department: string;
    hasPhoto: boolean;
    photoUrl: string | null;
    status: string;
    updatedAt: string | null;
}

interface EmployeeChoice {
    id: number;
    code: string;
    name: string;
    email: string;
    department: string;
    designation: string;
    card: CardDetails;
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
const readonly = 'mt-1.5 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-sm text-slate-900';

export default function IdCardManage({
    workspace,
    employees = [],
    selectedEmployeeId = null,
    employee = null,
    card,
    countries = [],
    bloodGroups = [],
    issue,
    notice,
}: HrmsPageProps & {
    employees?: EmployeeChoice[];
    selectedEmployeeId?: number | null;
    employee?: EmployeeChoice | null;
    card: CardDetails;
    countries?: { value: string; label: string }[];
    bloodGroups?: string[];
    issue: IssueState;
    notice?: string;
}) {
    const canIssue = workspace.permissions.idCardsIssue;
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<EmployeeChoice | null>(employee ?? null);
    const [editOpen, setEditOpen] = useState(false);
    const form = useForm({
        employee_id: selectedEmployeeId ? String(selectedEmployeeId) : '',
        country: card.country || 'india',
        blood_group: card.bloodGroup || '',
        emergency_contact_number: card.emergencyContactNumber || '',
        designation: card.designation || employee?.designation || '',
        department: card.department || employee?.department || '',
        date_of_birth: card.dateOfBirth || '',
        photo: null as File | null,
    });
    const editForm = useForm({
        employee_id: selectedEmployeeId ? String(selectedEmployeeId) : '',
        country: card.country || 'india',
        blood_group: card.bloodGroup || '',
        emergency_contact_number: card.emergencyContactNumber || '',
        designation: card.designation || employee?.designation || '',
        department: card.department || employee?.department || '',
        date_of_birth: card.dateOfBirth || '',
        photo: null as File | null,
    });
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);

    const matches = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return employees
            .filter((row) => needle === '' || `${row.name} ${row.code} ${row.email}`.toLowerCase().includes(needle))
            .slice(0, 8);
    }, [employees, query]);

    useEffect(() => {
        setSelected(employee ?? null);
        const next = {
            employee_id: selectedEmployeeId ? String(selectedEmployeeId) : '',
            country: card.country || 'india',
            blood_group: card.bloodGroup || '',
            emergency_contact_number: card.emergencyContactNumber || '',
            designation: card.designation || employee?.designation || '',
            department: card.department || employee?.department || '',
            date_of_birth: card.dateOfBirth || '',
            photo: null as File | null,
        };
        form.setData(next);
        editForm.setData(next);
        setPhotoPreview(null);
        setEditOpen(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedEmployeeId, card.id, card.updatedAt]);

    useEffect(() => {
        if (form.data.photo === null) {
            setPhotoPreview(null);

            return;
        }

        const url = URL.createObjectURL(form.data.photo);
        setPhotoPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [form.data.photo]);

    function choose(row: EmployeeChoice) {
        router.get(route('hrms.id-cards', { employee: row.id }), {}, { preserveScroll: true });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('hrms.id-cards.save'), { forceFormData: true });
    }

    function openEdit() {
        editForm.setData({
            employee_id: form.data.employee_id,
            country: form.data.country,
            blood_group: form.data.blood_group,
            emergency_contact_number: form.data.emergency_contact_number,
            designation: form.data.designation,
            department: form.data.department,
            date_of_birth: form.data.date_of_birth,
            photo: null,
        });
        editForm.clearErrors();
        setEditOpen(true);
    }

    function submitEdit(event: FormEvent) {
        event.preventDefault();
        editForm.post(route('hrms.id-cards.save'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
    }

    const preview = issue.preview;
    const livePhoto = photoPreview
        ?? (preview?.front.photoUrl ? `${preview.front.photoUrl}?t=${encodeURIComponent(card.updatedAt ?? '')}` : null);
    const liveLogo = preview?.front.logoUrl
        ? `${preview.front.logoUrl}${preview.front.logoUrl.includes('?') ? '&' : '?'}t=${encodeURIComponent(card.updatedAt ?? '')}`
        : null;
    const shownDesignation = form.data.designation || preview?.front.designation || '—';
    const shownDepartment = form.data.department || preview?.front.department || '—';

    return (
        <HrmsLayout workspace={workspace} title="Employee ID cards">
            <PageIntro
                title="Employee ID card details"
                subtitle="Select an employee, save card-only fields, preview the CR80 front and back, then issue a print-ready PDF with a secure verification QR."
                actions={(
                    <div className="flex flex-wrap gap-3 text-sm">
                        <Link className="text-slate-500 underline" href={route('hrms.id-cards.standalone')}>Standalone cards</Link>
                        {workspace.permissions.idCardsIssue && (
                            <Link className="text-slate-500 underline" href={route('hrms.id-cards.bulk')}>Bulk issue</Link>
                        )}
                        <Link className="text-slate-500 underline" href={route('hrms.id-cards.settings')}>Company details</Link>
                    </div>
                )}
            />
            {notice && <p className="mb-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.05fr)_minmax(20rem,0.95fr)]">
                <div className="grid gap-4">
                    <SectionCard title="Select employee" extra={<UserRound className="size-4 text-slate-400" aria-hidden="true" />}>
                        <label className="grid gap-1 text-xs text-slate-500">
                            Search employee
                            <input className={field} value={query} placeholder="Name, employee ID, or email" onChange={(event) => setQuery(event.target.value)} />
                        </label>
                        {query.trim() !== '' && (
                            <div className="mt-3 overflow-hidden rounded-xl border border-slate-200">
                                {matches.length === 0 && <p className="px-3 py-2 text-sm text-slate-500">No matching employee.</p>}
                                {matches.map((row) => (
                                    <button
                                        key={row.id}
                                        type="button"
                                        className="block w-full border-t border-slate-100 px-3 py-2 text-left text-sm first:border-t-0 hover:bg-slate-50"
                                        onClick={() => choose(row)}
                                    >
                                        <span className="font-medium text-slate-900">{row.name}</span>
                                        <span className="ml-2 text-slate-500">{row.code}</span>
                                        {row.card.id && <span className="ml-2 text-[11px] uppercase tracking-wide text-blue-700">{row.card.status}</span>}
                                    </button>
                                ))}
                            </div>
                        )}
                        {form.errors.employee_id && <p className="mt-2 text-sm text-rose-700">{form.errors.employee_id}</p>}

                        {selected && (
                            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                {[
                                    ['Name', selected.name],
                                    ['Employee ID', selected.code],
                                    ['Email', selected.email || '—'],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-xs text-slate-400">{label}</dt>
                                        <dd className={readonly}>{value}</dd>
                                    </div>
                                ))}
                                <div>
                                    <dt className="text-xs text-slate-400">ID card designation</dt>
                                    <dd className={readonly}>{shownDesignation}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-slate-400">ID card department</dt>
                                    <dd className={readonly}>{shownDepartment}</dd>
                                </div>
                            </dl>
                        )}

                        {selected && card.id && (
                            <div className="mt-4">
                                <Button type="button" variant="outline" onClick={openEdit}>
                                    <Pencil className="mr-2 size-4" aria-hidden="true" />
                                    Edit ID Card
                                </Button>
                            </div>
                        )}
                    </SectionCard>

                    <SectionCard title="Card-only details" extra={<IdCard className="size-4 text-slate-400" aria-hidden="true" />}>
                        {!selected ? (
                            <p className="text-sm text-slate-500">Choose an employee to add or update ID card details. Date of birth is saved on the employee profile. Other card fields stay on the ID card.</p>
                        ) : (
                            <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit}>
                                <input type="hidden" value={form.data.employee_id} readOnly />
                                <label className="grid gap-1 text-xs text-slate-500">
                                    Card country
                                    <select className={field} value={form.data.country} onChange={(event) => form.setData('country', event.target.value)}>
                                        {countries.map((item) => (
                                            <option key={item.value} value={item.value}>{item.label}</option>
                                        ))}
                                    </select>
                                    {form.errors.country && <span className="text-rose-700">{form.errors.country}</span>}
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
                                <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                                    Date of birth
                                    <input
                                        className={field}
                                        type="date"
                                        required
                                        value={form.data.date_of_birth}
                                        onChange={(event) => form.setData('date_of_birth', event.target.value)}
                                    />
                                    <span className="text-[11px] text-slate-400">Required. Saved on the employee profile and printed on the card. An existing date is filled in automatically.</span>
                                    {form.errors.date_of_birth && <span className="text-rose-700">{form.errors.date_of_birth}</span>}
                                </label>
                                <label className="grid gap-1 text-xs text-slate-500">
                                    Designation
                                    <input
                                        className={field}
                                        value={form.data.designation}
                                        onChange={(event) => form.setData('designation', event.target.value)}
                                    />
                                    <span className="text-[11px] text-slate-400">Stored on the ID card only — does not change the HR profile.</span>
                                    {form.errors.designation && <span className="text-rose-700">{form.errors.designation}</span>}
                                </label>
                                <label className="grid gap-1 text-xs text-slate-500">
                                    Department
                                    <input
                                        className={field}
                                        value={form.data.department}
                                        onChange={(event) => form.setData('department', event.target.value)}
                                    />
                                    <span className="text-[11px] text-slate-400">Stored on the ID card only — does not change the HR profile.</span>
                                    {form.errors.department && <span className="text-rose-700">{form.errors.department}</span>}
                                </label>
                                <label className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                                    Emergency contact number
                                    <input
                                        className={field}
                                        value={form.data.emergency_contact_number}
                                        onChange={(event) => form.setData('emergency_contact_number', event.target.value)}
                                    />
                                    {form.errors.emergency_contact_number && <span className="text-rose-700">{form.errors.emergency_contact_number}</span>}
                                </label>
                                <div className="grid gap-1 text-xs text-slate-500 sm:col-span-2">
                                    Employee photo
                                    <FileUpload
                                        accept="image/png,image/jpeg"
                                        file={form.data.photo}
                                        hint="PNG or JPEG up to 2 MB. Stored privately for this company."
                                        error={form.errors.photo}
                                        onChange={(file) => form.setData('photo', file)}
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={form.processing}>{card.id ? 'Save changes' : 'Save card details'}</Button>
                                </div>
                            </form>
                        )}
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
                                    <Button type="button" onClick={() => router.post(route('hrms.id-cards.issue', card.id))}>
                                        {issue.hasPdf ? 'Re-generate PDF' : 'Issue ID card PDF'}
                                    </Button>
                                )}
                                {issue.hasPdf && issue.pdfUrl && (
                                    <a className="inline-flex h-9 items-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-900 hover:bg-slate-50" href={issue.pdfUrl} target="_blank" rel="noreferrer">
                                        Open PDF
                                    </a>
                                )}
                                {issue.canReissue && (
                                    <Button type="button" variant="outline" onClick={() => router.post(route('hrms.id-cards.reissue', card.id))}>
                                        Reissue card
                                    </Button>
                                )}
                                {issue.canDeactivate && (
                                    <Button type="button" variant="outline" onClick={() => router.post(route('hrms.id-cards.deactivate', card.id))}>
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
                                            <img src={liveLogo} alt="" className={`h-8 w-auto max-w-[9rem] rounded-sm object-contain p-0.5 ${preview.front.logoOnDark ? 'bg-slate-950' : 'bg-white'}`} />
                                        ) : (
                                            <div className="flex h-8 w-28 items-center justify-center rounded-md border border-dashed border-slate-300 text-[10px] text-slate-400">Logo</div>
                                        )}
                                        <p className="mt-2 text-xs font-semibold text-slate-900">{preview.front.companyDisplayName || 'Company'}</p>
                                        {livePhoto ? (
                                            <img src={livePhoto} alt="" className="mt-3 h-24 w-[4.5rem] rounded-md object-cover" />
                                        ) : (
                                            <div className="mt-3 flex h-24 w-[4.5rem] items-center justify-center rounded-md border border-dashed border-slate-300 text-[10px] text-slate-400">Photo</div>
                                        )}
                                        <p className="mt-3 text-sm font-semibold text-slate-950">{preview.front.name}</p>
                                        <p className="mt-1 text-[11px] text-slate-500">{preview.front.employeeCode}</p>
                                        <p className="text-[11px] text-slate-500">{shownDesignation}</p>
                                        <p className="text-[11px] text-slate-500">{shownDepartment}</p>
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
                                        <p className="text-center text-[11px] font-semibold uppercase tracking-wide text-slate-900">Employee details</p>
                                        <div className="mt-3 space-y-2">
                                            <p><span className="font-medium text-slate-900">Email</span><br />{selected?.email || preview.back.email || '—'}</p>
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

            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit ID Card</DialogTitle>
                        <DialogDescription>
                            Update designation and department for this ID card only. Employee HR profile values stay unchanged.
                        </DialogDescription>
                    </DialogHeader>
                    <form className="grid gap-4" onSubmit={submitEdit}>
                        <label className="grid gap-1 text-xs text-slate-500">
                            Designation
                            <input
                                className={field}
                                value={editForm.data.designation}
                                onChange={(event) => editForm.setData('designation', event.target.value)}
                                autoFocus
                            />
                            {editForm.errors.designation && <span className="text-rose-700">{editForm.errors.designation}</span>}
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500">
                            Department
                            <input
                                className={field}
                                value={editForm.data.department}
                                onChange={(event) => editForm.setData('department', event.target.value)}
                            />
                            {editForm.errors.department && <span className="text-rose-700">{editForm.errors.department}</span>}
                        </label>
                        {editForm.errors.card && <p className="text-sm text-rose-700">{editForm.errors.card}</p>}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={editForm.processing}>Save designation & department</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </HrmsLayout>
    );
}
