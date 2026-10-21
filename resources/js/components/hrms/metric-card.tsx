import { StatusBadge } from '@/components/hrms/section-card';
import type { LucideIcon } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

const tones = {
    blue: 'bg-blue-50 text-blue-700',
    green: 'bg-emerald-50 text-emerald-700',
    orange: 'bg-amber-50 text-amber-700',
    red: 'bg-rose-50 text-rose-700',
};

export function MetricCard({ label, value, hint, badge, href, action, icon: Icon, tone }: {
    label: string;
    value: string;
    hint?: string;
    badge?: string;
    href?: string;
    action?: string;
    icon: LucideIcon;
    tone: keyof typeof tones;
}) {
    return (
        <article className="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-center gap-3">
                <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${tones[tone]}`}>
                    <Icon className="size-5" aria-hidden="true" />
                </span>
                <p className="text-sm font-medium text-slate-600">{label}</p>
            </div>
            <p className="mt-3 text-[28px] leading-none font-semibold tracking-tight text-slate-950">{value}</p>
            {(hint || badge) && (
                <div className="mt-2 flex items-center justify-between gap-2">
                    {hint && <p className="text-xs text-slate-500">{hint}</p>}
                    {badge && <StatusBadge value={badge} />}
                </div>
            )}
            {href && action && (
                <Link href={href} className="mt-auto inline-flex items-center gap-1 self-end pt-3 text-sm font-medium text-blue-700 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none">
                    {action}
                    <ArrowRight className="size-3.5" aria-hidden="true" />
                </Link>
            )}
        </article>
    );
}
