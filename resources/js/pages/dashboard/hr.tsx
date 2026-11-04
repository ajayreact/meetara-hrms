import { OfferApprovals, type OfferApprovalState } from '@/components/hrms/offer-approvals';
import { TimingCard, type TimingState } from '@/components/hrms/timing-card';
import { WorkplacePanels, type WorkplacePayload } from '@/components/hrms/workplace-panels';
import { greetingName, HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { CalendarCheck, UserPlus, Users } from 'lucide-react';
import { Link } from '@inertiajs/react';

interface LeaveQueue {
    id: number;
    employee: string | null;
    type: string | null;
    start: string;
    end: string;
    status: string;
}

export default function HrDashboard({ workspace, timing, companyStats, attendanceRoster, leaveBoard, workplace, offerApprovals = null }: HrmsPageProps & {
    timing?: TimingState;
    offerApprovals?: OfferApprovalState | null;
    workplace?: WorkplacePayload;
    companyStats?: { headcount: number; invited: number };
    attendanceRoster?: { counts: { review: number } } | null;
    leaveBoard?: { queue: LeaveQueue[] };
}) {
    const requests = leaveBoard?.queue ?? [];

    return (
        <HrmsLayout workspace={workspace} title="HR & Assistant Operations">
            <PageIntro title={greetingName(workspace.userName)} subtitle={`${workspace.companyName ?? 'Company'} · headcount, attendance, and leave from company records.`} />
            {timing && <div className="mb-4 max-w-xl"><TimingCard timing={timing} /></div>}
            {offerApprovals && <OfferApprovals approvals={offerApprovals} />}
            <div className="grid gap-3 md:grid-cols-3">
                <MetricCard tone="blue" icon={Users} label="Headcount" value={String(companyStats?.headcount ?? 0)} />
                <MetricCard tone="orange" icon={UserPlus} label="Invited" value={String(companyStats?.invited ?? 0)} />
                <MetricCard tone="red" icon={CalendarCheck} label="Attendance exceptions" value={String(attendanceRoster?.counts.review ?? 0)} />
            </div>
            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_16rem]">
                <SectionCard title="Leave requests" extra={<Link href="/leave" className="text-sm text-blue-600">Open leave</Link>}>
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="bg-blue-50 text-xs text-slate-600">
                                <tr>
                                    <th className="px-3 py-3 font-medium">Request</th>
                                    <th className="px-3 py-3 font-medium">Employee</th>
                                    <th className="px-3 py-3 font-medium">Type</th>
                                    <th className="px-3 py-3 font-medium">Dates</th>
                                    <th className="px-3 py-3 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.length === 0 ? (
                                    <tr><td className="px-3 py-3 text-slate-500" colSpan={5}>No leave requests are waiting.</td></tr>
                                ) : requests.map((request) => (
                                    <tr key={request.id} className="border-t border-slate-100">
                                        <td className="px-3 py-3">{request.id}</td>
                                        <td className="px-3 py-3">{request.employee}</td>
                                        <td className="px-3 py-3">{request.type ?? '—'}</td>
                                        <td className="px-3 py-3">{request.start} – {request.end}</td>
                                        <td className="px-3 py-3"><StatusBadge value={request.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>
                <SectionCard title="In this queue">
                    {requests.length === 0 ? (
                        <p className="text-sm text-slate-500">No leave requests are waiting.</p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {statusCounts(requests).map((item) => (
                                <li key={item.status} className="flex items-center justify-between gap-3">
                                    <StatusBadge value={item.status} />
                                    <span className="font-semibold text-slate-800">{item.count}</span>
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

function statusCounts(requests: LeaveQueue[]): { status: string; count: number }[] {
    const counts = new Map<string, number>();

    for (const request of requests) {
        counts.set(request.status, (counts.get(request.status) ?? 0) + 1);
    }

    return [...counts.entries()].map(([status, count]) => ({ status, count }));
}
