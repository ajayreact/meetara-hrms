import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { BriefcaseBusiness, Building2, CalendarDays, FileSpreadsheet, GraduationCap, Hash, Linkedin, Mail, MapPin, Phone, UserRound } from 'lucide-react';
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
    const showFollowUp = canFollowUp && followUpUrl !== '';
    const candidateFacts = [
        { icon: Mail, label: 'Email', value: lead.email || '—' },
        { icon: Phone, label: 'Phone', value: lead.phone || '—' },
        { icon: Linkedin, label: 'LinkedIn', value: lead.linkedin || '—', href: lead.linkedin || null },
        { icon: MapPin, label: 'State', value: lead.state || '—' },
        { icon: Building2, label: 'University', value: lead.university || '—' },
        { icon: GraduationCap, label: 'Graduation term', value: lead.graduationTerm || '—' },
        { icon: BriefcaseBusiness, label: 'Experience', value: lead.experience || '—' },
        { icon: UserRound, label: 'Designations', value: lead.designations || '—' },
        { icon: CalendarDays, label: 'Imported on', value: lead.importedOn || '—' },
        { icon: Hash, label: 'Source row', value: lead.sourceRow === null ? '—' : String(lead.sourceRow) },
    ];
    const placementFacts = [
        { label: 'Pool', value: placement?.label || (lead.unassigned ? 'Unassigned' : 'Assigned') },
        { label: 'Team Lead', value: placement?.teamLeadName || '—' },
        { label: 'Assignee', value: placement?.assigneeName || '—' },
        { label: 'Follow-up date', value: lead.followUpOn || '—' },
        { label: 'Remarks', value: lead.remarks || '—' },
    ];

    return (
        <HrmsLayout workspace={workspace} title={lead.name}>
            <PageIntro
                title={lead.name}
                subtitle={`${placement?.label || (lead.unassigned ? 'Company pool' : 'Assigned')} · ${lead.university || 'University not recorded'}${lead.state ? ` · ${lead.state}` : ''}`}
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

            <div className="grid items-stretch gap-4 xl:grid-cols-12">
                <SectionCard className="flex h-full flex-col xl:col-span-7" title="Candidate" extra={<StatusBadge value={lead.statusLabel} />}>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        {candidateFacts.map((fact) => (
                            <div key={fact.label} className="rounded-xl border border-slate-100 bg-slate-50/70 px-3 py-2.5">
                                <dt className="flex items-center gap-1.5 text-xs text-slate-500">
                                    <fact.icon className="size-3.5 shrink-0 text-slate-400" aria-hidden="true" />
                                    {fact.label}
                                </dt>
                                <dd className="mt-1 break-words text-sm font-medium text-slate-900">
                                    {fact.href ? (
                                        <a href={fact.href.startsWith('http') ? fact.href : `https://${fact.href}`} className="text-blue-700 hover:underline" target="_blank" rel="noreferrer">{fact.value}</a>
                                    ) : fact.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <div className="mt-4 grid gap-2 rounded-xl border border-blue-100 bg-gradient-to-r from-blue-50/90 to-sky-50/60 p-3 sm:grid-cols-3">
                        {placementFacts.slice(0, 3).map((fact) => (
                            <div key={fact.label}>
                                <p className="text-xs text-slate-500">{fact.label}</p>
                                <p className="mt-0.5 text-sm font-semibold text-slate-900">{fact.value}</p>
                            </div>
                        ))}
                    </div>
                    {(placementFacts[3].value !== '—' || placementFacts[4].value !== '—') && (
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {placementFacts.slice(3).map((fact) => (
                                <div key={fact.label} className="rounded-xl border border-slate-100 px-3 py-2.5">
                                    <p className="text-xs text-slate-500">{fact.label}</p>
                                    <p className="mt-0.5 text-sm text-slate-800">{fact.value}</p>
                                </div>
                            ))}
                        </div>
                    )}
                </SectionCard>

                <SectionCard className="flex h-full flex-col xl:col-span-5" title="Import batch" extra={batch ? <StatusBadge value={batch.status} /> : undefined}>
                    {batch === null ? (
                        <div className="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center">
                            <FileSpreadsheet className="mb-2 size-8 text-slate-400" aria-hidden="true" />
                            <p className="text-sm text-slate-500">This lead was not created from an import batch.</p>
                        </div>
                    ) : (
                        <div className="flex flex-1 flex-col gap-3">
                            <div className="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50/80 to-white px-3 py-3">
                                <p className="text-xs text-slate-500">File</p>
                                <p className="mt-1 break-all text-sm font-semibold text-slate-950">{batch.fileName}</p>
                                <p className="mt-1 text-xs text-slate-500">{batch.uploadedBy || 'Unknown uploader'}{batch.uploadedAt ? ` · ${batch.uploadedAt}` : ''}</p>
                            </div>
                            <dl className="grid grid-cols-2 gap-2">
                                {[
                                    ['Rows', String(batch.rowCount)],
                                    ['Created', String(batch.createdCount)],
                                    ['Duplicates', String(batch.duplicateCount)],
                                    ['Invalid', String(batch.invalidCount)],
                                ].map(([label, value]) => (
                                    <div key={label} className="rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2.5">
                                        <dt className="text-xs text-slate-500">{label}</dt>
                                        <dd className="mt-1 text-lg font-semibold text-slate-950">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    )}
                </SectionCard>
            </div>

            {canManage && placement?.section === 'pending' && reports.length > 0 && (
                <form
                    className="mt-4 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post('/my-leads/distribute/review', { ids: [lead.id], employee_id: reportId });
                    }}
                >
                    <label className="grid min-w-56 flex-1 gap-1 text-xs text-slate-500">
                        Direct report
                        <select aria-label="Direct report" className="h-10 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={reportId} onChange={(event) => setReportId(Number(event.target.value))}>
                            {reports.map((report) => <option key={report.id} value={report.id}>{report.name}</option>)}
                        </select>
                    </label>
                    <Button type="submit">Distribute this lead</Button>
                </form>
            )}

            <div className={`mt-4 grid items-stretch gap-4 ${showFollowUp ? 'xl:grid-cols-3' : 'xl:grid-cols-2'}`}>
                {showFollowUp && (
                    <SectionCard className="flex h-full flex-col" title="Follow-up">
                        <form
                            className="flex flex-1 flex-col gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                followUp.post(followUpUrl);
                            }}
                        >
                            <p className="text-sm leading-5 text-slate-500">Records you, the time, status, date and remark. Ownership does not change.</p>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Pipeline status
                                <select aria-label="Pipeline status" className="h-10 w-full rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={followUp.data.pipeline_status} onChange={(event) => followUp.setData('pipeline_status', event.target.value)}>
                                    {statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs text-slate-500">
                                Follow-up date
                                <input aria-label="Follow-up date" type="date" className="h-10 w-full rounded-md border border-slate-200 px-2 text-sm" value={followUp.data.follow_up_on} onChange={(event) => followUp.setData('follow_up_on', event.target.value)} />
                            </label>
                            <label className="grid flex-1 gap-1 text-xs text-slate-500">
                                Remarks
                                <textarea aria-label="Remarks" className="min-h-28 w-full flex-1 rounded-md border border-slate-200 px-2 py-2 text-sm" value={followUp.data.remarks} onChange={(event) => followUp.setData('remarks', event.target.value)} />
                            </label>
                            <Button type="submit" className="mt-auto w-full sm:w-auto" disabled={followUp.processing}>Save follow-up</Button>
                        </form>
                    </SectionCard>
                )}

                <SectionCard className="flex h-full flex-col" title="Assignment history">
                    {assignments.length === 0 ? (
                        <div className="flex flex-1 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-6 text-center">
                            <p className="text-sm text-slate-500">No assignment changes yet.</p>
                        </div>
                    ) : (
                        <ul className="flex flex-1 flex-col gap-2">
                            {assignments.map((record) => (
                                <li key={record.id} className="rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2.5 text-sm">
                                    <p className="font-medium text-slate-900">{record.note || 'Assignment change'}</p>
                                    <p className="mt-1 text-xs leading-5 text-slate-500">
                                        {(record.fromAssignee || 'Unassigned')} → {(record.toAssignee || 'Unassigned')}
                                        {record.actor ? ` · ${record.actor}` : ''}{record.at ? ` · ${record.at}` : ''}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard className="flex h-full flex-col" title="Follow-up history">
                    {followUps.length === 0 ? (
                        <div className="flex flex-1 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-6 text-center">
                            <p className="text-sm text-slate-500">No follow-up notes yet.</p>
                        </div>
                    ) : (
                        <ul className="flex flex-1 flex-col gap-2">
                            {followUps.map((record) => (
                                <li key={record.id} className="rounded-xl border border-slate-100 bg-slate-50/80 px-3 py-2.5 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-medium text-slate-900">{record.body || record.type}</p>
                                        {record.statusLabel && <StatusBadge value={record.statusLabel} />}
                                    </div>
                                    <p className="mt-1 text-xs leading-5 text-slate-500">
                                        {record.followUpOn ? `Call on ${record.followUpOn}` : 'No call date'}
                                        {record.actor ? ` · ${record.actor}` : ''}{record.at ? ` · ${record.at}` : ''}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
