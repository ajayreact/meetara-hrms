import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

interface TeamLeadOption {
    id: number;
    name: string;
    accessLevel: string;
    leadCount: number;
}

interface AssignProps extends HrmsPageProps {
    ids: number[];
    selectedCount: number;
    assignableCount: number;
    heldCount: number;
    unavailable: number;
    teamLeads: TeamLeadOption[];
    notice?: string | null;
    error?: string | null;
}

export default function LeadAssignPage({
    workspace,
    ids,
    selectedCount,
    assignableCount,
    heldCount,
    unavailable,
    teamLeads,
    notice,
    error,
}: AssignProps) {
    const form = useForm({
        ids,
        team_lead_employee_id: teamLeads[0]?.id ?? 0,
    });
    const canReview = teamLeads.length > 0 && (assignableCount > 0 || heldCount > 0);

    return (
        <HrmsLayout workspace={workspace} title="Assign leads">
            <PageIntro
                title="Assign to a Team Lead"
                subtitle="Choose one active Team Lead. Only unassigned leads are assigned unless you confirm a reassignment on the next screen."
                actions={<Link href="/leads?pool=unassigned" className="text-sm font-medium text-blue-700">Back to unassigned pool</Link>}
            />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <SectionCard title="Selected leads">
                <dl className="mb-4 grid gap-3 sm:grid-cols-3">
                    <Count label="In this company" value={selectedCount} />
                    <Count label="Ready to assign" value={assignableCount} />
                    <Count label="Already with a Team Lead" value={heldCount} />
                </dl>
                {unavailable > 0 && <p className="mb-4 text-sm text-slate-600">{unavailable} selected {unavailable === 1 ? 'lead is' : 'leads are'} outside this company and will not be assigned.</p>}
                {teamLeads.length === 0 ? (
                    <p className="text-sm text-slate-500">No active Team Leads are available. Manager and Company Manager access levels qualify. A job title named Team Lead does not.</p>
                ) : (
                    <form
                        className="grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/leads/assignments/review');
                        }}
                    >
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium text-slate-800">Team Lead</legend>
                            {teamLeads.map((teamLead) => (
                                <label key={teamLead.id} className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                    <input
                                        className="mt-1"
                                        type="radio"
                                        name="team_lead_employee_id"
                                        aria-label={`${teamLead.name}, ${teamLead.leadCount} current leads`}
                                        checked={form.data.team_lead_employee_id === teamLead.id}
                                        onChange={() => form.setData('team_lead_employee_id', teamLead.id)}
                                    />
                                    <span>
                                        <span className="font-medium text-slate-900">{teamLead.name}</span>
                                        <span className="mt-0.5 block text-slate-500">{teamLead.accessLevel} · {teamLead.leadCount} current {teamLead.leadCount === 1 ? 'lead' : 'leads'}</span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                        <div>
                            <Button type="submit" disabled={!canReview || form.processing}>Review assignment</Button>
                        </div>
                    </form>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}

function Count({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl bg-slate-50 px-3 py-2">
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="text-lg font-semibold text-slate-900">{value}</dd>
        </div>
    );
}
