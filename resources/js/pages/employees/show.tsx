import { HrmsLayout } from '@/components/hrms/hrms-layout';
import { PageTabs, TabPanel } from '@/components/hrms/page-tabs';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { Activity, Building2, CalendarDays, FileText, Globe, LayoutGrid, ListChecks, Mail, MapPin, ScrollText, UserRound } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface NamedOption {
    id: number;
    name: string;
}

interface RoleOption {
    value: string;
    label: string;
}

interface EmployeeDetail {
    id: number;
    code: string;
    name: string;
    firstName: string;
    lastName: string;
    email: string;
    phone: string | null;
    department: string | null;
    designation: string | null;
    manager: string | null;
    office: string | null;
    joined: string | null;
    dateOfBirth?: string;
    status: string;
    statusValue: string;
    employmentType: string;
    workMode: string;
    holidayCalendar: string;
    departmentId: number | null;
    designationId: number | null;
    officeId: number | null;
    managerId: number | null;
    login: string;
    updatedAt: string | null;
    invitation?: { state: string; expiresAt: string | null; sent: boolean };
}

interface OnboardingTask {
    id: number;
    code: string;
    title: string;
    status: string;
    required: boolean;
    note: string | null;
    decisionNote: string | null;
    decisionAt: string | null;
    decisionBy: string | null;
    completedAt: string | null;
    completedBy: string | null;
}

interface EmployeeDocumentRow {
    id: number;
    category: string;
    categoryValue: string;
    status: string;
    uploadedAt: string;
    fileName: string;
    reviewer: string | null;
    reviewedAt: string | null;
    note: string | null;
}

interface OnboardingState {
    ready: boolean;
    started: boolean;
    status: string;
    tasks: OnboardingTask[];
    documents: EmployeeDocumentRow[];
    canStart: boolean;
    canUpload: boolean;
    canReview: boolean;
    canDecide: boolean;
    decisionsReady: boolean;
    categories: Array<{ value: string; label: string }>;
}

interface OfferLetterRow {
    id: number;
    position: string;
    status: string;
    version: number;
}

interface ActivityEvent {
    id: number;
    action: string;
    detail: string;
    actor: string | null;
    at: string | null;
    note: string | null;
}

interface ShowPageProps extends HrmsPageProps {
    employee: EmployeeDetail;
    options: {
        departments: NamedOption[];
        designations: NamedOption[];
        offices: NamedOption[];
        managers: NamedOption[];
        roles: RoleOption[];
    };
    canManage: boolean;
    onboarding: OnboardingState;
    offerLetters: { visible: boolean; letters: OfferLetterRow[] };
    activity: { ready: boolean; events: ActivityEvent[] };
    notice?: string | null;
    noticeTone?: 'success' | 'error';
}

function initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? '').join('');
}

