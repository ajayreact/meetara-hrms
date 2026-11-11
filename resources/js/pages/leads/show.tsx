import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface LeadDetail {
    id: number;
    name: string;
    email: string;
    phone: string;
    linkedin: string;
    state: string;
    university: string;
    graduationTerm: string;
    experience: string;
    designations: string;
    status: string;
    statusLabel: string;
    importedOn: string | null;
    sourceRow: number | null;
    followUpOn: string | null;
    remarks: string;
    unassigned: boolean;
}

interface Placement {
    section: 'unassigned' | 'pending' | 'retained' | 'distributed';
    label: string;
    teamLeadName: string;
    assigneeName: string;
}

interface AssignmentRecord {
    id: number;
    fromTeamLead: string;
    toTeamLead: string;
    fromAssignee: string;
    toAssignee: string;
    note: string;
    actor: string;
    at: string;
}

interface FollowUpRecord {
    id: number;
    type: string;
    statusLabel: string;
    followUpOn: string | null;
    body: string;
    actor: string;
    at: string;
}

interface DirectReport {
    id: number;
    name: string;
}

interface ImportBatch {
    id: number;
    fileName: string;
    status: string;
    rowCount: number;
    createdCount: number;
    duplicateCount: number;
    invalidCount: number;
    uploadedBy: string | null;
    uploadedAt: string | null;
}

