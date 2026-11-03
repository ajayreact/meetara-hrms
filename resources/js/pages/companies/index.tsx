import { DataTable, bodyCell, bodyRow, headCell, selectedRow } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { PlatformEmpty } from '@/components/hrms/platform-mark';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm, type InertiaFormProps } from '@inertiajs/react';
import { useState } from 'react';

const weekDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const avatarTones = ['bg-blue-100 text-blue-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-700', 'bg-violet-100 text-violet-700', 'bg-rose-100 text-rose-700', 'bg-teal-100 text-teal-700'];

function initials(name: string) {
    const letters = name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? '');

    return letters.join('') || '?';
}

function avatarTone(name: string) {
    const code = [...name].reduce((sum, char) => sum + char.charCodeAt(0), 0);

    return avatarTones[code % avatarTones.length];
}

function CompanyIdentity({ name }: { name: string }) {
    return (
        <span className="flex min-w-0 items-center gap-3">
            <span aria-hidden="true" className={`flex size-9 shrink-0 items-center justify-center rounded-xl text-xs font-semibold ${avatarTone(name)}`}>{initials(name)}</span>
            <span className="min-w-0 truncate">{name}</span>
        </span>
    );
}

function CompanyStatus({ value }: { value: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span aria-hidden="true" className={`size-1.5 rounded-full ${value === 'Active' ? 'bg-emerald-500' : 'bg-slate-400'}`} />
            <StatusBadge value={value} />
        </span>
    );
}

function PeopleCount({ value }: { value: number }) {
    return <span className="inline-flex min-w-8 justify-center rounded-full bg-blue-50 px-2 py-0.5 text-sm font-medium text-blue-800 tabular-nums">{value}</span>;
}

interface CompanyRow {
    id: number;
    name: string;
    legalName: string;
    status: string;
    timezone: string;
    headcount: number;
    administrators: string[];
}

interface CompanyInvitation {
    id: number;
    email: string;
    state: 'pending' | 'accepted' | 'expired' | 'revoked';
    delivery: 'sent' | 'not_sent';
    createdAt: string;
    expiresAt: string;
    acceptedAt: string | null;
    revokedAt: string | null;
}

interface CompanyDetail extends CompanyRow {
    email: string;
    phone: string;
    address: string;
    weekOff: string;
    weekOffDays: string[];
    slug: string;
    statusKey: string;
    createdAt: string;
}

