import { greetingName, HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link } from '@inertiajs/react';
import { CircleCheck, Clock3, Receipt } from 'lucide-react';

interface Activity {
    action: string;
    period: string;
    at: string;
}

interface Overview {
    runs: number;
    draft: number;
    calculated: number;
    underReview: number;
    review: number;
    approved: number;
    processed: number;
    outstanding: number;
    processedGross: string;
    processedNet: string;
    employees: number;
    currencyNote: string;
    activity: Activity[];
}

export default function AccountsDashboard({ workspace, payrollBoard }: HrmsPageProps & { payrollBoard?: Overview }) {
    const board = payrollBoard ?? { runs: 0, draft: 0, calculated: 0, underReview: 0, review: 0, approved: 0, processed: 0, outstanding: 0, processedGross: '0.00', processedNet: '0.00', employees: 0, currencyNote: '', activity: [] };

    return (
        <HrmsLayout workspace={workspace} title="Accounts">
            <PageIntro title={greetingName(workspace.userName)} subtitle={board.currencyNote || `${workspace.companyName ?? 'Your company'} · payroll figures come from saved runs.`} />
            {board.runs === 0 ? (
                <SectionCard title="Payroll" extra={<Link href="/payroll/runs" className="text-sm font-medium text-blue-700 hover:underline">Open runs</Link>}>
                    <div className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center">
                        <p className="text-sm font-medium text-slate-800">No payroll runs yet</p>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500">Opening this page does not create a run. A run is opened for one company and month from payroll runs.</p>
                    </div>
                </SectionCard>
            ) : (
                <>
                    <div className="grid gap-3 md:grid-cols-3">
                        <MetricCard tone="blue" icon={Receipt} label="Processed net" value={board.processedNet} />
                        <MetricCard tone="orange" icon={Clock3} label="Awaiting review" value={String(board.review)} />
                        <MetricCard tone="green" icon={CircleCheck} label="Outstanding payments" value={String(board.outstanding)} />
                    </div>
                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <SectionCard title="Payroll" extra={<Link href="/payroll" className="text-sm text-blue-600">Open payroll</Link>}>
                            <ul className="divide-y divide-slate-100 text-sm text-slate-700">
                                <Count label="Draft" value={board.draft} />
                                <Count label="Calculated" value={board.calculated} />
                                <Count label="Under review" value={board.underReview} />
                                <Count label="Approved" value={board.approved} />
                                <Count label="Processed" value={board.processed} />
                                <Count label="Employees in processed runs" value={board.employees} />
                            </ul>
                        </SectionCard>
                        <SectionCard title="Recent activity">
                            {board.activity.length === 0 ? <p className="text-sm text-slate-500">No payroll actions yet.</p> : (
                                <ul className="divide-y divide-slate-100 text-sm text-slate-700">
                                    {board.activity.map((event) => (
                                        <li key={`${event.action}-${event.at}`} className="flex items-start justify-between gap-3 py-2">
                                            <span>{event.action}{event.period ? ` · ${event.period}` : ''}</span>
                                            <time className="shrink-0 text-xs text-slate-500">{event.at}</time>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>
                </>
            )}
        </HrmsLayout>
    );
}

function Count({ label, value }: { label: string; value: number }) {
    const badge = label === 'Under review' ? 'Under Review' : label;
    const known = ['Draft', 'Calculated', 'Under Review', 'Approved', 'Processed'].includes(badge);

    return (
        <li className="flex items-center justify-between gap-3 py-2">
            {known ? <StatusBadge value={badge} /> : <span>{label}</span>}
            <span className="font-semibold">{value}</span>
        </li>
    );
}
