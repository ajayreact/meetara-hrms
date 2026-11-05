import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { Building2, Plus, UserCheck, Users } from 'lucide-react';
import { useState } from 'react';

interface NamedOption {
    id: number;
    name: string;
}

interface RoleOption {
    value: string;
    label: string;
}

interface DirectoryEmployee {
    id: number;
    code: string;
    name: string;
    email: string;
    department: string | null;
    designation: string | null;
    manager: string | null;
    office: string | null;
    joined: string | null;
    status: string;
    statusValue: string;
    invitation?: { state: string; expiresAt: string | null; sent: boolean };
}

interface EmployeesPageProps extends HrmsPageProps {
    employees: DirectoryEmployee[];
    filters: { q: string };
    options: {
        departments: NamedOption[];
        designations: NamedOption[];
        offices: NamedOption[];
        managers: NamedOption[];
        roles: RoleOption[];
    };
    canManage: boolean;
    canExport: boolean;
    followups?: {
        canManage: boolean;
        candidates: FollowCandidate[];
        owners: { id: number; name: string }[];
    };
    notice?: string | null;
    noticeTone?: 'success' | 'error';
}

interface FollowCandidate {
    id: number;
    ownerEmployeeId: number;
    name: string;
    email: string;
    phone: string;
    university: string;
    state: string;
    remarks: string;
    followUpOn: string;
    nextReminder: string | null;
    status: string;
    statusLabel: string;
    reminders: { id: number; at: string; notes: string; status: string }[];
    history: { action: string; note: string; at: string }[];
}