export default function CompaniesPage({
    workspace,
    companies = [],
    page = 1,
    pages = 1,
    search = '',
    status = 'all',
    detail = null,
    timezones = [],
    notice = null,
    error = null,
    invitation = null,
    mailConfigured = false,
    openForm = false,
}: HrmsPageProps & {
    companies?: CompanyRow[];
    page?: number;
    pages?: number;
    search?: string;
    status?: string;
    detail?: CompanyDetail | null;
    timezones?: string[];
    notice?: string | null;
    error?: string | null;
    invitation?: CompanyInvitation | null;
    mailConfigured?: boolean;
    openForm?: boolean;
}) {
    const [term, setTerm] = useState(search);
    const [creating, setCreating] = useState(openForm);

    function visit(next: { search?: string; status?: string; page?: number; company?: number }) {
        router.get('/companies', {
            search: next.search ?? '',
            status: (next.status ?? status) === 'all' ? undefined : (next.status ?? status),
            page: next.page && next.page > 1 ? next.page : undefined,
            company: next.company,
        }, { preserveState: true, preserveScroll: true });
    }

    return (
        <HrmsLayout workspace={workspace} title="Companies">
            <PageIntro title="Companies" subtitle="Companies saved on this platform. Billing plans are not connected." actions={<Button type="button" onClick={() => setCreating((value) => !value)}>{creating ? 'Close form' : 'Add company'}</Button>} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            {creating && <CreateCompany timezones={timezones} />}
            <SectionCard divided title="Directory">
                <form className="mb-4 flex flex-wrap items-center gap-2 rounded-2xl bg-slate-50 p-3" onSubmit={(event) => { event.preventDefault(); visit({ search: term, page: 1 }); }}>
                    <Input aria-label="Search companies" value={term} onChange={(event) => setTerm(event.target.value)} placeholder="Search by name" className="w-full bg-white sm:max-w-xs" />
                    <label className="text-sm text-slate-600">
                        <span className="sr-only">Status</span>
                        <select aria-label="Company status" className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={status} onChange={(event) => visit({ search: term, status: event.target.value, page: 1 })}>
                            <option value="all">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Not active</option>
                        </select>
                    </label>
                    <Button type="submit" variant="outline">Search</Button>
                    {search !== '' && <Button type="button" variant="ghost" onClick={() => { setTerm(''); visit({ search: '', page: 1 }); }}>Clear search</Button>}
                </form>
                {companies.length === 0 ? <PlatformEmpty>No companies match this search.</PlatformEmpty> : (
                    <>
                        <ul className="space-y-2 md:hidden">
                            {companies.map((company) => (
                                <li key={company.id} className={`flex gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-3 ${detail?.id === company.id ? 'border-l-[3px] border-l-blue-700' : ''}`}>
                                    <span aria-hidden="true" className={`flex size-10 shrink-0 items-center justify-center rounded-xl text-sm font-semibold ${avatarTone(company.name)}`}>{initials(company.name)}</span>
                                    <div className="min-w-0">
                                        <button type="button" className="text-left text-sm font-medium text-blue-700 hover:underline" onClick={() => visit({ search, status, page, company: company.id })}>{company.name}</button>
                                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                                            <CompanyStatus value={company.status} />
                                            <PeopleCount value={company.headcount} />
                                            <span>{company.timezone}</span>
                                        </div>
                                        <p className="mt-1 text-sm text-slate-500">{company.administrators.length === 0 ? 'No Company Admin' : company.administrators.join(', ')}</p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <div className="hidden md:block">
                            <DataTable>
                                <thead>
                                    <tr>
                                        <th className={headCell}>Company</th>
                                        <th className={headCell}>Status</th>
                                        <th className={headCell}>Time zone</th>
                                        <th className={headCell}>People</th>
                                        <th className={headCell}>Company Admin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {companies.map((company) => (
                                        <tr key={company.id} className={`${bodyRow} ${detail?.id === company.id ? selectedRow : ''}`}>
                                            <td className={`${bodyCell} font-medium`}>
                                                <button type="button" className="text-left text-blue-700 hover:underline" onClick={() => visit({ search, status, page, company: company.id })}>
                                                    <CompanyIdentity name={company.name} />
                                                </button>
                                            </td>
                                            <td className={bodyCell}><CompanyStatus value={company.status} /></td>
                                            <td className={bodyCell}>{company.timezone}</td>
                                            <td className={bodyCell}><PeopleCount value={company.headcount} /></td>
                                            <td className={bodyCell}>{company.administrators.length === 0 ? 'No Company Admin' : company.administrators.join(', ')}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </DataTable>
                        </div>
                    </>
                )}
                <div className="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm">
                    <span className="text-slate-500">Page {page} of {pages}</span>
                    {page > 1 && <Button type="button" variant="outline" onClick={() => visit({ search, status, page: page - 1 })}>Previous</Button>}
                    {page < pages && <Button type="button" variant="outline" onClick={() => visit({ search, status, page: page + 1 })}>Next</Button>}
                </div>
            </SectionCard>
            {detail && (
                <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                    <SectionCard divided title={detail.name}>
                        <dl className="grid gap-4 sm:grid-cols-2">
                            {([
                                ['Legal name', detail.legalName],
                                ['Slug', detail.slug],
                                ['Status', detail.status],
                                ['Time zone', detail.timezone],
                                ['People', String(detail.headcount)],
                                ['Company email', detail.email],
                                ['Phone', detail.phone],
                                ['Week off', detail.weekOff],
                                ['Address', detail.address],
                                ['Created', detail.createdAt],
                                ['Company Admin', detail.administrators.length === 0 ? 'No Company Admin' : detail.administrators.join(', ')],
                            ] as Array<[string, string]>).map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
                                    <dd className="mt-1 text-sm text-slate-900">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <StatusAction companyId={detail.id} statusKey={detail.statusKey} />
                        <CompanyInvitationPanel companyId={detail.id} invitation={invitation} mailConfigured={mailConfigured} hasAdmin={detail.administrators.length > 0} />
                    </SectionCard>
                    <CompanyEditor key={detail.id} detail={detail} timezones={timezones} />
                </div>
            )}
        </HrmsLayout>
    );
}

function CreateCompany({ timezones }: { timezones: string[] }) {
    const form = useForm({
        name: '',
        legal_name: '',
        slug: '',
        timezone: 'Asia/Kolkata',
        email: '',
        phone: '',
        address: '',
        status: 'active',
        week_off: ['saturday', 'sunday'] as string[],
        admin_name: '',
        admin_email: '',
        admin_password: '',
        admin_password_confirmation: '',
    });

    return (
        <SectionCard divided title="New company" className="mb-4 max-w-3xl">
            <CompanyFields form={form} timezones={timezones} slug />
            <label className="mb-4 grid gap-1.5 text-sm">
                <Label htmlFor="company-status">Status</Label>
                <select id="company-status" className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <InputError message={form.errors.status} />
            </label>
            <fieldset className="mb-4 grid gap-3">
                <legend className="text-sm font-medium text-slate-700">Company Admin</legend>
                <p className="text-sm text-slate-500">Leave these blank to save the company without an administrator. The account belongs only to this company.</p>
                <label className="grid gap-1.5 text-sm"><Label htmlFor="admin-name">Administrator name</Label><Input id="admin-name" value={form.data.admin_name} onChange={(event) => form.setData('admin_name', event.target.value)} autoComplete="off" /><InputError message={form.errors.admin_name} /></label>
                <label className="grid gap-1.5 text-sm"><Label htmlFor="admin-email">Administrator email</Label><Input id="admin-email" type="email" value={form.data.admin_email} onChange={(event) => form.setData('admin_email', event.target.value)} autoComplete="off" /><InputError message={form.errors.admin_email} /></label>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="grid gap-1.5 text-sm"><Label htmlFor="admin-password">Temporary password</Label><Input id="admin-password" type="password" value={form.data.admin_password} onChange={(event) => form.setData('admin_password', event.target.value)} autoComplete="new-password" /><InputError message={form.errors.admin_password} /></label>
                    <label className="grid gap-1.5 text-sm"><Label htmlFor="admin-password-confirmation">Confirm password</Label><Input id="admin-password-confirmation" type="password" value={form.data.admin_password_confirmation} onChange={(event) => form.setData('admin_password_confirmation', event.target.value)} autoComplete="new-password" /><InputError message={form.errors.admin_password_confirmation} /></label>
                </div>
            </fieldset>
            <Button type="button" disabled={form.processing} onClick={() => form.post('/companies')}>{form.processing ? 'Saving…' : 'Save company'}</Button>
        </SectionCard>
    );
}

function CompanyEditor({ detail, timezones }: { detail: CompanyDetail; timezones: string[] }) {
    const blank = (value: string) => (value === 'Not recorded' ? '' : value);
    const form = useForm({
        name: detail.name,
        legal_name: blank(detail.legalName),
        timezone: detail.timezone,
        email: blank(detail.email),
        phone: blank(detail.phone),
        address: blank(detail.address),
        week_off: detail.weekOffDays ?? [],
    });

    return (
        <SectionCard divided title="Edit company">
            <CompanyFields form={form} timezones={timezones} />
            <Button type="button" disabled={form.processing} onClick={() => form.put(`/companies/${detail.id}`)}>{form.processing ? 'Saving…' : 'Save changes'}</Button>
        </SectionCard>
    );
}

function CompanyFields<T extends { name: string; legal_name: string; timezone: string; email: string; phone: string; address: string; week_off: string[]; slug?: string }>({ form, timezones, slug = false }: { form: InertiaFormProps<T>; timezones: string[]; slug?: boolean }) {
    function toggleDay(day: string) {
        const current = form.data.week_off;
        form.setData('week_off', current.includes(day) ? current.filter((item) => item !== day) : [...current, day]);
    }

    return (
        <div className="mb-4 grid gap-3">
            <label className="grid gap-1.5 text-sm"><Label htmlFor={slug ? 'company-name' : 'edit-company-name'}>Company name</Label><Input id={slug ? 'company-name' : 'edit-company-name'} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /><InputError message={form.errors.name} /></label>
            <label className="grid gap-1.5 text-sm"><Label htmlFor={slug ? 'company-legal-name' : 'edit-company-legal-name'}>Legal company name</Label><Input id={slug ? 'company-legal-name' : 'edit-company-legal-name'} value={form.data.legal_name} onChange={(event) => form.setData('legal_name', event.target.value)} /><InputError message={form.errors.legal_name} /></label>
            {slug && <label className="grid gap-1.5 text-sm"><Label htmlFor="company-slug">Slug</Label><Input id="company-slug" value={form.data.slug ?? ''} onChange={(event) => form.setData('slug', event.target.value)} /><InputError message={form.errors.slug} /></label>}
            <label className="grid gap-1.5 text-sm"><Label htmlFor={slug ? 'company-timezone' : 'edit-company-timezone'}>Time zone</Label>
                <select id={slug ? 'company-timezone' : 'edit-company-timezone'} className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)}>
                    {timezones.map((zone) => <option key={zone}>{zone}</option>)}
                </select>
                <InputError message={form.errors.timezone} />
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1.5 text-sm"><Label htmlFor={slug ? 'company-email' : 'edit-company-email'}>Company email</Label><Input id={slug ? 'company-email' : 'edit-company-email'} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /><InputError message={form.errors.email} /></label>
                <label className="grid gap-1.5 text-sm"><Label htmlFor={slug ? 'company-phone' : 'edit-company-phone'}>Phone</Label><Input id={slug ? 'company-phone' : 'edit-company-phone'} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /><InputError message={form.errors.phone} /></label>
            </div>
            <label className="grid gap-1.5 text-sm"><Label htmlFor={slug ? 'company-address' : 'edit-company-address'}>Address</Label><textarea id={slug ? 'company-address' : 'edit-company-address'} className="min-h-20 rounded-lg border border-input px-3 py-2 text-sm" value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} /><InputError message={form.errors.address} /></label>
            <fieldset>
                <legend className="text-sm font-medium text-slate-700">Week off</legend>
                <div className="mt-2 flex flex-wrap gap-3">
                    {weekDays.map((day) => (
                        <label key={day} className="flex items-center gap-2 text-sm capitalize">
                            <input type="checkbox" checked={form.data.week_off.includes(day)} onChange={() => toggleDay(day)} />
                            {day}
                        </label>
                    ))}
                </div>
                <InputError message={form.errors.week_off} />
            </fieldset>
        </div>
    );
}

function StatusAction({ companyId, statusKey }: { companyId: number; statusKey: string }) {
    const inactive = statusKey !== 'active';
    const form = useForm({ status: inactive ? 'active' : 'inactive', confirm: '' });

    return (
        <form className="mt-4 grid max-w-md gap-2" onSubmit={(event) => { event.preventDefault(); form.post(`/companies/${companyId}/status`); }}>
            <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" checked={form.data.confirm === (inactive ? 'REACTIVATE' : 'DEACTIVATE')} onChange={(event) => form.setData('confirm', event.target.checked ? (inactive ? 'REACTIVATE' : 'DEACTIVATE') : '')} />
                <span>{inactive ? 'Reactivate this company and allow eligible users to sign in again.' : 'Deactivate this company. Records stay saved, and company users cannot sign in.'}</span>
            </label>
            <InputError message={form.errors.confirm} />
            <Button type="submit" variant={inactive ? 'default' : 'outline'} disabled={form.processing || form.data.confirm === ''}>{form.processing ? 'Saving…' : inactive ? 'Reactivate company' : 'Deactivate company'}</Button>
        </form>
    );
}

function CompanyInvitationPanel({ companyId, invitation, mailConfigured, hasAdmin }: { companyId: number; invitation: CompanyInvitation | null; mailConfigured: boolean; hasAdmin: boolean }) {
    const invite = useForm({ email: '' });
    const resend = useForm({});
    const revoke = useForm({ confirm: '' });
    const open = invitation && invitation.state !== 'accepted' && invitation.state !== 'revoked';
    const label = invitation?.state === 'accepted' ? 'Accepted' : invitation?.state === 'expired' ? 'Expired' : invitation?.state === 'revoked' ? 'Revoked' : invitation?.delivery === 'not_sent' ? 'Email not sent' : 'Pending';

    return (
        <div className="mt-6 border-t border-slate-100 pt-4">
            <h3 className="text-sm font-semibold text-slate-950">Company Admin invitation</h3>
            {invitation && (
                <div className="mt-3 grid gap-3 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge value={label} />
                        <span>{invitation.email}</span>
                    </div>
                    <dl className="grid gap-2 sm:grid-cols-2">
                        <div><dt className="text-xs uppercase tracking-wide text-slate-500">Created</dt><dd>{invitation.createdAt}</dd></div>
                        <div><dt className="text-xs uppercase tracking-wide text-slate-500">Expires</dt><dd>{invitation.expiresAt || 'Not recorded'}</dd></div>
                        {invitation.acceptedAt && <div><dt className="text-xs uppercase tracking-wide text-slate-500">Accepted</dt><dd>{invitation.acceptedAt}</dd></div>}
                        {invitation.revokedAt && <div><dt className="text-xs uppercase tracking-wide text-slate-500">Revoked</dt><dd>{invitation.revokedAt}</dd></div>}
                    </dl>
                    {invitation.delivery === 'not_sent' && invitation.state !== 'revoked' && invitation.state !== 'accepted' && <p className="text-amber-800">The invitation email was not sent.</p>}
                    {invitation.state === 'accepted' && <p className="text-slate-600">The Company Admin accepted this invitation.</p>}
                </div>
            )}
            {open && (
                <div className="mt-3 flex flex-wrap gap-2">
                    <Button type="button" variant="outline" disabled={resend.processing} onClick={() => resend.post(`/companies/${companyId}/invitations/${invitation.id}/resend`)}>{resend.processing ? 'Sending…' : 'Resend invitation'}</Button>
                </div>
            )}
            {open && (
                <form className="mt-3 grid max-w-md gap-2" onSubmit={(event) => { event.preventDefault(); revoke.post(`/companies/${companyId}/invitations/${invitation.id}/revoke`); }}>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" checked={revoke.data.confirm === 'REVOKE'} onChange={(event) => revoke.setData('confirm', event.target.checked ? 'REVOKE' : '')} />
                        <span>Revoke this invitation. The current link stops working.</span>
                    </label>
                    <InputError message={revoke.errors.confirm} />
                    <Button type="submit" variant="outline" disabled={revoke.processing || revoke.data.confirm === ''}>{revoke.processing ? 'Saving…' : 'Revoke invitation'}</Button>
                </form>
            )}
            {hasAdmin && !invitation && <p className="mt-3 text-sm text-slate-600">This company already has a Company Admin.</p>}
            {!hasAdmin && !open && (
                <form className="mt-3 grid max-w-md gap-2" onSubmit={(event) => { event.preventDefault(); invite.post(`/companies/${companyId}/invitations`); }}>
                    {!mailConfigured && <p className="text-sm text-amber-800">Mail delivery is not configured, so an invitation email cannot be sent.</p>}
                    <Label htmlFor="company-admin-email">Company Admin email</Label>
                    <Input id="company-admin-email" type="email" value={invite.data.email} onChange={(event) => invite.setData('email', event.target.value)} autoComplete="off" />
                    <InputError message={invite.errors.email} />
                    <Button type="submit" disabled={invite.processing}>{invite.processing ? 'Sending…' : 'Send invitation'}</Button>
                </form>
            )}
        </div>
    );
}
