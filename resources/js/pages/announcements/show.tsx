import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';

interface Announcement {
    id: number;
    title: string;
    description: string;
    category: string;
    categoryKey: string;
    visibility: string;
    visibilityKey: string;
    status: string;
    publishAt: string | null;
    expiresOn: string | null;
    createdBy: string;
    hasAttachment: boolean;
    attachmentName: string;
}

export default function AnnouncementShow({ workspace, announcement, canManage = false }: HrmsPageProps & { announcement: Announcement; canManage?: boolean }) {
    return (
        <HrmsLayout workspace={workspace} title={announcement.title}>
            <PageIntro
                title={announcement.title}
                subtitle={`${announcement.category} · ${announcement.status}`}
                actions={<Link className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" href={route('hrms.announcements')}>Back</Link>}
            />
            <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p className="text-sm text-slate-500">Published {announcement.publishAt ? announcement.publishAt.slice(0, 16).replace('T', ' ') : 'not yet'} · {announcement.createdBy || 'HR'}{announcement.expiresOn ? ` · Expires ${announcement.expiresOn}` : ''}</p>
                <p className="mt-1 text-sm text-slate-500">{announcement.visibility}</p>
                <p className="mt-4 whitespace-pre-wrap text-sm leading-6 text-slate-800">{announcement.description}</p>
                {announcement.hasAttachment && (
                    <a className="mt-4 inline-flex text-sm font-medium text-blue-700" href={route('hrms.announcements.attachment', announcement.id)}>{announcement.attachmentName || 'Download attachment'}</a>
                )}
                {canManage && announcement.status !== 'Archived' && (
                    <AnnouncementEditor announcement={announcement} />
                )}
            </article>
        </HrmsLayout>
    );
}

function AnnouncementEditor({ announcement }: { announcement: Announcement }) {
    const form = useForm({
        title: announcement.title,
        description: announcement.description,
        category: announcement.categoryKey,
        visibility: announcement.visibilityKey,
        publish_at: announcement.publishAt ? announcement.publishAt.slice(0, 16).replace(' ', 'T') : '',
        expires_on: announcement.expiresOn ?? '',
    });

    return (
        <form
            className="mt-5 grid gap-3 border-t border-slate-100 pt-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('hrms.announcements.update', announcement.id));
            }}
        >
            <label className="text-sm text-slate-700">Title
                <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
            </label>
            <label className="text-sm text-slate-700">Description
                <textarea className="mt-1 min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm text-slate-700">Category
                    <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>
                        <option value="general">General Updates</option>
                        <option value="hr">HR Notices</option>
                        <option value="holiday">Holidays</option>
                        <option value="payroll">Payroll Updates</option>
                        <option value="event">Company Events</option>
                        <option value="important">Important Notices</option>
                    </select>
                </label>
                <label className="text-sm text-slate-700">Visibility
                    <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.visibility} onChange={(event) => form.setData('visibility', event.target.value)}>
                        <option value="company">Everyone in the company</option>
                        <option value="employees">Employees and team leads</option>
                        <option value="hr">HR and company admin</option>
                    </select>
                </label>
                <label className="text-sm text-slate-700">Publish date
                    <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" type="datetime-local" value={form.data.publish_at} onChange={(event) => form.setData('publish_at', event.target.value)} />
                </label>
                <label className="text-sm text-slate-700">Expiry date
                    <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" type="date" value={form.data.expires_on} onChange={(event) => form.setData('expires_on', event.target.value)} />
                </label>
            </div>
            <div className="flex flex-wrap gap-2">
                <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="submit" disabled={form.processing}>Save changes</button>
                {announcement.status !== 'Published' && announcement.status !== 'Expired' && (
                    <button className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white" type="button" onClick={() => router.post(route('hrms.announcements.publish', announcement.id))}>Publish</button>
                )}
                <button className="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.announcements.archive', announcement.id))}>Archive</button>
            </div>
        </form>
    );
}
