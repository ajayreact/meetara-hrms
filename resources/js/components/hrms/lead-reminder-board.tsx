import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Link } from '@inertiajs/react';

export interface LeadReminder {
    id: number;
    name: string;
    when: string;
    details: string;
    statusLabel: string;
    href: string;
}

export interface LeadReminders {
    overdue: LeadReminder[];
    today: LeadReminder[];
    upcoming: LeadReminder[];
    counts: { overdue: number; today: number; upcoming: number };
}

const emptyReminders: LeadReminders = {
    overdue: [],
    today: [],
    upcoming: [],
    counts: { overdue: 0, today: 0, upcoming: 0 },
};

export function LeadReminderBoard({ reminders }: { reminders?: LeadReminders | null }) {
    const data = reminders ?? emptyReminders;

    return (
        <div className="mt-4 grid gap-4 xl:grid-cols-3">
            <ReminderColumn title="Overdue reminders" rows={data.overdue} count={data.counts.overdue} tone="text-rose-700" />
            <ReminderColumn title="Today's reminders" rows={data.today} count={data.counts.today} />
            <ReminderColumn title="Upcoming reminders" rows={data.upcoming} count={data.counts.upcoming} />
        </div>
    );
}

function ReminderColumn({ title, rows, count, tone = 'text-slate-900' }: { title: string; rows: LeadReminder[]; count: number; tone?: string }) {
    return (
        <SectionCard title={title} extra={<span className="text-sm text-slate-400">{count}</span>}>
            {rows.length === 0 ? <p className="text-sm text-slate-500">Nothing in this list.</p> : (
                <ul className="space-y-3 text-sm">
                    {rows.map((row) => (
                        <li key={row.id} className="rounded-lg border border-slate-100 px-3 py-2">
                            <Link href={row.href} className={`font-medium hover:underline ${tone}`}>{row.name}</Link>
                            <p className="text-slate-600">{row.when}</p>
                            <p className="text-slate-600">{row.details}</p>
                            <div className="mt-1"><StatusBadge value={row.statusLabel} /></div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
