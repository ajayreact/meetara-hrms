import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router } from '@inertiajs/react';
import { CalendarClock, CalendarDays, Clock3, Contact, UserRound, Users } from 'lucide-react';
import { type FormEvent } from 'react';

interface EmployeeRow {
    id: number;
    name: string;
    leads: number;
    statuses: { label: string; count: number }[];
    converted: number;
    conversionRate: number;
    overdue: number;
    dueToday: number;
    activities: number;
}

interface TeamReportProps extends HrmsPageProps {
    filters: { from: string; to: string };
    summary: {
        total: number;
        retained: number;
        distributed: number;
        undistributed: number;
        converted: number;
        overdue: number;
        dueToday: number;
        activities: number;
    };
    employees: EmployeeRow[];
}

function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    router.get('/my-leads/reports', { from: String(data.get('from') ?? ''), to: String(data.get('to') ?? '') }, { preserveState: true });
}

export default function TeamLeadReportPage({ workspace, filters, summary, employees }: TeamReportProps) {
    return (
        <HrmsLayout workspace={workspace} title="Lead reports">
            <PageIntro
                title="Team lead report"
                subtitle="Totals cover only your team. Lead counts use the date a lead was added. Follow-up activity uses the date it was recorded."
                actions={<Link href="/my-leads" className="text-sm font-medium text-blue-700">Back to my leads</Link>}
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
                {(filters.from !== '' || filters.to !== '') && <Button type="button" variant="outline" onClick={() => router.get('/my-leads/reports')}>Clear</Button>}
            </form>
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard tone="blue" icon={Users} label="Team leads" value={String(summary.total)} />
                <MetricCard tone="blue" icon={UserRound} label="Retained" value={String(summary.retained)} />
                <MetricCard tone="green" icon={Contact} label="Distributed" value={String(summary.distributed)} />
                <MetricCard tone="green" icon={Contact} label="Converted" value={String(summary.converted)} />
                <MetricCard tone="orange" icon={CalendarDays} label="Due today" value={String(summary.dueToday)} />
                <MetricCard tone="red" icon={Clock3} label="Overdue" value={String(summary.overdue)} />
                <MetricCard tone="green" icon={CalendarClock} label="Follow-up activities" value={String(summary.activities)} />
            </div>
            <SectionCard title="Employees">
                {employees.length === 0 ? (
                    <p className="text-sm text-slate-500">No employees report to you.</p>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <th className={headCell}>Employee</th>
                                <th className={headCell}>Leads</th>
                                <th className={headCell}>Status</th>
                                <th className={headCell}>Converted</th>
                                <th className={headCell}>Due today</th>
                                <th className={headCell}>Overdue</th>
                                <th className={headCell}>Follow-ups</th>
                            </tr>
                        </thead>
                        <tbody>
                            {employees.map((row) => (
                                <tr key={row.id} className={bodyRow}>
                                    <td className={bodyCell}><Link href={`/my-leads/reports/${row.id}`} className="font-medium text-blue-700 hover:underline">{row.name}</Link></td>
                                    <td className={bodyCell}>{row.leads}</td>
                                    <td className={bodyCell}>{row.statuses.length === 0 ? '—' : row.statuses.map((status) => `${status.label} ${status.count}`).join(', ')}</td>
                                    <td className={bodyCell}>{row.converted} ({row.conversionRate}%)</td>
                                    <td className={bodyCell}>{row.dueToday}</td>
                                    <td className={bodyCell}>{row.overdue}</td>
                                    <td className={bodyCell}>{row.activities}</td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}
