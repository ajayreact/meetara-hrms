import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { WorkplacePanels, type WorkplacePayload } from '@/components/hrms/workplace-panels';
import { LeadFollowUpDialog, phoneLabel, reminderLabel, reminderTone, type LeadFollowUpTarget } from '@/components/hrms/lead-follow-up-dialog';
import { greetingName, HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { CalendarCheck, Contact, UserRound, Users } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

interface Roster {
    rows: { name: string; status: string }[];
    counts: { showing: number; present: number };
}

interface LeadBoardLead {
    id: number;
    name: string;
    phone: string;
    status: string;
    statusLabel: string;
    followUpOn: string | null;
    remarks: string;
    reminder: 'none' | 'due_today' | 'overdue' | 'upcoming';
    canFollowUp: boolean;
}

interface LeadBoard {
    myTotal: number;
    teamTotal: number;
    pending: number;
    distributed: number;
    myPipeline: { value: string; label: string; count: number }[];
    myLeads: LeadBoardLead[];
    employees: { id: number; name: string; active: boolean; total: number; statuses: { label: string; count: number }[] }[];
    statuses: { value: string; label: string }[];
}

export default function TeamLeadDashboard({ workspace, timing, attendanceRoster, leaveBoard, workplace, leadBoard = null }: HrmsPageProps & {
    timing?: TimingState;
    workplace?: WorkplacePayload;
    attendanceRoster?: Roster | null;
    leaveBoard?: { queue: { id: number; employee: string | null; type: string | null; start: string; end: string; status: string }[] };
    leadBoard?: LeadBoard | null;
}) {
    const attendance = attendanceRoster?.rows ?? [];
    const waiting = leaveBoard?.queue ?? [];
    const board = leadBoard ?? { myTotal: 0, teamTotal: 0, pending: 0, distributed: 0, myPipeline: [], myLeads: [], employees: [], statuses: [] };
    const [editing, setEditing] = useState<LeadFollowUpTarget | null>(null);

    return (
        <HrmsLayout workspace={workspace} title={workspace.roleLabel}>
            <PageIntro title={greetingName(workspace.userName)} subtitle={`${workspace.companyName ?? 'Company'} · your retained leads and the people who report to you.`} />
            <div className={`mb-4 grid items-stretch gap-4 ${timing ? 'xl:grid-cols-12' : ''}`}>
                {timing && (
                    <div className="xl:col-span-5">
                        <TimingCard className="h-full" timing={timing} />
                    </div>
                )}
                <div className={`min-w-0 ${timing ? 'xl:col-span-7' : ''}`}>
                    <div className={`grid gap-3 sm:grid-cols-2 ${timing ? '' : 'xl:grid-cols-4'}`}>
                        <MetricCard tone="green" icon={Users} label="Total Leads" value={String(board.teamTotal)} hint="All leads assigned to you" href="/my-leads?section=total" action="Open total" />
                        <MetricCard tone="orange" icon={Contact} label="Pool" value={String(board.pending)} hint="Not yet distributed" href="/my-leads" action="Open pool" />
                        <MetricCard tone="blue" icon={UserRound} label="My Leads" value={String(board.myTotal)} hint="Assigned to you" href="/my-leads?section=retained" action="Open my leads" />
                        <MetricCard tone="blue" icon={CalendarCheck} label="Distributed" value={String(board.distributed)} hint="With employees" href="/my-leads?section=distributed" action="View distributed" />
                    </div>
                </div>
            </div>

            <div className="mb-4 grid items-stretch gap-4 xl:grid-cols-12">
                <SectionCard className="flex h-full flex-col xl:col-span-7" title="My Leads" extra={<Link href="/my-leads?section=retained" className="text-sm font-medium text-blue-700">Open all</Link>}>
                    {board.myPipeline.some((row) => row.count > 0) && (
                        <div className="mb-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            {board.myPipeline.filter((row) => row.count > 0).map((row) => (
                                <div key={row.value} className="flex items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2">
                                    <StatusBadge value={row.label} />
                                    <span className="text-sm font-semibold tabular-nums text-slate-950">{row.count}</span>
                                </div>
                            ))}
                        </div>
                    )}
                    {board.myLeads.length === 0 ? (
                        <div className="flex flex-1 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-8 text-center">
                            <p className="text-sm text-slate-500">No leads retained by you yet. Retain from Not yet distributed, or Update a pending lead to keep it.</p>
                        </div>
                    ) : (
                        <ul className="flex flex-1 flex-col gap-2">
                            {board.myLeads.map((lead) => (
                                <li key={lead.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2.5 text-sm">
                                    <div className="min-w-0">
                                        <Link href={`/my-leads/${lead.id}`} className="font-medium text-blue-700 hover:underline">{lead.name}</Link>
                                        <p className="mt-0.5 text-xs text-slate-500">{phoneLabel(lead.phone)} · <span className={reminderTone(lead.reminder)}>{reminderLabel(lead.reminder, lead.followUpOn)}</span></p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge value={lead.statusLabel} />
                                        {lead.canFollowUp && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setEditing({
                                                    id: lead.id,
                                                    name: lead.name,
                                                    status: lead.status,
                                                    followUpOn: lead.followUpOn,
                                                    remarks: lead.remarks,
                                                })}
                                            >
                                                Update
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard className="flex h-full flex-col xl:col-span-5" title="Distributed to Employees" extra={<Link href="/my-leads?section=distributed" className="text-sm font-medium text-blue-700">Open</Link>}>
                    {board.employees.length === 0 ? (
                        <div className="flex flex-1 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-8 text-center">
                            <p className="text-sm text-slate-500">No employees report to you yet.</p>
                        </div>
                    ) : (
                        <ul className="flex flex-1 flex-col gap-2">
                            {board.employees.map((employee) => (
                                <li key={employee.id} className="rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2.5 text-sm">
                                    <div className="flex items-center justify-between gap-2">
                                        <Link href={`/my-leads/reports/${employee.id}`} className="font-medium text-blue-700 hover:underline">{employee.name}</Link>
                                        <span className="tabular-nums font-semibold text-slate-950">{employee.total}</span>
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">
                                        {employee.active ? 'Active' : 'Not active'}
                                        {employee.statuses.length > 0 ? ` · ${employee.statuses.map((status) => `${status.label} ${status.count}`).join(' · ')}` : ' · No distributed leads'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <div className="mb-4 grid gap-3 md:grid-cols-2">
                <MetricCard tone="blue" icon={Users} label="Team members" value={String(attendanceRoster?.counts.showing ?? 0)} />
                <MetricCard tone="green" icon={CalendarCheck} label="Clocked in today" value={String(attendanceRoster?.counts.present ?? 0)} />
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <SectionCard title="Team attendance" extra={<Link href="/attendance" className="text-sm text-blue-600">Open</Link>}>
                    {attendance.length === 0 ? (
                        <p className="text-sm text-slate-500">No team attendance is recorded for today.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100 text-sm">
                            {attendance.slice(0, 6).map((row) => (
                                <li key={row.name} className="flex items-center justify-between py-2">
                                    <span>{row.name}</span>
                                    <StatusBadge value={row.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
                <SectionCard title="Leave waiting on you" extra={<Link href="/leave" className="text-sm text-blue-600">Review</Link>}>
                    {waiting.length === 0 ? (
                        <p className="text-sm text-slate-500">No leave requests are waiting for your review.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100 text-sm">
                            {waiting.slice(0, 6).map((request) => (
                                <li key={request.id} className="flex items-start justify-between gap-3 py-2">
                                    <span>
                                        <span className="block font-medium">{request.employee ?? 'Employee'}</span>
                                        <span className="text-xs text-slate-500">{request.type ?? 'Leave'} · {request.start} – {request.end}</span>
                                    </span>
                                    <StatusBadge value={request.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
            <WorkplacePanels workplace={workplace} />
            <LeadFollowUpDialog
                open={editing !== null}
                lead={editing}
                statuses={board.statuses}
                url={editing ? `/my-leads/${editing.id}/follow-ups` : ''}
                onClose={() => setEditing(null)}
            />
        </HrmsLayout>
    );
}
