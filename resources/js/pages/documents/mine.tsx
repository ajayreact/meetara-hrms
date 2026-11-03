import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { useForm } from '@inertiajs/react';

interface Category {
    key: string;
    name: string;
    group: string;
}

interface Item {
    id: number;
    category: string;
    categoryKey: string;
    status: string;
    reference: string;
    description: string;
    uploadedAt: string;
    note: string;
    latest: boolean;
    preview: boolean;
}

interface ChecklistItem {
    key: string;
    name: string;
    status: string;
}

export default function MyDocumentsPage({
    workspace,
    linked = false,
    employeeId = null,
    checklist = null,
    items = [],
    categories = [],
    notice,
}: HrmsPageProps & {
    linked?: boolean;
    employeeId?: number | null;
    checklist?: { percentage: number; items: ChecklistItem[] } | null;
    items?: Item[];
    categories?: Category[];
    notice?: string | null;
}) {
    const form = useForm<{ category: string; reference: string; description: string; file: File | null }>({
        category: categories[0]?.key ?? '',
        reference: '',
        description: '',
        file: null,
    });
    const latest = items.filter((item) => item.latest);
    const rejected = latest.filter((item) => item.status === 'Rejected').map((item) => item.categoryKey);

    return (
        <HrmsLayout workspace={workspace} title="My Documents">
            <PageIntro title="My Documents" subtitle={checklist ? `${checklist.percentage}% of required documents are verified.` : 'Upload documents for HR verification.'} />
            {notice && <p className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}
            {!linked ? <p className="text-sm text-slate-500">Your login is not linked to an employee record.</p> : (
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <SectionCard title="Required documents">
                        {!checklist || checklist.items.length === 0 ? <p className="text-sm text-slate-500">No documents are marked required.</p> : (
                            <ul className="space-y-2 text-sm">
                                {checklist.items.map((item) => (
                                    <li key={item.key} className="flex justify-between gap-3 rounded-xl border border-slate-100 px-3 py-2"><span>{item.name}</span><span className="text-slate-500">{item.status}</span></li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                    <SectionCard title="Upload">
                        <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.my-documents.store'), { forceFormData: true }); }}>
                            <label className="text-sm">Category
                                <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>
                                    {categories.map((category) => <option key={category.key} value={category.key}>{category.group} · {category.name}</option>)}
                                </select>
                            </label>
                            <label className="text-sm">Reference number
                                <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} />
                            </label>
                            <label className="text-sm">Description
                                <textarea className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                            </label>
                            <label className="text-sm">File
                                <input className="mt-1 block w-full text-sm" type="file" accept="application/pdf,image/png,image/jpeg" onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
                            </label>
                            {form.errors.file && <p className="text-sm text-rose-700">{form.errors.file}</p>}
                            {form.errors.category && <p className="text-sm text-rose-700">{form.errors.category}</p>}
                            <button className="w-fit rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={form.processing}>Upload</button>
                            {rejected.includes(form.data.category) && <p className="text-xs text-slate-500">The current file was rejected. This upload becomes a new version.</p>}
                        </form>
                    </SectionCard>
                    <div className="lg:col-span-2">
                        <SectionCard title="My uploads">
                            {items.length === 0 ? <p className="text-sm text-slate-500">No documents uploaded yet.</p> : (
                                <ul className="space-y-2 text-sm">
                                    {items.map((item) => (
                                        <li key={item.id} className="rounded-xl border border-slate-100 px-3 py-2">
                                            <p className="font-medium text-slate-900">{item.category}{item.latest ? '' : ' · earlier version'}</p>
                                            <p className="text-xs text-slate-500">{item.status} · {item.uploadedAt.slice(0, 10)}{item.reference ? ` · ${item.reference}` : ''}</p>
                                            {item.note && <p className="text-xs text-slate-500">{item.note}</p>}
                                            {employeeId && (
                                                <span className="mt-1 flex gap-3">
                                                    <a className="text-blue-700" href={route('hrms.employees.documents.download', [employeeId, item.id])}>Download</a>
                                                    {item.preview && <a className="text-blue-700" href={route('hrms.my-documents.preview', item.id)}>Preview</a>}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>
                </div>
            )}
        </HrmsLayout>
    );
}
