import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

interface TeamLeadChoice {
    id: number;
    name: string;
    accessLevel: string;
    leadCount: number;
}

interface ConfirmProps extends HrmsPageProps {
    ids: number[];
    count: number;
    heldByOthers: number;
    alreadyWithTeamLead: number;
    unavailable: number;
    teamLead: TeamLeadChoice;
    reassignAvailable: boolean;
    notice?: string | null;
    error?: string | null;
}

export default function LeadAssignConfirmPage({
    workspace,
    ids,
    count,
    heldByOthers,
    alreadyWithTeamLead,
    unavailable,
    teamLead,
    reassignAvailable,
    notice,
    error,
}: ConfirmProps) {
    const form = useForm({
        ids,
        team_lead_employee_id: teamLead.id,
        reassign: false,
    });
    const total = count + (form.data.reassign ? heldByOthers : 0);

    return (
        <HrmsLayout workspace={workspace} title="Confirm assignment">
            <PageIntro
                title="Confirm assignment"
                subtitle="Nothing is saved until you confirm. Employees are not assigned in this step."
                actions={<Link href="/leads?pool=unassigned" className="text-sm font-medium text-blue-700">Back to unassigned pool</Link>}
            />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <SectionCard title="Assignment">
                <p className="text-sm text-slate-800">
                    Assign <span className="font-semibold">{total}</span> {total === 1 ? 'lead' : 'leads'} to <span className="font-semibold">{teamLead.name}</span>.
                </p>
                <p className="mt-2 text-sm text-slate-600">
                    {teamLead.name} is a {teamLead.accessLevel} and currently has {teamLead.leadCount} {teamLead.leadCount === 1 ? 'lead' : 'leads'}.
                </p>
                <ul className="mt-4 grid gap-1 text-sm text-slate-600">
                    <li>{count} from the unassigned pool.</li>
                    {alreadyWithTeamLead > 0 && <li>{alreadyWithTeamLead} already assigned to this Team Lead and will be left unchanged.</li>}
                    {heldByOthers > 0 && <li>{heldByOthers} already assigned to another Team Lead.</li>}
                    {unavailable > 0 && <li>{unavailable} outside this company and will not be assigned.</li>}
                </ul>
                {reassignAvailable && (
                    <label className="mt-4 flex items-start gap-2 text-sm text-slate-800">
                        <input
                            className="mt-1"
                            type="checkbox"
                            checked={form.data.reassign}
                            onChange={(event) => form.setData('reassign', event.target.checked)}
                        />
                        <span>Reassign leads that already belong to another Team Lead. Their employee assignee is cleared.</span>
                    </label>
                )}
                <form
                    className="mt-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/leads/assignments');
                    }}
                >
                    <Button type="submit" disabled={form.processing || total === 0}>Confirm assignment</Button>
                </form>
            </SectionCard>
        </HrmsLayout>
    );
}
