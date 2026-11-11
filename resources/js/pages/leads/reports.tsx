import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { MetricCard } from '@/components/hrms/metric-card';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';
import { router } from '@inertiajs/react';
import { CalendarClock, CalendarDays, Clock3, Contact, UserRound, Users } from 'lucide-react';
import { type FormEvent } from 'react';

interface Filters {
    from: string;
    to: string;
}

interface CountRow {
    label: string;
    total: number;
    converted: number;
}

interface TeamLeadRow {
    id: number;
    name: string;
    total: number;
    undistributed: number;
    retained: number;
    distributed: number;
    converted: number;
    conversionRate: number;
    overdue: number;
    dueToday: number;
    activities: number;
}

interface EmployeeRow {
    id: number;
    name: string;
    teamLead: string;
    leads: number;
    converted: number;
    conversionRate: number;
    overdue: number;
    dueToday: number;
    activities: number;
}

interface ReportsProps extends HrmsPageProps {
    filters: Filters;
    summary: {
        total: number;
        unassigned: number;
        assignedToTeamLeads: number;
        assignedToEmployees: number;
        overdue: number;
        dueToday: number;
        activities: number;
    };
    pipeline: { value: string; label: string; count: number }[];
    teamLeads: TeamLeadRow[];
    employees: EmployeeRow[];
    states: CountRow[];
    universities: CountRow[];
    graduationTerms: CountRow[];
    designations: CountRow[];
}

function queryString(filters: Filters): string {
    const query = new URLSearchParams();

    if (filters.from !== '') {
        query.set('from', filters.from);
    }

    if (filters.to !== '') {
        query.set('to', filters.to);
    }

    const text = query.toString();

    return text === '' ? '' : `?${text}`;
}

function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    router.get('/leads/reports', { from: String(data.get('from') ?? ''), to: String(data.get('to') ?? '') }, { preserveState: true });
}

function Breakdown({ title, rows }: { title: string; rows: CountRow[] }) {
    return (
        <SectionCard title={title}>
            {rows.length === 0 ? (
                <p className="text-sm text-slate-500">No leads in this range.</p>
            ) : (
                <DataTable>
                    <thead>
                        <tr>
                            <th className={headCell}>{title}</th>
                            <th className={headCell}>Leads</th>
                            <th className={headCell}>Converted</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.label} className={bodyRow}>
                                <td className={bodyCell}>{row.label}</td>
                                <td className={bodyCell}>{row.total}</td>
                                <td className={bodyCell}>{row.converted}</td>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>
            )}
        </SectionCard>
    );
}

export default function LeadReportsPage({ workspace, filters, summary, pipeline, teamLeads, employees, states, universities, graduationTerms, designations }: ReportsProps) {
    return (
        <HrmsLayout workspace={workspace} title="Lead reports">
            <PageIntro
                title="Lead reports"
                subtitle="Company totals use the date a lead was added. Follow-up activity uses the date it was recorded. The CSV lists the leads added in this range."
                actions={<Button asChild variant="outline"><a href={`/leads/reports/export${queryString(filters)}`}>Export CSV</a></Button>}
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
                {(filters.from !== '' || filters.to !== '') && <Button type="button" variant="outline" onClick={() => router.get('/leads/reports')}>Clear</Button>}
            </form>
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard tone="blue" icon={Users} label="Total leads" value={String(summary.total)} />
                <MetricCard tone="orange" icon={UserRound} label="Unassigned" value={String(summary.unassigned)} />
                <MetricCard tone="green" icon={Contact} label="Assigned to Team Leads" value={String(summary.assignedToTeamLeads)} />
                <MetricCard tone="blue" icon={Users} label="Assigned to employees" value={String(summary.assignedToEmployees)} />
                <MetricCard tone="orange" icon={CalendarDays} label="Due today" value={String(summary.dueToday)} />
                <MetricCard tone="red" icon={Clock3} label="Overdue" value={String(summary.overdue)} />
                <MetricCard tone="green" icon={CalendarClock} label="Follow-up activities" value={String(summary.activities)} />
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
                <SectionCard title="Team Lead performance">
                    {teamLeads.length === 0 ? (
                        <p className="text-sm text-slate-500">No leads have a Team Lead in this range.</p>
                    ) : (
                        <DataTable>
                            <thead>
                                <tr>
                                    <th className={headCell}>Team Lead</th>
                                    <th className={headCell}>Leads</th>
                                    <th className={headCell}>Not distributed</th>
                                    <th className={headCell}>Retained</th>
                                    <th className={headCell}>With employees</th>
                                    <th className={headCell}>Converted</th>
                                    <th className={headCell}>Due today</th>
                                    <th className={headCell}>Overdue</th>
                                    <th className={headCell}>Follow-ups</th>
                                </tr>
                            </thead>
                            <tbody>
                                {teamLeads.map((row) => (
                                    <tr key={row.id} className={bodyRow}>
                                        <td className={bodyCell}>{row.name}</td>
                                        <td className={bodyCell}>{row.total}</td>
                                        <td className={bodyCell}>{row.undistributed}</td>
                                        <td className={bodyCell}>{row.retained}</td>
                                        <td className={bodyCell}>{row.distributed}</td>
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
            </div>
            <div className="mt-4">
                <SectionCard title="Employee performance">
                    {employees.length === 0 ? (
                        <p className="text-sm text-slate-500">No leads are held by an employee in this range.</p>
                    ) : (
                        <DataTable>
                            <thead>
                                <tr>
                                    <th className={headCell}>Employee</th>
                                    <th className={headCell}>Team Lead</th>
                                    <th className={headCell}>Leads</th>
                                    <th className={headCell}>Converted</th>
                                    <th className={headCell}>Due today</th>
                                    <th className={headCell}>Overdue</th>
                                    <th className={headCell}>Follow-ups</th>
                                </tr>
                            </thead>
                            <tbody>
                                {employees.map((row) => (
                                    <tr key={`${row.id}-${row.teamLead}`} className={bodyRow}>
                                        <td className={bodyCell}>{row.name}</td>
                                        <td className={bodyCell}>{row.teamLead || '—'}</td>
                                        <td className={bodyCell}>{row.leads}</td>
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
            </div>
            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <Breakdown title="State" rows={states} />
                <Breakdown title="University" rows={universities} />
                <Breakdown title="Graduation term" rows={graduationTerms} />
                <Breakdown title="Designation" rows={designations} />
            </div>
        </HrmsLayout>
    );
}
