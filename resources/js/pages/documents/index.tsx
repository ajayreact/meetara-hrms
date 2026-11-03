import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';

interface Category {
    key: string;
    name: string;
    group: string;
    groupKey: string;
    required: boolean;
    active: boolean;
}

interface DocumentRow {
    id: number;
    employeeId: number;
    employee: string;
    department: string | null;
    category: string;
    status: string;
    uploadedAt: string;
}

interface Checklist {
    employeeId: number;
    name: string;
    code: string;
    percentage: number;
    complete: boolean;
    missing: number;
    pending: number;
    rejected: number;
}

export default function DocumentsPage({
    workspace,
    summary,
    filters,
    employees = [],
    categories = [],
    groups = {},
    documents = [],
    queue = [],
    checklists = [],
    notice,
}: HrmsPageProps & {
    summary: { total: number; pending: number; verified: number; rejected: number; missing: number; incompleteEmployees: number };
    filters: { employee: number | null; department: number | null; category: string; status: string };
    employees?: { id: number; name: string; department: string | null; departmentId: number | null }[];
    categories?: Category[];
    groups?: Record<string, string>;
    documents?: DocumentRow[];
    queue?: DocumentRow[];
    checklists?: Checklist[];
    notice?: string | null;
}) {
    const departments = employees.filter((employee, index) => employee.departmentId && employees.findIndex((item) => item.departmentId === employee.departmentId) === index);

    return (
        <HrmsLayout workspace={workspace} title="Documents">
            <PageIntro title="Documents" subtitle="Company document submissions and verification." actions={<Link className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" href={route('hrms.documents')}>Clear filters</Link>} />
            {notice && <p className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Metric label="Total documents" value={summary.total} />
                <Metric label="Pending verification" value={summary.pending} />
                <Metric label="Verified documents" value={summary.verified} />
                <Metric label="Rejected documents" value={summary.rejected} />
                <Metric label="Missing required documents" value={summary.missing} />
                <Metric label="Incomplete submissions" value={summary.incompleteEmployees} />
            </div>
            <form className="mb-4 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-4" onSubmit={(event) => {
                event.preventDefault();
                const data = new FormData(event.currentTarget);
                router.get(route('hrms.documents'), {
                    employee: String(data.get('employee') ?? ''),
                    department: String(data.get('department') ?? ''),
                    category: String(data.get('category') ?? ''),
                    status: String(data.get('status') ?? ''),
                }, { preserveState: true });
            }}>
                <label className="text-sm text-slate-700">Employee
                    <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" name="employee" defaultValue={filters.employee ?? ''}>
                        <option value="">All employees</option>
                        {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
                    </select>
                </label>
                <label className="text-sm text-slate-700">Department
                    <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" name="department" defaultValue={filters.department ?? ''}>
                        <option value="">All departments</option>
                        {departments.map((employee) => <option key={employee.departmentId} value={employee.departmentId ?? ''}>{employee.department}</option>)}
                    </select>
                </label>
                <label className="text-sm text-slate-700">Document category
                    <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" name="category" defaultValue={filters.category}>
                        <option value="">All categories</option>
                        {categories.map((category) => <option key={category.key} value={category.key}>{category.name}</option>)}
                    </select>
                </label>
                <label className="text-sm text-slate-700">Verification status
                    <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" name="status" defaultValue={filters.status}>
                        <option value="">All statuses</option>
                        <option>Pending Verification</option>
                        <option>Verified</option>
                        <option>Rejected</option>
                    </select>
                </label>
                <button className="w-fit rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit">Apply filters</button>
            </form>
            <div className="grid gap-4 xl:grid-cols-2">
                <SectionCard title="Verification queue">
                    {queue.length === 0 ? <p className="text-sm text-slate-500">No documents are waiting for verification.</p> : (
                        <DocumentList rows={queue} />
                    )}
                </SectionCard>
                <SectionCard title="Incomplete submissions">
                    {checklists.filter((item) => !item.complete).length === 0 ? <p className="text-sm text-slate-500">Every employee has the required documents verified.</p> : (
                        <ul className="space-y-2 text-sm">
                            {checklists.filter((item) => !item.complete).map((item) => (
                                <li key={item.employeeId} className="flex items-center justify-between gap-3 rounded-xl border border-slate-100 px-3 py-2">
                                    <Link className="font-medium text-slate-900 hover:text-blue-700" href={route('hrms.documents.employee', item.employeeId)}>{item.name}</Link>
                                    <span className="text-slate-500">{item.percentage}% · {item.missing} missing</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
            <div className="mt-4">
                <SectionCard title="Latest documents">
                    {documents.length === 0 ? <p className="text-sm text-slate-500">No documents match these filters.</p> : <DocumentList rows={documents} />}
                </SectionCard>
            </div>
            <CategoryEditor categories={categories} groups={groups} />
        </HrmsLayout>
    );
}

function DocumentList({ rows }: { rows: DocumentRow[] }) {
    return (
        <ul className="space-y-2 text-sm">
            {rows.map((row) => (
                <li key={row.id} className="rounded-xl border border-slate-100 px-3 py-2">
                    <Link className="font-medium text-slate-900 hover:text-blue-700" href={route('hrms.documents.show', [row.employeeId, row.id])}>{row.employee} · {row.category}</Link>
                    <p className="text-xs text-slate-500">{row.status} · {row.department || 'No department'} · {row.uploadedAt.slice(0, 10)}</p>
                </li>
            ))}
        </ul>
    );
}

function Metric({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <p className="text-xs text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-slate-950">{value}</p>
        </div>
    );
}

function CategoryEditor({ categories, groups }: { categories: Category[]; groups: Record<string, string> }) {
    const form = useForm({
        categories: categories.map((category) => ({ key: category.key, name: category.name, required: category.required ? 1 : 0, active: category.active ? 1 : 0 })),
    });
    const added = useForm({ name: '', group: 'other' });

    return (
        <div className="mt-4 grid gap-4">
            <SectionCard title="Document categories">
                <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); form.post(route('hrms.documents.categories')); }}>
                    <ul className="grid gap-2">
                        {form.data.categories.map((category, index) => (
                            <li key={category.key} className="grid gap-2 rounded-xl border border-slate-100 px-3 py-2 sm:grid-cols-[1fr_auto_auto] sm:items-center">
                                <input className="rounded-lg border border-slate-200 px-3 py-2 text-sm" value={category.name} onChange={(event) => form.setData('categories', form.data.categories.map((item, itemIndex) => itemIndex === index ? { ...item, name: event.target.value } : item))} />
                                <label className="text-xs text-slate-600"><input className="mr-1" type="checkbox" checked={category.required === 1} onChange={(event) => form.setData('categories', form.data.categories.map((item, itemIndex) => itemIndex === index ? { ...item, required: event.target.checked ? 1 : 0 } : item))} />Required</label>
                                <label className="text-xs text-slate-600"><input className="mr-1" type="checkbox" checked={category.active === 1} onChange={(event) => form.setData('categories', form.data.categories.map((item, itemIndex) => itemIndex === index ? { ...item, active: event.target.checked ? 1 : 0 } : item))} />Active</label>
                            </li>
                        ))}
                    </ul>
                    <button className="w-fit rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={form.processing}>Save categories</button>
                </form>
            </SectionCard>
            <SectionCard title="Add a category">
                <form className="grid gap-3 sm:grid-cols-[1fr_16rem_auto] sm:items-end" onSubmit={(event) => { event.preventDefault(); added.post(route('hrms.documents.categories.add')); }}>
                    <label className="text-sm text-slate-700">Name
                        <input className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={added.data.name} onChange={(event) => added.setData('name', event.target.value)} />
                    </label>
                    <label className="text-sm text-slate-700">Group
                        <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={added.data.group} onChange={(event) => added.setData('group', event.target.value)}>
                            {Object.entries(groups).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                    </label>
                    <button className="rounded-lg border border-slate-200 px-4 py-2 text-sm" type="submit" disabled={added.processing}>Add category</button>
                </form>
            </SectionCard>
        </div>
    );
}