function displayDate(value: string | null | undefined): string {
    if (!value) {
        return 'Not recorded';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(value);

    if (!match) {
        return value;
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4] ?? 0), Number(match[5] ?? 0));
    const day = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

    if (!match[4]) {
        return day;
    }

    return `${day}, ${date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
}

const labels: Record<string, string> = {
    full_time: 'Full time',
    part_time: 'Part time',
    contract: 'Contract',
    office: 'Office',
    hybrid: 'Hybrid',
    remote: 'Remote',
    india: 'India',
    usa: 'United States',
};

export default function EmployeeShowPage({ workspace, employee, options, canManage, onboarding, offerLetters, activity, notice, noticeTone = 'success' }: ShowPageProps) {
    const required = onboarding.tasks.filter((task) => task.required);
    const completed = required.filter((task) => task.status === 'Completed').length;
    const tabs = [
        { id: 'overview', label: 'Overview', icon: LayoutGrid },
        { id: 'onboarding', label: 'Onboarding', icon: ListChecks },
        { id: 'documents', label: 'Documents', icon: FileText },
        ...(offerLetters.visible ? [{ id: 'offers', label: 'Offer Letters', icon: ScrollText }] : []),
        ...(activity.ready ? [{ id: 'activity', label: 'Activity', icon: Activity }] : []),
    ];
    const [tab, setTab] = useState('overview');
    const meta = [
        { icon: Building2, label: 'Department', value: employee.department ?? 'Not assigned' },
        { icon: UserRound, label: 'Manager', value: employee.manager ?? 'Not assigned' },
        { icon: MapPin, label: 'Office', value: employee.office ?? 'Not assigned' },
        { icon: CalendarDays, label: 'Joining date', value: displayDate(employee.joined) },
        { icon: Mail, label: 'Work email', value: employee.email },
        { icon: Globe, label: 'Holiday calendar', value: labels[employee.holidayCalendar] ?? employee.holidayCalendar },
    ];

    return (
        <HrmsLayout workspace={workspace} title={employee.name}>
            {notice && <p className={`mb-3 rounded-lg px-3 py-2 text-sm ${noticeTone === 'error' ? 'bg-rose-50 text-rose-900' : 'bg-emerald-50 text-emerald-900'}`}>{notice}</p>}
            <section className="mb-4 overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-4 bg-gradient-to-r from-sky-50 to-white p-4 sm:p-5">
                    <div className="flex min-w-0 items-center gap-4">
                        <span aria-hidden="true" className="flex size-16 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xl font-semibold text-white">{initials(employee.name)}</span>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-tight text-slate-950">{employee.name}</h1>
                                <StatusBadge value={employee.status} />
                            </div>
                            <p className="mt-1 text-sm text-slate-600">
                                {[employee.designation, employee.code, labels[employee.employmentType] ?? employee.employmentType, labels[employee.workMode] ?? employee.workMode].filter(Boolean).join(' · ')}
                            </p>
                        </div>
                    </div>
                    <Button type="button" variant="outline" asChild><Link href={route('hrms.employees')}>Back to directory</Link></Button>
                </div>
                <dl className="grid gap-3 border-t border-slate-100 px-4 py-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    {meta.map((item) => (
                        <div key={item.label} className="min-w-0">
                            <dt className="flex items-center gap-1.5 text-xs text-slate-500">
                                <item.icon className="size-3.5 shrink-0" aria-hidden="true" />
                                {item.label}
                            </dt>
                            <dd className="mt-0.5 truncate text-sm font-medium text-slate-900">{item.value}</dd>
                        </div>
                    ))}
                </dl>
            </section>
            <PageTabs label="Employee record" tabs={tabs} value={tab} onChange={setTab} />
            <TabPanel id="overview" active={tab === 'overview'}>
                <div className="grid items-start gap-4 xl:grid-cols-2">
                    <div className="grid gap-4">
                        <SectionCard title="Employment summary">
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Fact label="Employee ID" value={employee.code} />
                                <Fact label="Login" value={employee.login} />
                                <Fact label="Employment status" value={employee.status} />
                                <Fact label="Invitation" value={invitationText(employee.invitation)} />
                                <Fact label="Onboarding" value={onboarding.started ? `${completed} of ${required.length || 0} required` : onboarding.status} />
                            </dl>
                            {onboarding.started && required.length > 0 && (
                                <div className="mt-4">
                                    <div className="mb-1 flex justify-between text-xs text-slate-500">
                                        <span>Required checklist</span>
                                        <span>{completed}/{required.length}</span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin={0} aria-valuemax={required.length} aria-valuenow={completed} aria-label="Required checklist">
                                        <div className="h-full bg-blue-600" style={{ width: `${Math.round((completed / required.length) * 100)}%` }} />
                                    </div>
                                </div>
                            )}
                            {canManage && employee.invitation && employee.invitation.state !== 'none' && employee.invitation.state !== 'accepted' && (
                                <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                                    <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.employees.invitation', employee.id))}>
                                        {employee.invitation.state === 'not_sent' ? 'Send invitation' : 'Resend invitation'}
                                    </Button>
                                    {employee.statusValue !== 'inactive' && (
                                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.employees.status', employee.id), { status: 'inactive' })}>Deactivate employee</Button>
                                    )}
                                </div>
                            )}
                            {canManage && employee.statusValue !== 'inactive' && employee.invitation?.state !== 'pending' && employee.invitation?.state !== 'expired' && employee.invitation?.state !== 'not_sent' && (
                                <div className="mt-4 border-t border-slate-100 pt-4">
                                    <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.employees.status', employee.id), { status: 'inactive' })}>Deactivate employee</Button>
                                </div>
                            )}
                        </SectionCard>
                        {activity.ready && (
                            <SectionCard title="Recent activity" extra={<Button type="button" variant="outline" size="sm" onClick={() => setTab('activity')}>View all</Button>}>
                                {activity.events.length === 0 ? <p className="text-sm text-slate-600">No record events are stored for this employee.</p> : (
                                    <ul className="grid gap-3">
                                        {activity.events.slice(0, 4).map((event) => (
                                            <li key={event.id} className="flex items-start justify-between gap-3 text-sm">
                                                <span className="min-w-0">
                                                    <span className="block font-medium text-slate-900">{event.action}</span>
                                                    {event.detail && <span className="block text-xs text-slate-500">{event.detail}</span>}
                                                </span>
                                                <span className="shrink-0 text-xs text-slate-500">{displayDate(event.at)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </SectionCard>
                        )}
                    </div>
                    {canManage ? (
                        <EditEmployee employee={employee} options={options} workspaceRole={workspace.role} onInvalid={() => setTab('overview')} />
                    ) : (
                        <SectionCard title="Profile">
                            <p className="text-sm text-slate-600">Your account can view this record. Employment fields are changed by Company Admin or HR.</p>
                            <p className="mt-2 text-sm text-slate-700">Phone: {employee.phone ?? 'Not recorded'}</p>
                        </SectionCard>
                    )}
                </div>
            </TabPanel>
            <TabPanel id="onboarding" active={tab === 'onboarding'}>
                <OnboardingPanel employeeId={employee.id} onboarding={onboarding} completed={completed} total={required.length} />
            </TabPanel>
            <TabPanel id="documents" active={tab === 'documents'}>
                <DocumentsPanel employeeId={employee.id} onboarding={onboarding} />
            </TabPanel>
            {offerLetters.visible && (
                <TabPanel id="offers" active={tab === 'offers'}>
                    <SectionCard title="Offer letters" extra={<Button type="button" variant="outline" asChild><Link href={route('hrms.offer-letters')}>Open offer letters</Link></Button>}>
                        {offerLetters.letters.length === 0 ? (
                            <p className="text-sm text-slate-600">No offer letter is linked to this employee. Issuance stays on the offer letters page.</p>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {offerLetters.letters.map((letter) => (
                                    <li key={letter.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                                        <span>
                                            <span className="font-medium">{letter.position}</span>
                                            <span className="block text-xs text-slate-500">Version {letter.version}</span>
                                        </span>
                                        <StatusBadge value={letter.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </TabPanel>
            )}
            {activity.ready && (
                <TabPanel id="activity" active={tab === 'activity'}>
                    <SectionCard title="Record events">
                        {activity.events.length === 0 ? <p className="text-sm text-slate-600">No record events are stored for this employee.</p> : (
                            <ul className="divide-y divide-slate-100">
                                {activity.events.map((event) => (
                                    <li key={event.id} className="py-3 text-sm">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="font-medium text-slate-900">{event.action}</p>
                                            <p className="text-xs text-slate-500">{event.actor ?? 'Not recorded'}{event.at ? ` · ${event.at}` : ''}</p>
                                        </div>
                                        {event.detail && <p className="mt-1 text-slate-600">{event.detail}</p>}
                                        {event.note && <p className="mt-1 text-slate-700">{event.note}</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </TabPanel>
            )}
        </HrmsLayout>
    );
}

function invitationText(invitation: EmployeeDetail['invitation']): string {
    if (!invitation || invitation.state === 'none') {
        return 'No invitation';
    }
    if (invitation.state === 'accepted') {
        return 'Accepted';
    }
    if (invitation.state === 'not_sent') {
        return 'Not sent. Mail delivery may be unconfigured.';
    }
    if (invitation.state === 'expired') {
        return `Expired ${invitation.expiresAt ?? ''}`.trim();
    }

    return `Pending until ${invitation.expiresAt ?? 'the expiry date'}${invitation.sent ? '. Email sent.' : '.'}`;
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="text-sm font-medium text-slate-900">{value}</dd>
        </div>
    );
}

function EditEmployee({ employee, options, workspaceRole, onInvalid }: { employee: EmployeeDetail; options: ShowPageProps['options']; workspaceRole: string; onInvalid: () => void }) {
    const form = useForm({
        first_name: employee.firstName,
        last_name: employee.lastName,
        email: employee.email,
        phone: employee.phone ?? '',
        employee_code: employee.code,
        department_id: employee.departmentId ? String(employee.departmentId) : '',
        designation_id: employee.designationId ? String(employee.designationId) : '',
        location_id: employee.officeId ? String(employee.officeId) : '',
        manager_id: employee.managerId ? String(employee.managerId) : '',
        date_of_joining: employee.joined ?? '',
        date_of_birth: employee.dateOfBirth ?? '',
        holiday_calendar: employee.holidayCalendar,
        employment_type: employee.employmentType,
        work_mode: employee.workMode,
        role: '',
        create_login: false,
    });

    useEffect(() => {
        if (Object.keys(form.errors).length > 0) {
            onInvalid();
        }
    }, [form.errors, onInvalid]);

    return (
        <SectionCard title="Edit employee">
            <form
                className="grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(route('hrms.employees.update', employee.id));
                }}
            >
                <fieldset className="grid gap-3">
                    <legend className="text-sm font-semibold text-slate-800">Personal information</legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="First name" error={form.errors.first_name}><Input value={form.data.first_name} onChange={(event) => form.setData('first_name', event.target.value)} /></Field>
                        <Field label="Last name" error={form.errors.last_name}><Input value={form.data.last_name} onChange={(event) => form.setData('last_name', event.target.value)} /></Field>
                        <Field label="Work email" error={form.errors.email}><Input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                        <Field label="Phone" error={form.errors.phone}><Input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                    </div>
                </fieldset>
                <fieldset className="grid gap-3 border-t border-slate-100 pt-4">
                    <legend className="text-sm font-semibold text-slate-800">Employment information</legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Employee ID" error={form.errors.employee_code}><Input value={form.data.employee_code} onChange={(event) => form.setData('employee_code', event.target.value)} /></Field>
                        <Field label="Joining date" error={form.errors.date_of_joining}><Input type="date" value={form.data.date_of_joining} onChange={(event) => form.setData('date_of_joining', event.target.value)} /></Field>
                        <Field label="Date of birth" error={form.errors.date_of_birth}><Input type="date" value={form.data.date_of_birth} onChange={(event) => form.setData('date_of_birth', event.target.value)} /></Field>
                        <Field label="Department" error={form.errors.department_id}><Select value={form.data.department_id} onChange={(value) => form.setData('department_id', value)} options={options.departments} /></Field>
                        <Field label="Designation" error={form.errors.designation_id}><Select value={form.data.designation_id} onChange={(value) => form.setData('designation_id', value)} options={options.designations} /></Field>
                        <Field label="Office" error={form.errors.location_id}><Select value={form.data.location_id} onChange={(value) => form.setData('location_id', value)} options={options.offices} /></Field>
                        <Field label="Reporting manager" error={form.errors.manager_id}><Select value={form.data.manager_id} onChange={(value) => form.setData('manager_id', value)} options={options.managers} /></Field>
                        <Field label="Employment type" error={form.errors.employment_type}>
                            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.employment_type} onChange={(event) => form.setData('employment_type', event.target.value)}>
                                <option value="full_time">Full time</option>
                                <option value="part_time">Part time</option>
                                <option value="contract">Contract</option>
                            </select>
                        </Field>
                        <Field label="Work mode" error={form.errors.work_mode}>
                            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.work_mode} onChange={(event) => form.setData('work_mode', event.target.value)}>
                                <option value="office">Office</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="remote">Remote</option>
                            </select>
                        </Field>
                        <Field label="Holiday calendar" error={form.errors.holiday_calendar}>
                            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.holiday_calendar} onChange={(event) => form.setData('holiday_calendar', event.target.value)}>
                                <option value="india">India</option>
                                <option value="usa">United States</option>
                            </select>
                        </Field>
                        {employee.login !== 'Not linked' && (
                            <Field label="Access level" error={form.errors.role}>
                                <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                                    <option value="">Keep the current access level</option>
                                    {options.roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                                </select>
                            </Field>
                        )}
                    </div>
                </fieldset>
                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save Changes'}</Button>
                    <Button type="button" variant="outline" onClick={() => form.reset()}>Cancel</Button>
                    {workspaceRole === 'company_admin' && !options.roles.some((role) => role.value === 'Accounts') && (
                        <Button type="button" variant="outline" onClick={() => router.post('/company/access-levels/accounts')}>Enable Accounts access</Button>
                    )}
                </div>
            </form>
        </SectionCard>
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

function OnboardingPanel({ employeeId, onboarding, completed, total }: { employeeId: number; onboarding: OnboardingState; completed: number; total: number }) {
    const historicalTasks = onboarding.tasks.filter((task) => !task.required);
    const requiredTasks = onboarding.tasks.filter((task) => task.required);
    const historicalOpen = historicalTasks.some((task) => task.status === 'Pending');

    return (
        <div className="grid gap-4">
            <SectionCard title="Required checklist" extra={<StatusBadge value={onboarding.status} />}>
                {!onboarding.ready && <p className="text-sm text-slate-600">Onboarding storage is not ready on this database.</p>}
                {onboarding.ready && !onboarding.started && (
                    <div className="grid gap-3">
                        <p className="text-sm text-slate-600">This employee record does not complete onboarding by itself.</p>
                        {onboarding.canStart && <div><Button type="button" onClick={() => router.post(route('hrms.employees.onboarding', employeeId))}>Start onboarding checklist</Button></div>}
                    </div>
                )}
                {onboarding.started && (
                    <>
                        <p className="mb-3 text-sm text-slate-600">{completed} of {total} required items are complete.</p>
                        <ul className="grid gap-2">
                            {requiredTasks.map((task) => (
                                <li key={task.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2">
                                    <div>
                                        <p className="text-sm font-medium text-slate-900">{task.title}</p>
                                        {task.completedBy && <p className="text-xs text-slate-500">{task.completedBy}{task.completedAt ? ` · ${task.completedAt}` : ''}</p>}
                                    </div>
                                    <StatusBadge value={task.status} />
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </SectionCard>
            {onboarding.ready && onboarding.started && (
                <SectionCard title="Historical tasks">
                    <p className="text-sm text-slate-600">These tasks are not part of the required checklist. Their outcome does not change whether the required checklist is complete.</p>
                    {onboarding.status === 'Completed' && <p className="mt-2 text-sm text-slate-700">The required checklist is complete.</p>}
                    {historicalOpen && <p className="mt-2 text-sm text-slate-700">Some historical tasks are still open.</p>}
                    {!onboarding.decisionsReady && <p className="mt-2 text-sm text-amber-800">Decision storage is not ready on this database.</p>}
                    {historicalTasks.length === 0 ? <p className="mt-3 text-sm text-slate-600">No historical tasks are recorded for this employee.</p> : (
                        <div className="mt-3 grid gap-2">
                            {historicalTasks.map((task) => (
                                <details key={task.id} className="rounded-lg border border-slate-100 px-3 py-2">
                                    <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2">
                                        <span>
                                            <span className="text-sm font-medium text-slate-900">{task.title}</span>
                                            {task.note && <span className="block text-xs text-slate-500">{task.note}</span>}
                                        </span>
                                        <StatusBadge value={task.status} />
                                    </summary>
                                    <div className="mt-3 border-t border-slate-100 pt-3">
                                        {task.decisionNote && <p className="text-sm text-slate-700">{task.decisionNote}</p>}
                                        {task.status === 'Completed' && task.completedBy && <p className="text-xs text-slate-500">{task.completedBy}{task.completedAt ? ` · ${task.completedAt}` : ''}</p>}
                                        {task.status !== 'Completed' && task.decisionBy && <p className="text-xs text-slate-500">Decision recorded by {task.decisionBy}{task.decisionAt ? ` · ${task.decisionAt}` : ''}</p>}
                                        {onboarding.canDecide && <HistoricalDecisionForm employeeId={employeeId} taskId={task.id} />}
                                    </div>
                                </details>
                            ))}
                        </div>
                    )}
                </SectionCard>
            )}
        </div>
    );
}

function DocumentsPanel({ employeeId, onboarding }: { employeeId: number; onboarding: OnboardingState }) {
    if (!onboarding.ready) {
        return <SectionCard title="Documents"><p className="text-sm text-slate-600">Document storage is not ready on this database.</p></SectionCard>;
    }

    if (!onboarding.canUpload && !onboarding.canReview && onboarding.documents.length === 0) {
        return <SectionCard title="Documents"><p className="text-sm text-slate-600">Document files stay with the employee and with Company Admin or HR.</p></SectionCard>;
    }

    if (!onboarding.started) {
        return <SectionCard title="Documents"><p className="text-sm text-slate-600">Start the onboarding checklist before uploading documents.</p></SectionCard>;
    }

    return (
        <div className="grid gap-4">
            <SectionCard title="Documents">
                {onboarding.documents.length === 0 ? <p className="text-sm text-slate-600">No documents uploaded yet.</p> : (
                    <ul className="grid gap-3">
                        {onboarding.documents.map((document) => (
                            <li key={document.id} className="rounded-lg border border-slate-100 px-3 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="text-sm font-medium text-slate-900">{document.category}</p>
                                        <p className="text-xs text-slate-500">{document.fileName} · {document.uploadedAt}</p>
                                        {document.reviewer && <p className="text-xs text-slate-500">{document.reviewer}{document.reviewedAt ? ` · ${document.reviewedAt}` : ''}</p>}
                                        {document.note && <p className="mt-1 text-sm text-slate-600">{document.note}</p>}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge value={document.status} />
                                        <Button type="button" variant="outline" asChild><Link href={route('hrms.employees.documents.download', [employeeId, document.id])}>Download</Link></Button>
                                    </div>
                                </div>
                                {onboarding.canReview && <ReviewForm employeeId={employeeId} documentId={document.id} />}
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            {onboarding.canUpload && (
                <SectionCard title="Upload a document">
                    <UploadForm employeeId={employeeId} categories={onboarding.categories} />
                </SectionCard>
            )}
        </div>
    );
}

function HistoricalDecisionForm({ employeeId, taskId }: { employeeId: number; taskId: number }) {
    const form = useForm({ outcome: 'pending', note: '', confirm: false as boolean });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ outcome: data.outcome, note: data.note, confirm: data.confirm ? 'RECORD' : '' }));
        form.post(route('hrms.employees.onboarding-tasks.decide', [employeeId, taskId]));
    }

    return (
        <form className="mt-3 grid gap-2" onSubmit={submit}>
            <label className="text-sm text-slate-700" htmlFor={`historical-outcome-${taskId}`}>Outcome
                <select id={`historical-outcome-${taskId}`} className="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={form.data.outcome} onChange={(event) => form.setData('outcome', event.target.value)}>
                    <option value="completed">Completed</option>
                    <option value="not_applicable">Not applicable</option>
                    <option value="pending">Still open</option>
                </select>
            </label>
            <label className="text-sm text-slate-700" htmlFor={`historical-note-${taskId}`}>Decision note
                <textarea id={`historical-note-${taskId}`} className="mt-1 min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} />
            </label>
            {form.errors.outcome && <span className="text-xs text-rose-600">{form.errors.outcome}</span>}
            {form.errors.note && <span className="text-xs text-rose-600">{form.errors.note}</span>}
            {form.errors.confirm && <span className="text-xs text-rose-600">{form.errors.confirm}</span>}
            <label className="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={form.data.confirm} onChange={(event) => form.setData('confirm', event.target.checked)} />
                Confirm this decision with RECORD
            </label>
            <div><Button type="submit" variant="outline" disabled={form.processing}>Save decision</Button></div>
        </form>
    );
}

function UploadForm({ employeeId, categories }: { employeeId: number; categories: Array<{ value: string; label: string }> }) {
    const form = useForm<{ category: string; file: File | null }>({ category: categories[0]?.value ?? 'resume', file: null });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('hrms.employees.documents.store', employeeId), { forceFormData: true });
    }

    return (
        <form className="grid gap-3 sm:grid-cols-2" onSubmit={submit}>
            <Field label="Category" error={form.errors.category}>
                <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>
                    {categories.map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}
                </select>
            </Field>
            <Field label="File" error={form.errors.file}>
                <Input type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
            </Field>
            <div className="sm:col-span-2">
                <Button type="submit" disabled={form.processing}>{form.processing ? 'Uploading…' : 'Upload document'}</Button>
            </div>
        </form>
    );
}

function ReviewForm({ employeeId, documentId }: { employeeId: number; documentId: number }) {
    const form = useForm({ status: 'under_review', note: '', confirm: false as boolean });
    const word = form.data.status === 'verified' ? 'VERIFY' : form.data.status === 'rejected' ? 'REJECT' : 'REVIEW';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ status: data.status, note: data.note, confirm: data.confirm ? word : '' }));
        form.post(route('hrms.employees.documents.review', [employeeId, documentId]));
    }

    return (
        <form className="mt-3 grid gap-2 border-t border-slate-100 pt-3" onSubmit={submit}>
            <div className="grid gap-2 sm:grid-cols-2">
                <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)} aria-label="Review status">
                    <option value="under_review">Under Review</option>
                    <option value="verified">Verified</option>
                    <option value="rejected">Rejected</option>
                </select>
                <Input value={form.data.note} placeholder="Review note" aria-label="Review note" onChange={(event) => form.setData('note', event.target.value)} />
            </div>
            {form.errors.note && <span className="text-xs text-rose-600">{form.errors.note}</span>}
            {form.errors.confirm && <span className="text-xs text-rose-600">{form.errors.confirm}</span>}
            <label className="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={form.data.confirm} onChange={(event) => form.setData('confirm', event.target.checked)} />
                Confirm this review with {word}
            </label>
            <div><Button type="submit" variant="outline" disabled={form.processing}>Save review</Button></div>
        </form>
    );
}
