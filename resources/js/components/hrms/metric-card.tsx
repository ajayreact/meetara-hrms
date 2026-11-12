import { StatusBadge } from '@/components/hrms/section-card';
import type { LucideIcon } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

const tones = {
    blue: {
        icon: 'bg-blue-600 text-white shadow-sm shadow-blue-600/25',
        card: 'border-blue-100 bg-gradient-to-br from-white via-white to-blue-50/80',
        value: 'text-blue-950',
        link: 'text-blue-700',
    },
    green: {
        icon: 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/25',
        card: 'border-emerald-100 bg-gradient-to-br from-white via-white to-emerald-50/80',
        value: 'text-emerald-950',
        link: 'text-emerald-700',
    },
    orange: {
        icon: 'bg-amber-500 text-white shadow-sm shadow-amber-500/25',
        card: 'border-amber-100 bg-gradient-to-br from-white via-white to-amber-50/80',
        value: 'text-amber-950',
        link: 'text-amber-800',
    },
    red: {
        icon: 'bg-rose-600 text-white shadow-sm shadow-rose-600/25',
        card: 'border-rose-100 bg-gradient-to-br from-white via-white to-rose-50/80',
        value: 'text-rose-950',
        link: 'text-rose-700',
    },
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
    const palette = tones[tone];

    return (
        <article className={`flex h-full flex-col rounded-2xl border p-4 shadow-sm ${palette.card}`}>
            <div className="flex items-center gap-3">
                <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${palette.icon}`}>
                    <Icon className="size-5" aria-hidden="true" />
                </span>
                <p className="text-sm font-medium text-slate-600">{label}</p>
            </div>
            <p className={`mt-3 text-[28px] leading-none font-semibold tracking-tight ${palette.value}`}>{value}</p>
            {(hint || badge) && (
                <div className="mt-2 flex items-center justify-between gap-2">
                    {hint && <p className="text-xs text-slate-500">{hint}</p>}
                    {badge && <StatusBadge value={badge} />}
                </div>
            )}
            {href && action && (
                <Link href={href} className={`mt-auto inline-flex items-center gap-1 self-end pt-3 text-sm font-medium hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none ${palette.link}`}>
                    {action}
                    <ArrowRight className="size-3.5" aria-hidden="true" />
                </Link>
            )}
        </article>
    );
}
