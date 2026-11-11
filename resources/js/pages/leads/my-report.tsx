import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { CalendarClock, CalendarDays, Clock3, Contact, Users } from 'lucide-react';
import { type FormEvent } from 'react';

interface MyReportProps extends HrmsPageProps {
    filters: { from: string; to: string };
    summary: {
        total: number;
        converted: number;
        pending: number;
        overdue: number;
        dueToday: number;
        upcoming: number;
        activities: number;
        latestActivity: string;
    };
    pipeline: { value: string; label: string; count: number }[];
    activityByStatus: { label: string; count: number }[];
}

function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    router.get('/leads/mine/report', { from: String(data.get('from') ?? ''), to: String(data.get('to') ?? '') }, { preserveState: true });
}

export default function MyLeadReportPage({ workspace, filters, summary, pipeline, activityByStatus }: MyReportProps) {
    return (
        <HrmsLayout workspace={workspace} title="My report">
            <PageIntro
                title="My lead report"
                subtitle="Your assigned leads only. Pending means not converted. Activity counts follow-ups you recorded."
                actions={<Link href="/leads/mine" className="text-sm font-medium text-blue-700">Back to my leads</Link>}
            />
            <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={applyFilters}>
                <label className="grid gap-1 text-sm text-slate-600">
                    From
                    <Input type="date" name="from" defaultValue={filters.from} />
                </label>
                <label className="grid gap-1 text-sm text-slate-600">
                    To
                    <Input type="date" name="to" defaultValue={filters.to} />
                </label>
                <Button type="submit">Apply</Button>
                {(filters.from !== '' || filters.to !== '') && <Button type="button" variant="outline" onClick={() => router.get('/leads/mine/report')}>Clear</Button>}
            </form>
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard tone="blue" icon={Users} label="My leads" value={String(summary.total)} />
                <MetricCard tone="green" icon={Contact} label="Converted" value={String(summary.converted)} />
                <MetricCard tone="orange" icon={Clock3} label="Pending" value={String(summary.pending)} />
                <MetricCard tone="orange" icon={CalendarDays} label="Due today" value={String(summary.dueToday)} />
                <MetricCard tone="blue" icon={CalendarClock} label="Upcoming" value={String(summary.upcoming)} />
                <MetricCard tone="red" icon={Clock3} label="Overdue" value={String(summary.overdue)} />
                <MetricCard tone="green" icon={CalendarClock} label="My follow-ups" value={String(summary.activities)} />
            </div>
            <SectionCard title="Pipeline">
                <DataTable>
                    <thead>
                        <tr>
                            <th className={headCell}>Status</th>
                            <th className={headCell}>Leads</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pipeline.map((row) => (
                            <tr key={row.value} className={bodyRow}>
                                <td className={bodyCell}>{row.label}</td>
                                <td className={bodyCell}>{row.count}</td>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>
            </SectionCard>
            <div className="mt-4">
                <SectionCard title="My activity">
                    <p className="mb-3 text-sm text-slate-600">{summary.latestActivity === '' ? 'No follow-ups recorded in this range.' : `Latest follow-up ${summary.latestActivity}.`}</p>
                    {activityByStatus.length === 0 ? (
                        <p className="text-sm text-slate-500">No personal follow-up activity in this range.</p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            {activityByStatus.map((row) => (
                                <li key={row.label}>{row.label}: {row.count}</li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
