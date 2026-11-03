import { HrmsLayout } from '@/components/hrms/hrms-layout';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { stamp, templateStatus, TemplateRow } from './india-shared';
import { RichLetterEditor } from './rich-letter-editor';

export default function IndiaEditor({
    workspace,
    templates = [],
    placeholders = [],
    indiaHtml = '',
    actorId = 0,
    canConfigure = false,
    notice,
}: HrmsPageProps & {
    templates?: TemplateRow[];
    placeholders?: string[];
    indiaHtml?: string | null;
    actorId?: number;
    canConfigure?: boolean;
    notice?: string;
}) {
    const standard = templates.find((item) => item.name === 'India Appointment Letter') ?? templates[0] ?? null;
    const stored = standard?.body?.trim() ?? '';
    const form = useForm({
        country: 'india',
        name: standard?.name ?? 'India Appointment Letter',
        body: stored.startsWith('<') ? stored : (indiaHtml || '<p></p>'),
        template_id: (stored.startsWith('<') ? (standard?.id ?? '') : '') as number | '',
    });
    const [previewError, setPreviewError] = useState('');
    const current = templates.find((item) => item.id === form.data.template_id) ?? null;
    const status = templateStatus(current?.status ?? 'draft');
    const canSubmit = current?.status === 'draft';
    const canApprove = current?.status === 'submitted' && current.createdBy !== actorId && current.submittedBy !== actorId;
    const canActivate = canConfigure && current?.status === 'approved';

    async function openPreview() {
        setPreviewError('');
        const row = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
        const token = row ? decodeURIComponent(row.slice('XSRF-TOKEN='.length)) : '';
        const response = await fetch(route('hrms.offer-letters.templates.sample-pdf'), {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            },
            body: JSON.stringify({ country: 'india', body: form.data.body }),
        });
        const type = response.headers.get('content-type') ?? '';

        if (response.type === 'opaqueredirect' || !response.ok || !type.includes('pdf')) {
            const payload = type.includes('json')
                ? await response.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null
                : null;
            setPreviewError(payload?.errors?.body?.[0] ?? payload?.message ?? 'The preview could not be created. Check the letter and try again.');

            return;
        }

        const blob = await response.blob();
        const pdf = blob.type.includes('pdf') ? blob : new Blob([await blob.arrayBuffer()], { type: 'application/pdf' });
        const url = URL.createObjectURL(pdf);
        window.open(url, '_blank', 'noopener');
        window.setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    return (
        <HrmsLayout workspace={workspace} title="Edit India Appointment Letter">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm text-slate-500">
                        <Link className="hover:text-slate-800" href={route('hrms.offer-letters.templates', { country: 'india' })}>India Offer Letter Settings</Link>
                        <span className="px-1.5">/</span>
                        <span className="text-slate-700">Editor</span>
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight text-slate-950">{form.data.name || 'India template'}</h1>
                        <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${status.tone === 'amber' ? 'bg-amber-50 text-amber-800' : status.tone === 'green' ? 'bg-emerald-50 text-emerald-700' : status.tone === 'blue' ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'}`}>{status.label}</span>
                    </div>
                    <p className="mt-1 text-sm text-slate-500">{status.detail} Last updated {stamp(current?.updatedAt)}.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-60" type="button" disabled={form.processing} onClick={() => form.post(route('hrms.offer-letters.templates.save'))}>Save draft</button>
                    <button className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" type="button" onClick={openPreview}>Preview</button>
                    {canSubmit && current && <button className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.submit', current.id))}>Submit for approval</button>}
                    {canApprove && current && <button className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.approve', current.id), { confirm: 'APPROVE' })}>Approve</button>}
                    {canActivate && current && <button className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.activate', current.id), { confirm: 'ACTIVATE' })}>Activate</button>}
                </div>
            </div>
            {notice && <p className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">{notice}</p>}
            {current?.status === 'submitted' && !canApprove && <p className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">This version is already submitted. Saving a change returns it to a draft. Another authorized person approves it, and it is not activated automatically.</p>}
            {previewError && <p className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{previewError}</p>}
            {form.errors.body && <p className="mb-4 text-sm text-rose-700">{form.errors.body}</p>}
            <label className="mb-4 block text-sm text-slate-700">Template name
                <input className="mt-1 w-full max-w-xl rounded-lg border border-slate-200 px-3 py-2 text-sm" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
            </label>
            <div className="mb-4 flex flex-wrap gap-2">
                <button className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" type="button" onClick={() => form.setData({ country: 'india', name: '', body: '<p></p>', template_id: '' })}>New template</button>
                {current && <button className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" type="button" onClick={() => router.post(route('hrms.offer-letters.templates.duplicate', current.id))}>Duplicate</button>}
            </div>
            <ul className="mb-4 grid gap-2">
                {templates.map((item) => (
                    <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                        <span>{item.name} · v{item.version} · {templateStatus(item.status).label}{item.status === 'active' ? ' · default' : ''}</span>
                        <button className="rounded-lg border border-slate-200 px-3 py-1" type="button" onClick={() => form.setData({ country: 'india', name: item.name, body: item.body.startsWith('<') ? item.body : (indiaHtml || '<p></p>'), template_id: item.id })}>Edit</button>
                    </li>
                ))}
            </ul>
            <RichLetterEditor presentation="page" body={form.data.body} placeholders={placeholders} onChange={(body) => form.setData('body', body)} />
            {indiaHtml && (
                <button className="mt-4 text-sm text-slate-500 underline" type="button" onClick={() => form.setData({ ...form.data, name: 'India Appointment Letter', body: indiaHtml, template_id: standard?.name === 'India Appointment Letter' ? standard.id : '' })}>Load the 22-clause appointment wording into the editor</button>
            )}
        </HrmsLayout>
    );
}
