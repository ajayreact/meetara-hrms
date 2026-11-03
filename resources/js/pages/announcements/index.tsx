import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

interface AnnouncementRow {
    id: number;
    title: string;
    category: string;
    visibility: string;
    status: string;
    publishAt: string | null;
    expiresOn: string | null;
    createdBy: string;
    hasAttachment: boolean;
}

export default function AnnouncementsPage({
    workspace,
    announcements = [],
    canManage = false,
    categories = {},
    visibility = {},
    unread = 0,
    notice,
}: HrmsPageProps & {
    announcements?: AnnouncementRow[];
    canManage?: boolean;
    categories?: Record<string, string>;
    visibility?: Record<string, string>;
    unread?: number;
    notice?: string | null;
}) {
    const visible = canManage ? announcements : announcements.filter((item) => item.status === 'Published');

    return (
        <HrmsLayout workspace={workspace} title="Announcements">
            <PageIntro title="Announcements" subtitle={unread > 0 ? `${unread} unread announcement notification${unread === 1 ? '' : 's'}.` : 'Company notices published for you.'} />
            {notice && <p className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}
            {canManage && <AnnouncementForm categories={categories} visibility={visibility} />}
            <SectionCard title={canManage ? 'Company announcements' : 'Published announcements'}>
                {visible.length === 0 ? (
                    <p className="text-sm text-slate-500">No announcements are available.</p>
                ) : (
                    <ul className="grid gap-3">
                        {visible.map((item) => (
                            <li key={item.id} className="rounded-xl border border-slate-200 px-4 py-3">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <Link className="font-medium text-slate-950 hover:text-blue-700" href={route('hrms.announcements.show', item.id)}>{item.title}</Link>
                                        <p className="mt-1 text-sm text-slate-500">{item.category} · {item.status}{item.publishAt ? ` · ${item.publishAt.slice(0, 10)}` : ''}</p>
                                    </div>
                                    <span className="text-xs text-slate-500">{item.createdBy || 'HR'}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
        </HrmsLayout>
    );
}

function AnnouncementForm({ categories, visibility }: { categories: Record<string, string>; visibility: Record<string, string> }) {
    const form = useForm<{ title: string; description: string; category: string; visibility: string; publish_at: string; expires_on: string; attachment: File | null }>({
        title: '',
        description: '',
        category: Object.keys(categories)[0] ?? 'general',
        visibility: 'company',
        publish_at: '',
        expires_on: '',
        attachment: null,
    });

    return (
        <SectionCard title="New announcement" className="mb-4">
            <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.announcements.store'), { forceFormData: true }); }}>
                <label className="text-sm text-slate-700">Title
                    <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                    {form.errors.title && <span className="mt-1 block text-rose-700">{form.errors.title}</span>}
                </label>
                <label className="text-sm text-slate-700">Description
                    <textarea className="mt-1 min-h-28 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                    {form.errors.description && <span className="mt-1 block text-rose-700">{form.errors.description}</span>}
                </label>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm text-slate-700">Category
                        <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>
                            {Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                    </label>
                    <label className="text-sm text-slate-700">Visibility
                        <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.visibility} onChange={(event) => form.setData('visibility', event.target.value)}>
                            {Object.entries(visibility).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                    </label>
                    <label className="text-sm text-slate-700">Publish date
                        <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" type="datetime-local" value={form.data.publish_at} onChange={(event) => form.setData('publish_at', event.target.value)} />
                    </label>
                    <label className="text-sm text-slate-700">Expiry date
                        <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" type="date" value={form.data.expires_on} onChange={(event) => form.setData('expires_on', event.target.value)} />
                    </label>
                </div>
                <label className="text-sm text-slate-700">Attachment
                    <input className="mt-1 block w-full text-sm" type="file" accept="application/pdf,image/png,image/jpeg" onChange={(event) => form.setData('attachment', event.target.files?.[0] ?? null)} />
                </label>
                <button className="w-fit rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" disabled={form.processing}>Save draft</button>
            </form>
        </SectionCard>
    );
}
