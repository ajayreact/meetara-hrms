import { WorkplacePanels, type WorkplacePayload } from '@/components/hrms/workplace-panels';
import { greetingName, HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { CalendarCheck, Users } from 'lucide-react';
import { Link } from '@inertiajs/react';

interface Roster {
    rows: { name: string; status: string }[];
    counts: { showing: number; present: number };
}

export default function TeamLeadDashboard({ workspace, attendanceRoster, leaveBoard, workplace }: HrmsPageProps & {
    workplace?: WorkplacePayload;
    attendanceRoster?: Roster | null;
    leaveBoard?: { queue: { id: number; employee: string | null; type: string | null; start: string; end: string; status: string }[] };
}) {
    const attendance = attendanceRoster?.rows ?? [];
    const waiting = leaveBoard?.queue ?? [];

    return (
        <HrmsLayout workspace={workspace} title={workspace.roleLabel}>
            <PageIntro title={greetingName(workspace.userName)} subtitle={`${workspace.companyName ?? 'Company'} · people who report to you.`} />
            <div className="grid gap-3 md:grid-cols-2">
                <MetricCard tone="blue" icon={Users} label="Team members" value={String(attendanceRoster?.counts.showing ?? 0)} />
                <MetricCard tone="green" icon={CalendarCheck} label="Clocked in today" value={String(attendanceRoster?.counts.present ?? 0)} />
            </div>
            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <SectionCard title="Team attendance" extra={<Link href="/attendance" className="text-sm text-blue-600">Open</Link>}>
                    {attendance.length === 0 ? (
                        <p className="text-sm text-slate-500">No team attendance is recorded for today.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100 text-sm">
                            {attendance.map((row) => (
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
                            {waiting.map((request) => (
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
        </HrmsLayout>
    );
}