export default function EmployeesPage({ workspace, employees, filters, options, canManage, canExport, followups, notice, noticeTone = 'success' }: EmployeesPageProps) {
    const teamOnly = workspace.role === 'team_lead';
    const [open, setOpen] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [deactivate, setDeactivate] = useState<DirectoryEmployee | null>(null);
    const [followEmployee, setFollowEmployee] = useState<DirectoryEmployee | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [search, setSearch] = useState(filters.q);
    const [department, setDepartment] = useState('all');
    const [status, setStatus] = useState('all');
    const [office, setOffice] = useState('all');
    const departments = [...new Set(employees.map((employee) => employee.department).filter((value): value is string => Boolean(value)))].sort();
    const statuses = [...new Set(employees.map((employee) => employee.status))].sort();
    const offices = [...new Set(employees.map((employee) => employee.office).filter((value): value is string => Boolean(value)))].sort();
    const visible = employees.filter((employee) => (department === 'all' || employee.department === department) && (status === 'all' || employee.status === status) && (office === 'all' || employee.office === office));
    const activeCount = employees.filter((employee) => employee.statusValue === 'active').length;
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        employee_code: '',
        department_id: '',
        designation_id: '',
        location_id: '',
        manager_id: '',
        date_of_joining: '',
        holiday_calendar: 'india',
        employment_type: 'full_time',
        work_mode: 'office',
        role: '',
        create_login: false,
    });
    const followForm = useForm({
        name: '',
        email: '',
        phone: '',
        university: '',
        state: '',
        remarks: '',
        follow_up_on: '',
        remind_at: '',
        reminder_notes: '',
        status: 'new',
        owner_employee_id: '',
    });
    const owned = followups?.candidates.filter((candidate) => candidate.ownerEmployeeId === followEmployee?.id) ?? [];

    function submitSearch(event: React.FormEvent) {
        event.preventDefault();
        router.get(route('hrms.employees'), { q: search }, { preserveState: true, replace: true });
    }

    return (
        <HrmsLayout workspace={workspace} title="Employees">
            <section className="mb-4 rounded-2xl border border-blue-100 bg-gradient-to-r from-sky-50 to-white px-5 py-4">
                <h1 className="text-2xl font-semibold tracking-tight text-slate-950">{teamOnly ? 'My team' : 'Employees'}</h1>
                <p className="mt-1 text-sm text-slate-600">{teamOnly ? 'People who report to you in this company.' : 'Manage your people and employee records.'}</p>
            </section>
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <MetricCard tone="blue" icon={Users} label="Total employees" value={String(employees.length)} hint={filters.q ? 'Matching this search' : 'In this directory'} />
                <MetricCard tone="green" icon={UserCheck} label="Active employees" value={String(activeCount)} hint="Currently active" />
                <MetricCard tone="orange" icon={Building2} label="Departments" value={String(departments.length)} hint="Named on these records" />
            </div>
            {canManage && (options.departments.length === 0 || options.designations.length === 0) && (
                <p className="mb-4 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">
                    Department and designation choices come from this company.
                    {options.departments.length === 0 && <Link className="ml-2 text-blue-700 underline" href="/departments">Add a department</Link>}
                    {options.designations.length === 0 && <Link className="ml-2 text-blue-700 underline" href="/designations">Add a designation</Link>}
                </p>
            )}
            {workspace.role === 'company_admin' && !options.roles.some((role) => role.value === 'Accounts') && (
                <p className="mb-4 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">
                    Accounts access is not enabled. Enabling it does not assign it to anyone.
                    <Button type="button" variant="outline" className="ml-3" onClick={() => router.post('/company/access-levels/accounts')}>Enable Accounts access</Button>
                </p>
            )}
            <SectionCard
                title="Employee directory"
                extra={
                    <div className="flex gap-2">
                        {canExport && (
                            <Button type="button" variant="outline" onClick={() => { window.location.href = route('hrms.employees.export'); }}>
                                Download
                            </Button>
                        )}
                        {canManage && (
                            <Button type="button" onClick={() => { form.clearErrors(); form.reset(); setOpen(true); }}>
                                <Plus aria-hidden="true" />
                                Add Employee
                            </Button>
                        )}
                    </div>
                }
            >
                <form className="mb-3 flex flex-wrap items-center gap-2" onSubmit={submitSearch}>
                    <label className="block min-w-[14rem] flex-1 text-sm">
                        <span className="sr-only">Search employees</span>
                        <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, email, or ID" />
                    </label>
                    <Button type="submit" variant="outline">Search</Button>
                    {departments.length > 0 && (
                        <FilterSelect label="Department" value={department} onChange={setDepartment} options={departments} />
                    )}
                    {statuses.length > 0 && (
                        <FilterSelect label="Status" value={status} onChange={setStatus} options={statuses} />
                    )}
                    {offices.length > 0 && (
                        <FilterSelect label="Office" value={office} onChange={setOffice} options={offices} />
                    )}
                </form>
                {notice && <p className={`mb-3 rounded-lg px-3 py-2 text-sm ${noticeTone === 'error' ? 'bg-rose-50 text-rose-900' : 'bg-emerald-50 text-emerald-900'}`}>{notice}</p>}
                {employees.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                        {filters.q ? 'No employees match that search.' : 'No employees are recorded for this company yet.'}
                    </p>
                ) : visible.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">No employees match these filters.</p>
                ) : (
                    <DataTable>
                            <thead>
                                <tr>
                                    <th className={headCell}>Employee</th>
                                    <th className={headCell}>Department</th>
                                    <th className={headCell}>Reporting manager</th>
                                    <th className={headCell}>Office</th>
                                    <th className={headCell}>Joined</th>
                                    <th className={headCell}>Status</th>
                                    <th className={headCell}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((employee) => (
                                    <tr key={employee.id} className={bodyRow}>
                                        <td className={bodyCell}>
                                            <div className="flex items-center gap-3">
                                                <span className="flex size-9 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">{initials(employee.name)}</span>
                                                <span>
                                                    <span className="block font-medium">{employee.name}</span>
                                                    <span className="text-xs text-slate-500">{employee.code} · {employee.email}</span>
                                                </span>
                                            </div>
                                        </td>
                                        <td className={bodyCell}>{employee.department ?? '—'}<span className="block text-xs text-slate-500">{employee.designation ?? '—'}</span></td>
                                        <td className={bodyCell}>{employee.manager ?? '—'}</td>
                                        <td className={bodyCell}>{employee.office ?? '—'}</td>
                                        <td className={bodyCell}>{displayDate(employee.joined)}</td>
                                        <td className={bodyCell}><StatusBadge value={employee.status} /></td>
                                        <td className={bodyCell}>
                                            <div className="flex gap-2">
                                                <Button type="button" size="sm" variant="outline" onClick={() => router.visit(route('hrms.employees.show', employee.id))}>View</Button>
                                                {followups?.canManage && (
                                                    <Button type="button" size="sm" variant="outline" onClick={() => { followForm.reset(); setEditingId(null); setFollowEmployee(employee); }}>Candidate details</Button>
                                                )}
                                                {canManage && employee.invitation && ['pending', 'expired', 'not_sent'].includes(employee.invitation.state) && (
                                                    <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.employees.invitation', employee.id))}>
                                                        {employee.invitation.state === 'not_sent' ? 'Send invitation' : 'Resend invitation'}
                                                    </Button>
                                                )}
                                                {canManage && employee.statusValue !== 'inactive' && (
                                                    <Button type="button" size="sm" variant="outline" onClick={() => setDeactivate(employee)}>Deactivate</Button>
                                                )}
                                                {canManage && employee.statusValue === 'inactive' && (
                                                    <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.employees.status', employee.id), { status: 'active' })}>Activate</Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                    </DataTable>
                )}
                {employees.length > 0 && <p className="mt-3 text-xs text-slate-500">Showing {visible.length} of {employees.length} loaded {employees.length === 1 ? 'employee' : 'employees'}.</p>}
            </SectionCard>

            <Sheet open={followEmployee !== null} onOpenChange={(next) => { if (!next) setFollowEmployee(null); }}>
                <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle>Candidate details</SheetTitle>
                        <SheetDescription>Follow-ups for {followEmployee?.name}. These stay inside this company and are not emailed.</SheetDescription>
                    </SheetHeader>
                    <form className="mt-4 grid gap-3" onSubmit={(event) => {
                        event.preventDefault();
                        if (!followEmployee) return;
                        const payload = { ...followForm.data, owner_employee_id: String(followEmployee.id) };
                        if (editingId) {
                            followForm.transform(() => payload).put(route('hrms.follow-ups.update', editingId), { onSuccess: () => { followForm.reset(); setEditingId(null); } });
                            return;
                        }
                        followForm.transform(() => payload).post(route('hrms.follow-ups.store', followEmployee.id), { onSuccess: () => followForm.reset() });
                    }}>
                        <Field label="Candidate name" error={followForm.errors.name}><Input value={followForm.data.name} onChange={(event) => followForm.setData('name', event.target.value)} /></Field>
                        <Field label="Email address" error={followForm.errors.email}><Input type="email" value={followForm.data.email} onChange={(event) => followForm.setData('email', event.target.value)} /></Field>
                        <Field label="Phone number" error={followForm.errors.phone}><Input value={followForm.data.phone} onChange={(event) => followForm.setData('phone', event.target.value)} /></Field>
                        <Field label="University" error={followForm.errors.university}><Input value={followForm.data.university} onChange={(event) => followForm.setData('university', event.target.value)} /></Field>
                        <Field label="State" error={followForm.errors.state}><Input value={followForm.data.state} onChange={(event) => followForm.setData('state', event.target.value)} /></Field>
                        <Field label="Remarks" error={followForm.errors.remarks}><Input value={followForm.data.remarks} onChange={(event) => followForm.setData('remarks', event.target.value)} /></Field>
                        <Field label="Follow-up date" error={followForm.errors.follow_up_on}><Input type="date" value={followForm.data.follow_up_on} onChange={(event) => followForm.setData('follow_up_on', event.target.value)} /></Field>
                        <Field label="Reminder date and time" error={followForm.errors.remind_at}><Input type="datetime-local" value={followForm.data.remind_at} onChange={(event) => followForm.setData('remind_at', event.target.value)} /></Field>
                        <Field label="Reminder notes" error={followForm.errors.reminder_notes}><Input value={followForm.data.reminder_notes} onChange={(event) => followForm.setData('reminder_notes', event.target.value)} /></Field>
                        <Field label="Status" error={followForm.errors.status}>
                            <select className="h-10 w-full rounded-md border px-3 text-sm" value={followForm.data.status} onChange={(event) => followForm.setData('status', event.target.value)}>
                                <option value="new">New</option>
                                <option value="contacted">Contacted</option>
                                <option value="follow_up">Follow-up Required</option>
                                <option value="interested">Interested</option>
                                <option value="not_interested">Not Interested</option>
                                <option value="converted">Converted</option>
                            </select>
                        </Field>
                        <p className="text-sm text-slate-500">Assigned employee: {followEmployee?.name}</p>
                        <Button type="submit" disabled={followForm.processing}>{editingId ? 'Save changes' : 'Save candidate'}</Button>
                    </form>
                    <ul className="mt-4 space-y-3 text-sm">
                        {owned.map((candidate) => (
                            <li key={candidate.id} className="rounded-lg border border-slate-200 p-3">
                                <p className="font-medium">{candidate.name}</p>
                                <p className="text-slate-600">{candidate.email} · {candidate.phone || 'No phone'} · {candidate.statusLabel}</p>
                                <p className="text-slate-600">{candidate.university} {candidate.state}</p>
                                <p className="text-slate-500">Next follow-up {candidate.nextReminder || candidate.followUpOn || 'not set'}</p>
                                <Button type="button" size="sm" variant="outline" className="mt-2" onClick={() => {
                                    setEditingId(candidate.id);
                                    followForm.setData({
                                        name: candidate.name,
                                        email: candidate.email,
                                        phone: candidate.phone,
                                        university: candidate.university,
                                        state: candidate.state,
                                        remarks: candidate.remarks,
                                        follow_up_on: candidate.followUpOn,
                                        remind_at: '',
                                        reminder_notes: '',
                                        status: candidate.status,
                                        owner_employee_id: String(candidate.ownerEmployeeId),
                                    });
                                }}>Edit</Button>
                                {candidate.reminders.filter((reminder) => reminder.status === 'open').map((reminder) => (
                                    <button key={reminder.id} type="button" className="ml-2 text-blue-700 underline" onClick={() => router.post(route('hrms.follow-ups.complete', reminder.id))}>Complete reminder</button>
                                ))}
                                <ul className="mt-2 space-y-1 text-xs text-slate-500">
                                    {candidate.history.map((event, index) => <li key={`${event.at}-${index}`}>{event.note}</li>)}
                                </ul>
                            </li>
                        ))}
                        {owned.length === 0 && <li className="text-slate-500">No candidate follow-ups for this employee yet.</li>}
                    </ul>
                </SheetContent>
            </Sheet>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Add Employee</DialogTitle>
                        <DialogDescription>This saves the employee in {workspace.companyName ?? 'your company'} only.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="First name" error={form.errors.first_name}><Input value={form.data.first_name} onChange={(event) => form.setData('first_name', event.target.value)} /></Field>
                        <Field label="Last name" error={form.errors.last_name}><Input value={form.data.last_name} onChange={(event) => form.setData('last_name', event.target.value)} /></Field>
                        <Field label="Work email" error={form.errors.email}><Input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                        <Field label="Phone" error={form.errors.phone}><Input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                        <Field label="Employee ID" error={form.errors.employee_code}><Input value={form.data.employee_code} onChange={(event) => form.setData('employee_code', event.target.value)} /></Field>
                        <Field label="Joining date" error={form.errors.date_of_joining}><Input type="date" value={form.data.date_of_joining} onChange={(event) => form.setData('date_of_joining', event.target.value)} /></Field>
                        <Field label="Department" error={form.errors.department_id}>
                            <Select value={form.data.department_id} onChange={(value) => form.setData('department_id', value)} options={options.departments} />
                        </Field>
                        <Field label="Designation" error={form.errors.designation_id}>
                            <Select value={form.data.designation_id} onChange={(value) => form.setData('designation_id', value)} options={options.designations} />
                        </Field>
                        <Field label="Office" error={form.errors.location_id}>
                            <Select value={form.data.location_id} onChange={(value) => form.setData('location_id', value)} options={options.offices} />
                        </Field>
                        <Field label="Reporting manager" error={form.errors.manager_id}>
                            <Select value={form.data.manager_id} onChange={(value) => form.setData('manager_id', value)} options={options.managers} />
                            <span className="text-xs text-slate-500">Head of Operations covers every office. HR and a Team Lead report to Head of Operations. Other employees report to Head of Operations, HR, or a Team Lead.</span>
                        </Field>
                        <Field label="Holiday region" error={form.errors.holiday_calendar}>
                            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.holiday_calendar} onChange={(event) => form.setData('holiday_calendar', event.target.value)}>
                                <option value="india">India</option>
                                <option value="usa">United States</option>
                            </select>
                        </Field>
                        <Field label="Work mode" error={form.errors.work_mode}>
                            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.work_mode} onChange={(event) => form.setData('work_mode', event.target.value)}>
                                <option value="office">Office</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="remote">Remote</option>
                            </select>
                        </Field>
                        <Field label="Access level" error={form.errors.role}>
                            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                                <option value="">No login role</option>
                                {options.roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                            </select>
                        </Field>
                        <label className="flex items-center gap-2 self-end text-sm">
                            <input type="checkbox" checked={form.data.create_login} onChange={(event) => form.setData('create_login', event.target.checked)} />
                            Create login access
                        </label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="button" onClick={() => setConfirm(true)}>Review</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={confirm} onOpenChange={setConfirm}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Create this employee?</DialogTitle>
                        <DialogDescription>
                            {form.data.first_name} {form.data.last_name} will be saved for {workspace.companyName ?? 'this company'}.
                            {form.data.create_login ? ' A login is created as invitation pending. The employee sets their own password from the invitation email. The generated password is not shown.' : ' No login will be created.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirm(false)}>Back</Button>
                        <Button
                            type="button"
                            disabled={form.processing}
                            onClick={() => form.post(route('hrms.employees.store'), { onSuccess: () => { setConfirm(false); setOpen(false); } })}
                        >
                            {form.processing ? 'Saving…' : 'Save employee'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={deactivate !== null} onOpenChange={(value) => !value && setDeactivate(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Deactivate {deactivate?.name}?</DialogTitle>
                        <DialogDescription>The employee record stays in this company and sign-in is blocked. Nothing is deleted.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setDeactivate(null)}>Cancel</Button>
                        <Button
                            type="button"
                            onClick={() => {
                                if (!deactivate) return;
                                router.post(route('hrms.employees.status', deactivate.id), { status: 'inactive' }, { onSuccess: () => setDeactivate(null) });
                            }}
                        >
                            Deactivate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </HrmsLayout>
    );
}

function initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? '').join('');
}

function displayDate(value: string | null): string {
    const match = value ? /^(\d{4})-(\d{2})-(\d{2})/.exec(value) : null;

    if (!match) {
        return value ?? '—';
    }

    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function FilterSelect({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: string[] }) {
    return (
        <label className="text-sm text-slate-600">
            <span className="sr-only">{label}</span>
            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={value} onChange={(event) => onChange(event.target.value)} aria-label={label}>
                <option value="all">{label}</option>
                {options.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
        </label>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5 text-sm">
            <Label>{label}</Label>
            {children}
            {error && <span className="text-xs text-rose-600">{error}</span>}
        </div>
    );
}

function Select({ value, onChange, options }: { value: string; onChange: (value: string) => void; options: NamedOption[] }) {
    return (
        <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={value} onChange={(event) => onChange(event.target.value)}>
            <option value="">Not assigned</option>
            {options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
        </select>
    );
}
