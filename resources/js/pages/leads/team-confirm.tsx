import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

interface ConfirmProps extends HrmsPageProps {
    action: 'retain' | 'distribute' | 'recall';
    ids: number[];
    count: number;
    employee: { id: number; name: string } | null;
    skipped: number;
    notice?: string | null;
    error?: string | null;
}

const copy: Record<ConfirmProps['action'], { title: string; path: string; verb: string }> = {
    retain: { title: 'Confirm retain', path: '/my-leads/retain', verb: 'Retain' },
    distribute: { title: 'Confirm distribution', path: '/my-leads/distribute', verb: 'Distribute' },
    recall: { title: 'Confirm take back', path: '/my-leads/recall', verb: 'Take back' },
};

export default function TeamConfirmPage({ workspace, action, ids, count, employee, skipped, notice, error }: ConfirmProps) {
    const form = useForm({
        ids,
        employee_id: employee?.id ?? undefined,
    });
    const details = copy[action];

    return (
        <HrmsLayout workspace={workspace} title={details.title}>
            <PageIntro title={details.title} subtitle="Nothing is saved until you confirm." actions={<Link href="/my-leads" className="text-sm font-medium text-blue-700">Back to my leads</Link>} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <SectionCard title="Selected leads">
                {action === 'retain' && <p className="text-sm text-slate-800">Retain <span className="font-semibold">{count}</span> {count === 1 ? 'lead' : 'leads'} on your own list.</p>}
                {action === 'distribute' && <p className="text-sm text-slate-800">Distribute <span className="font-semibold">{count}</span> {count === 1 ? 'lead' : 'leads'} to <span className="font-semibold">{employee?.name}</span>.</p>}
                {action === 'recall' && <p className="text-sm text-slate-800">Take <span className="font-semibold">{count}</span> {count === 1 ? 'lead' : 'leads'} back onto your retained list.</p>}
                {skipped > 0 && <p className="mt-2 text-sm text-slate-600">{skipped} selected {skipped === 1 ? 'lead is' : 'leads are'} outside this action and will be left unchanged.</p>}
                <form className="mt-4" onSubmit={(event) => { event.preventDefault(); form.post(details.path); }}>
                    <Button type="submit" disabled={form.processing || count === 0}>{details.verb}</Button>
                </form>
            </SectionCard>
        </HrmsLayout>
    );
}