export default function LeadShowPage({
    workspace,
    lead,
    batch,
    placement = null,
    assignments = [],
    followUps = [],
    canAssign,
    canManage = false,
    canFollowUp = false,
    followUpUrl = '',
    statuses = [],
    backHref = '/leads',
    reports = [],
    notice,
    error,
}: HrmsPageProps & {
    lead: LeadDetail;
    batch: ImportBatch | null;
    placement?: Placement | null;
    assignments?: AssignmentRecord[];
    followUps?: FollowUpRecord[];
    canAssign: boolean;
    canManage?: boolean;
    canFollowUp?: boolean;
    followUpUrl?: string;
    statuses?: { value: string; label: string }[];
    backHref?: string;
    reports?: DirectReport[];
    notice?: string | null;
    error?: string | null;
}) {
    const assign = useForm({ ids: [lead.id] });
    const followUp = useForm({
        pipeline_status: lead.status,
        follow_up_on: lead.followUpOn ?? '',
        remarks: lead.remarks,
    });
    const [reportId, setReportId] = useState(reports[0]?.id ?? 0);
    const fields: [string, string][] = [
        ['Email', lead.email || '—'],
        ['Phone', lead.phone || '—'],
        ['LinkedIn', lead.linkedin || '—'],
        ['State', lead.state || '—'],
        ['University', lead.university || '—'],
        ['Graduation term', lead.graduationTerm || '—'],
        ['Experience', lead.experience || '—'],
        ['Designations', lead.designations || '—'],
        ['Imported on', lead.importedOn || '—'],
        ['Source row', lead.sourceRow === null ? '—' : String(lead.sourceRow)],
        ['Follow-up date', lead.followUpOn || '—'],
        ['Remarks', lead.remarks || '—'],
        ['Pool', placement?.label || (lead.unassigned ? 'Unassigned' : 'Assigned')],
        ['Team Lead', placement?.teamLeadName || '—'],
        ['Assignee', placement?.assigneeName || '—'],
    ];

    return (
        <HrmsLayout workspace={workspace} title={lead.name}>
            <PageIntro
                title={lead.name}
                subtitle="Assignment and follow-up history stay with this lead."
                actions={(
                    <div className="flex flex-wrap items-center gap-3">
                        {canAssign && lead.unassigned && (
                            <Button type="button" disabled={assign.processing} onClick={() => assign.post('/leads/assignments/prepare')}>Assign to Team Lead</Button>
                        )}
                        {canManage && placement?.section === 'pending' && (
                            <Button type="button" onClick={() => router.post('/my-leads/retain/review', { ids: [lead.id] })}>Retain</Button>
                        )}
                        {canManage && placement?.section === 'distributed' && (
                            <Button type="button" onClick={() => router.post('/my-leads/recall/review', { ids: [lead.id] })}>Take back</Button>
                        )}
                        <Link href={backHref} className="text-sm font-medium text-blue-700">Back to leads</Link>
                    </div>
                )}
            />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.8fr)]">
                <SectionCard title="Candidate" extra={<StatusBadge value={lead.statusLabel} />}>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        {fields.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-xs text-slate-500">{label}</dt>
                                <dd className="text-sm text-slate-900">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </SectionCard>
                <SectionCard title="Import batch">
                    {batch === null ? (
                        <p className="text-sm text-slate-500">This lead was not created from an import batch.</p>
                    ) : (
                        <dl className="grid gap-3">
                            {[
                                ['File', batch.fileName],
                                ['Status', batch.status],
                                ['Uploaded by', batch.uploadedBy || '—'],
                                ['Uploaded at', batch.uploadedAt || '—'],
                                ['Rows', String(batch.rowCount)],
                                ['Created', String(batch.createdCount)],
                                ['Duplicates', String(batch.duplicateCount)],
                                ['Invalid', String(batch.invalidCount)],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-xs text-slate-500">{label}</dt>
                                    <dd className="text-sm text-slate-900">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </SectionCard>
            </div>
            {canManage && placement?.section === 'pending' && reports.length > 0 && (
                <form
                    className="mt-4 flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post('/my-leads/distribute/review', { ids: [lead.id], employee_id: reportId });
                    }}
                >
                    <label className="grid gap-1 text-xs text-slate-500">
                        Direct report
                        <select aria-label="Direct report" className="h-10 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={reportId} onChange={(event) => setReportId(Number(event.target.value))}>
                            {reports.map((report) => <option key={report.id} value={report.id}>{report.name}</option>)}
                        </select>
                    </label>
                    <Button type="submit">Distribute this lead</Button>
                </form>
            )}
            {canFollowUp && followUpUrl !== '' && (
                <form
                    className="mt-4 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        followUp.post(followUpUrl);
                    }}
                >
                    <h2 className="text-sm font-semibold text-slate-900">Follow-up</h2>
                    <p className="text-sm text-slate-500">Saving a follow-up records you, the time, the status, the date and the remark. It does not change who owns the lead.</p>
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="grid gap-1 text-xs text-slate-500">
                            Pipeline status
                            <select aria-label="Pipeline status" className="h-10 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={followUp.data.pipeline_status} onChange={(event) => followUp.setData('pipeline_status', event.target.value)}>
                                {statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs text-slate-500">
                            Follow-up date
                            <input aria-label="Follow-up date" type="date" className="h-10 rounded-md border border-slate-200 px-2 text-sm" value={followUp.data.follow_up_on} onChange={(event) => followUp.setData('follow_up_on', event.target.value)} />
                        </label>
                    </div>
                    <label className="grid gap-1 text-xs text-slate-500">
                        Remarks
                        <textarea aria-label="Remarks" className="min-h-24 rounded-md border border-slate-200 px-2 py-2 text-sm" value={followUp.data.remarks} onChange={(event) => followUp.setData('remarks', event.target.value)} />
                    </label>
                    <div>
                        <Button type="submit" disabled={followUp.processing}>Save follow-up</Button>
                    </div>
                </form>
            )}
            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <SectionCard title="Assignment history">
                    {assignments.length === 0 ? (
                        <p className="text-sm text-slate-500">No assignment changes yet.</p>
                    ) : (
                        <ul className="grid gap-3">
                            {assignments.map((record) => (
                                <li key={record.id} className="text-sm text-slate-700">
                                    <span className="font-medium text-slate-900">{record.note || 'Assignment change'}</span>
                                    <span className="mt-1 block text-slate-500">
                                        {(record.fromAssignee || 'Unassigned')} to {(record.toAssignee || 'Unassigned')}
                                        {record.actor ? ` · ${record.actor}` : ''}{record.at ? ` · ${record.at}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
                <SectionCard title="Follow-up history">
                    {followUps.length === 0 ? (
                        <p className="text-sm text-slate-500">No follow-up notes yet.</p>
                    ) : (
                        <ul className="grid gap-3">
                            {followUps.map((record) => (
                                <li key={record.id} className="text-sm text-slate-700">
                                    <span className="font-medium text-slate-900">{record.body || record.type}</span>
                                    <span className="mt-1 block text-slate-500">
                                        {record.statusLabel || 'Note'}{record.followUpOn ? ` · ${record.followUpOn}` : ''}{record.actor ? ` · ${record.actor}` : ''}{record.at ? ` · ${record.at}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
