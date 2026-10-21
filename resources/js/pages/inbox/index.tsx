import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { router } from '@inertiajs/react';
import { Bell, CalendarDays, ClipboardList, Clock, ExternalLink, LifeBuoy, Mail, MailOpen, Receipt, UserRound, type LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Notice {
    id: string;
    title: string;
    message: string;
    url: string;
    read: boolean;
    at: string;
}

type NoticeFilter = 'all' | 'unread' | 'read';

function stamp(value: string): { date: string; time: string; combined: string } {
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value);

    if (!match) {
        return { date: value, time: '', combined: value };
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]));
    const day = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    const time = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

    return { date: day, time, combined: `${day}, ${time}` };
}

function preview(message: string): string {
    const line = message.replace(/\s+/g, ' ').trim();

    return line.length > 78 ? `${line.slice(0, 75)}…` : line;
}

function noticeStyle(title: string): { icon: LucideIcon; tone: string } {
    const value = title.toLowerCase();

    if (value.includes('employee') || value.includes('joined')) {
        return { icon: UserRound, tone: 'bg-blue-50 text-blue-700' };
    }

    if (value.includes('onboarding')) {
        return { icon: ClipboardList, tone: 'bg-amber-50 text-amber-700' };
    }

    if (value.includes('leave')) {
        return { icon: CalendarDays, tone: 'bg-emerald-50 text-emerald-700' };
    }

    if (value.includes('payroll') || value.includes('payslip')) {
        return { icon: Receipt, tone: 'bg-violet-50 text-violet-700' };
    }

    if (value.includes('overtime')) {
        return { icon: Clock, tone: 'bg-amber-50 text-amber-700' };
    }

    if (value.includes('help') || value.includes('ticket')) {
        return { icon: LifeBuoy, tone: 'bg-sky-50 text-sky-700' };
    }

    return { icon: Bell, tone: 'bg-slate-100 text-slate-600' };
}

