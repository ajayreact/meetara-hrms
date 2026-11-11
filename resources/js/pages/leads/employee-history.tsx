import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link } from '@inertiajs/react';

interface HistoryLead {
    id: number;
    name: string;
    statusLabel: string;
    followUpOn: string | null;
    timing: string;
}

interface HistoryItem {
    id: number;
    leadId: number;
    leadName: string;
    body: string;
    statusLabel: string;
    followUpOn: string | null;
    actor: string;
    at: string;
}

interface HistoryProps extends HrmsPageProps {
    employee: { id: number; name: string };
    leads: HistoryLead[];
    followUps: HistoryItem[];
    notice?: string | null;
    error?: string | null;
}

export default function EmployeeFollowUpHistoryPage({ workspace, employee, leads, followUps, notice, error }: HistoryProps) {
    return (
        <HrmsLayout workspace={workspace} title={employee.name}>
            <PageIntro title={employee.name} subtitle="Leads distributed to this employee and their follow-up history." actions={<Link href="/my-leads" className="text-sm font-medium text-blue-700">Back to my leads</Link>} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="grid gap-4 xl:grid-cols-2">
                <SectionCard title="Leads">
                    {leads.length === 0 ? (
                        <p className="text-sm text-slate-500">No leads are assigned to this employee.</p>
                    ) : (
                        <ul className="grid gap-3">
                            {leads.map((lead) => (
                                <li key={lead.id} className="text-sm">
                                    <Link href={`/my-leads/${lead.id}`} className="font-medium text-blue-700 hover:underline">{lead.name}</Link>
                                    <div className="mt-1 flex flex-wrap items-center gap-2 text-slate-500">
                                        <StatusBadge value={lead.statusLabel} />
                                        <span>{lead.followUpOn || 'No follow-up date'}{lead.timing === 'overdue' ? ' · Overdue' : lead.timing === 'today' ? ' · Due today' : ''}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
                <SectionCard title="Follow-up history">
                    {followUps.length === 0 ? (
                        <p className="text-sm text-slate-500">No follow-up notes yet.</p>
                    ) : (
                        <ul className="grid gap-3 text-sm">
                            {followUps.map((item) => (
                                <li key={item.id}>
                                    <Link href={`/my-leads/${item.leadId}`} className="font-medium text-blue-700 hover:underline">{item.leadName}</Link>
                                    <span className="text-slate-600"> · {item.statusLabel}{item.followUpOn ? ` · ${item.followUpOn}` : ''}</span>
                                    <span className="mt-1 block text-slate-500">{item.body || 'Follow-up'}{item.actor ? ` · ${item.actor}` : ''}{item.at ? ` · ${item.at}` : ''}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
