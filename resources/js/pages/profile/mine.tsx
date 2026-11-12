import { HrmsLayout, initials, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { BadgeCheck, Briefcase, Building2, CalendarDays, Globe, Hash, Mail, MapPin, Users, type LucideIcon } from 'lucide-react';

interface OwnEmployee {
    code: string;
    department: string | null;
    status: string;
    joined: string | null;
}

interface ProfileCard {
    personal: { name: string; email: string; phone: string | null; address: string | null; dateOfBirth: string | null };
    employment: { code: string; designation: string | null; department: string | null; joined: string | null; status: string; manager: string | null; office: string | null };
    payroll: { bank: string | null; note: string };
}

interface ProfilePageProps extends HrmsPageProps {
    employee?: OwnEmployee | null;
    profileCard?: ProfileCard | null;
}

function displayDate(value: string | null | undefined): string {
    if (!value) {
        return 'Not recorded';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    if (!match) {
        return value;
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function DetailRow({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1 border-b border-slate-100 py-2.5 last:border-b-0 sm:grid-cols-[minmax(8.5rem,0.9fr)_minmax(0,1.1fr)] sm:items-center sm:gap-4">
            <dt className="flex items-center gap-2 text-sm text-slate-500">
                <Icon className="size-4 shrink-0 text-slate-400" aria-hidden="true" />
                {label}
            </dt>
            <dd className="text-sm font-medium break-words text-slate-950 sm:text-right">{children}</dd>
        </div>
    );
}

export default function MyProfilePage({ workspace, employee = null, profileCard = null }: ProfilePageProps) {
    const code = employee?.code ?? 'Not linked';
    const department = employee?.department ?? 'Not assigned';
    const office = workspace.officeName ?? 'Not assigned';
    const status = employee?.status ?? 'Not linked';
    const company = workspace.companyName ?? 'Platform';

    return (
        <HrmsLayout workspace={workspace} title="My Profile">
            <PageIntro title="My Profile" subtitle="View your employment information." />
            <section className="relative mb-4 overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-sm">
                <div className="pointer-events-none absolute inset-y-0 right-0 w-2/5 bg-gradient-to-l from-sky-100/80 to-transparent" aria-hidden="true" />
                <div className="relative flex flex-wrap items-center gap-4 p-4 sm:p-5">
                    <span aria-hidden="true" className="flex size-16 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xl font-semibold text-white shadow-sm sm:size-20 sm:text-2xl">
                        {initials(workspace.userName)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-xl font-semibold tracking-tight text-slate-950">{workspace.userName}</h2>
                        <p className="mt-0.5 text-sm text-slate-600">
                            {workspace.designation}
                            {employee?.code ? ` · ${employee.code}` : ''}
                        </p>
                        <p className="mt-1 flex min-w-0 items-center gap-1.5 text-sm text-slate-500">
                            <Mail className="size-3.5 shrink-0" aria-hidden="true" />
                            <span className="truncate">{workspace.email}</span>
                        </p>
                        <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span className="inline-flex items-center gap-1">
                                <Briefcase className="size-3.5" aria-hidden="true" />
                                {department}
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <Building2 className="size-3.5" aria-hidden="true" />
                                {office}
                            </span>
                        </p>
                        {!employee && <p className="mt-2 text-sm text-slate-500">No employee record is linked to this login.</p>}
                    </div>
                    <StatusBadge value={status} />
                </div>
            </section>
            {profileCard ? (
                <div className="grid items-stretch gap-4 lg:grid-cols-2">
                    <SectionCard className="flex h-full flex-col" title="Personal information">
                        <dl className="flex-1">
                            <DetailRow icon={Users} label="Full name">{profileCard.personal.name}</DetailRow>
                            <DetailRow icon={Mail} label="Email">{profileCard.personal.email}</DetailRow>
                            <DetailRow icon={Hash} label="Phone">{profileCard.personal.phone || 'Not recorded'}</DetailRow>
                            <DetailRow icon={MapPin} label="Address">{profileCard.personal.address || 'Not recorded'}</DetailRow>
                            <DetailRow icon={CalendarDays} label="Date of birth">{displayDate(profileCard.personal.dateOfBirth)}</DetailRow>
                        </dl>
                    </SectionCard>
                    <SectionCard className="flex h-full flex-col" title="Employment information">
                        <dl className="flex-1">
                            <DetailRow icon={Hash} label="Employee ID">{profileCard.employment.code}</DetailRow>
                            <DetailRow icon={Briefcase} label="Designation">{profileCard.employment.designation || 'Not assigned'}</DetailRow>
                            <DetailRow icon={Users} label="Department">{profileCard.employment.department || 'Not assigned'}</DetailRow>
                            <DetailRow icon={CalendarDays} label="Joining date">{displayDate(profileCard.employment.joined)}</DetailRow>
                            <DetailRow icon={BadgeCheck} label="Employment status"><StatusBadge value={profileCard.employment.status} /></DetailRow>
                            <DetailRow icon={Users} label="Reporting manager">{profileCard.employment.manager || 'Not assigned'}</DetailRow>
                            <DetailRow icon={Building2} label="Office">{profileCard.employment.office || 'Not assigned'}</DetailRow>
                        </dl>
                    </SectionCard>
                    <SectionCard className="flex h-full flex-col" title="Bank and payroll information">
                        <div className="flex flex-1 flex-col justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-8 text-center">
                            <p className="text-sm leading-6 text-slate-600">{profileCard.payroll.bank ? `Account ending ${profileCard.payroll.bank}` : profileCard.payroll.note}</p>
                        </div>
                    </SectionCard>
                    <SectionCard className="flex h-full flex-col" title="Organization and work location">
                        <dl className="flex-1">
                            <DetailRow icon={Building2} label="Company">{company}</DetailRow>
                            <DetailRow icon={MapPin} label="Office">{office}</DetailRow>
                            <DetailRow icon={Globe} label="Holiday region">{workspace.officeRegion ?? 'Not assigned'}</DetailRow>
                            <DetailRow icon={Mail} label="Work email">{workspace.email}</DetailRow>
                        </dl>
                    </SectionCard>
                </div>
            ) : (
                <div className="grid items-stretch gap-4 lg:grid-cols-2">
                    <SectionCard className="flex h-full flex-col" title="Employment details">
                        <dl className="flex-1">
                            <DetailRow icon={Hash} label="Employee ID">{code}</DetailRow>
                            <DetailRow icon={Briefcase} label="Designation">{workspace.designation}</DetailRow>
                            <DetailRow icon={Users} label="Department">{department}</DetailRow>
                            <DetailRow icon={CalendarDays} label="Joining date">{displayDate(employee?.joined)}</DetailRow>
                            <DetailRow icon={BadgeCheck} label="Employment status"><StatusBadge value={status} /></DetailRow>
                        </dl>
                    </SectionCard>
                    <SectionCard className="flex h-full flex-col" title="Organization and work location">
                        <dl className="flex-1">
                            <DetailRow icon={Building2} label="Company">{company}</DetailRow>
                            <DetailRow icon={MapPin} label="Office">{office}</DetailRow>
                            <DetailRow icon={Globe} label="Holiday region">{workspace.officeRegion ?? 'Not assigned'}</DetailRow>
                            <DetailRow icon={Mail} label="Work email">{workspace.email}</DetailRow>
                        </dl>
                    </SectionCard>
                </div>
            )}
        </HrmsLayout>
    );
}
