import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link } from '@inertiajs/react';

interface ChecklistItem {
    key: string;
    name: string;
    status: string;
    documentId: number | null;
}

interface Version {
    id: number;
    category: string;
    status: string;
    uploadedAt: string;
    latest: boolean;
    fileName: string;
}

export default function EmployeeDocumentsPage({
    workspace,
    employee,
    checklist,
    items = [],
}: HrmsPageProps & {
    employee: { id: number; name: string; code: string; department: string | null };
    checklist: { percentage: number; required: number; verified: number; missing: number; pending: number; rejected: number; items: ChecklistItem[] };
    items?: Version[];
}) {
    return (
        <HrmsLayout workspace={workspace} title={`${employee.name} documents`}>
            <PageIntro title={employee.name} subtitle={`${employee.code} · ${employee.department || 'No department'} · ${checklist.percentage}% complete`} actions={<Link className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" href={route('hrms.documents')}>All documents</Link>} />
            <div className="mb-4 grid gap-3 sm:grid-cols-4">
                <Stat label="Required" value={String(checklist.required)} />
                <Stat label="Verified" value={String(checklist.verified)} />
                <Stat label="Pending" value={String(checklist.pending)} />
                <Stat label="Missing or rejected" value={`${checklist.missing} / ${checklist.rejected}`} />
            </div>
            <SectionCard title="Required document checklist">
                {checklist.items.length === 0 ? <p className="text-sm text-slate-500">No document categories are marked required.</p> : (
                    <ul className="space-y-2 text-sm">
                        {checklist.items.map((item) => (
                            <li key={item.key} className="flex items-center justify-between gap-3 rounded-xl border border-slate-100 px-3 py-2">
                                <span>{item.name}</span>
                                {item.documentId ? <Link className="font-medium text-blue-700" href={route('hrms.documents.show', [employee.id, item.documentId])}>{item.status}</Link> : <span className="text-slate-500">{item.status}</span>}
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            <div className="mt-4">
                <SectionCard title="Uploaded documents">
                    {items.length === 0 ? <p className="text-sm text-slate-500">No documents have been uploaded.</p> : (
                        <ul className="space-y-2 text-sm">
                            {items.map((item) => (
                                <li key={item.id}>
                                    <Link className="font-medium text-slate-900 hover:text-blue-700" href={route('hrms.documents.show', [employee.id, item.id])}>{item.category}</Link>
                                    <p className="text-xs text-slate-500">{item.status} · {item.uploadedAt.slice(0, 10)}{item.latest ? ' · Latest version' : ' · Earlier version'}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-lg font-semibold">{value}</p></div>;
}