export default function InboxPage({ workspace, notifications = [], notice }: HrmsPageProps & { notifications?: Notice[]; notice?: string | null }) {
    const [filter, setFilter] = useState<NoticeFilter>('all');
    const [order, setOrder] = useState<'newest' | 'oldest'>('newest');
    const [active, setActive] = useState(notifications[0]?.id ?? '');
    const unreadCount = notifications.filter((item) => !item.read).length;
    const visible = useMemo(() => {
        const rows = notifications.filter((item) => filter === 'all' || (filter === 'unread' ? !item.read : item.read));

        return [...rows].sort((left, right) => (order === 'newest' ? right.at.localeCompare(left.at) : left.at.localeCompare(right.at)));
    }, [notifications, filter, order]);
    const message = visible.find((item) => item.id === active) ?? visible[0] ?? null;
    const filters: Array<{ id: NoticeFilter; label: string; count: number }> = [
        { id: 'all', label: 'All', count: notifications.length },
        { id: 'unread', label: 'Unread', count: unreadCount },
        { id: 'read', label: 'Read', count: notifications.length - unreadCount },
    ];

    return (
        <HrmsLayout workspace={workspace} title="Inbox">
            <PageIntro
                title="Inbox"
                subtitle="Notices saved for your account. Email, SMS, and push delivery are not connected."
                mark={<span className="flex size-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm" aria-hidden="true"><Mail className="size-5" /></span>}
                actions={<span className="hidden size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-100 to-blue-50 text-blue-600 lg:flex" aria-hidden="true"><Mail className="size-7" /></span>}
            />
            {notice && <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{notice}</p>}
            {notifications.length === 0 ? (
                <section className="rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
                    <span className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-blue-50 text-blue-700" aria-hidden="true">
                        <Mail className="size-5" />
                    </span>
                    <h2 className="mt-3 text-base font-semibold text-slate-950">No notices yet</h2>
                    <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500">Saved account notices will appear here. This page does not send email, SMS, or push messages.</p>
                </section>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(18rem,0.92fr)_minmax(0,1.08fr)]">
                    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                            <h2 className="text-[15px] font-semibold text-slate-950">Notifications</h2>
                            <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">{unreadCount} unread</span>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-3 py-3">
                            {filters.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    aria-pressed={filter === item.id}
                                    className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none ${filter === item.id ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                                    onClick={() => setFilter(item.id)}
                                >
                                    {item.id === 'unread' && <span className={`size-1.5 rounded-full ${filter === item.id ? 'bg-white' : 'bg-rose-500'}`} aria-hidden="true" />}
                                    {item.label}
                                    <span className={filter === item.id ? 'text-blue-100' : 'text-slate-400'}>({item.count})</span>
                                </button>
                            ))}
                            <label className="ml-auto text-sm text-slate-600">
                                <span className="sr-only">Sort notices</span>
                                <select
                                    className="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-700 focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none"
                                    value={order}
                                    onChange={(event) => setOrder(event.target.value === 'oldest' ? 'oldest' : 'newest')}
                                >
                                    <option value="newest">Newest first</option>
                                    <option value="oldest">Oldest first</option>
                                </select>
                            </label>
                        </div>
                        {visible.length === 0 ? (
                            <p className="px-4 py-8 text-sm text-slate-500">{filter === 'unread' ? 'No unread notices.' : 'No read notices.'}</p>
                        ) : (
                            <ul className="max-h-[28rem] overflow-y-auto lg:max-h-[70vh]">
                                {visible.map((item) => {
                                    const style = noticeStyle(item.title);
                                    const when = stamp(item.at);
                                    const selected = item.id === message?.id;

                                    return (
                                        <li key={item.id}>
                                            <button
                                                type="button"
                                                aria-current={selected ? 'true' : undefined}
                                                className={`flex w-full items-start gap-3 border-b border-slate-100 px-4 py-3 text-left focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none ${selected ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                                                onClick={() => setActive(item.id)}
                                            >
                                                <span className={`mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl ${style.tone}`} aria-hidden="true">
                                                    <style.icon className="size-4" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className={`block truncate text-sm ${item.read ? 'font-medium text-slate-700' : 'font-semibold text-slate-950'}`}>{item.title}</span>
                                                    <span className="mt-0.5 line-clamp-1 block text-xs text-slate-500">{preview(item.message) || (item.read ? 'Read' : 'Unread')}</span>
                                                    <span className="sr-only">{item.read ? 'Read' : 'Unread'}. {when.combined}</span>
                                                </span>
                                                <span className="shrink-0 text-right">
                                                    <span className="block text-xs text-slate-500">{when.date}</span>
                                                    {when.time !== '' && <span className="mt-0.5 block text-xs text-slate-400">{when.time}</span>}
                                                </span>
                                                <span className={`mt-1.5 size-2 shrink-0 rounded-full ${item.read ? 'border border-slate-300 bg-transparent' : 'bg-blue-600'}`} aria-hidden="true" />
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
                    <section className="flex min-h-72 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        {message ? (
                            <>
                                <header className="flex items-start gap-3 border-b border-slate-100 px-4 py-4">
                                    <NoticeMark title={message.title} />
                                    <div className="min-w-0 flex-1">
                                        <h2 className="text-base font-semibold text-slate-950">{message.title}</h2>
                                        <p className="mt-1 text-xs text-slate-500">{stamp(message.at).combined}</p>
                                    </div>
                                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${message.read ? 'bg-slate-100 text-slate-600' : 'bg-blue-50 text-blue-700'}`}>
                                        <span className={`size-1.5 rounded-full ${message.read ? 'bg-slate-400' : 'bg-blue-600'}`} aria-hidden="true" />
                                        {message.read ? 'Read' : 'Unread'}
                                    </span>
                                </header>
                                <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                                    <p className="text-sm leading-6 whitespace-pre-wrap text-slate-700">{message.message}</p>
                                </div>
                                <footer className="flex flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-3">
                                    {!message.read && (
                                        <Button type="button" onClick={() => router.post(`/inbox/${message.id}/read`)}>
                                            <MailOpen aria-hidden="true" />
                                            Mark as read
                                        </Button>
                                    )}
                                    {message.url !== '' && (
                                        <Button asChild variant="outline">
                                            <a href={message.url}>
                                                <ExternalLink aria-hidden="true" />
                                                Open the related page
                                            </a>
                                        </Button>
                                    )}
                                </footer>
                            </>
                        ) : (
                            <div className="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center">
                                <span className="flex size-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500" aria-hidden="true">
                                    <Mail className="size-5" />
                                </span>
                                <h2 className="mt-3 text-base font-semibold text-slate-950">No notice selected</h2>
                                <p className="mt-1 max-w-sm text-sm text-slate-500">Choose a notification from the list to read it here.</p>
                            </div>
                        )}
                    </section>
                </div>
            )}
        </HrmsLayout>
    );
}

function NoticeMark({ title }: { title: string }) {
    const style = noticeStyle(title);

    return (
        <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${style.tone}`} aria-hidden="true">
            <style.icon className="size-5" />
        </span>
    );
}
