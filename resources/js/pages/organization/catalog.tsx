import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface CatalogRecord {
    id: number;
    name: string;
    code?: string | null;
    active: boolean;
}

interface CatalogPageProps extends HrmsPageProps {
    records: CatalogRecord[];
    notice?: string | null;
    title: string;
    description: string;
    storeUrl: string;
    updateUrl: (id: number) => string;
    showCode?: boolean;
}

export default function CatalogPage({ workspace, records, notice, title, description, storeUrl, updateUrl, showCode = false }: CatalogPageProps) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<CatalogRecord | null>(null);
    const form = useForm({ name: '', code: '', is_active: true });

    function startCreate() {
        setEditing(null);
        form.clearErrors();
        form.setData({ name: '', code: '', is_active: true });
        setOpen(true);
    }

    function startEdit(record: CatalogRecord) {
        setEditing(record);
        form.clearErrors();
        form.setData({ name: record.name, code: record.code ?? '', is_active: record.active });
        setOpen(true);
    }

    function save() {
        const options = { onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(updateUrl(editing.id), options);
            return;
        }

        form.post(storeUrl, options);
    }

    function setActive(record: CatalogRecord, active: boolean) {
        router.put(updateUrl(record.id), { name: record.name, code: record.code ?? '', is_active: active });
    }

    return (
        <HrmsLayout workspace={workspace} title={title}>
            <PageIntro title={title} subtitle={description} actions={<Button type="button" onClick={startCreate}>Add {title.slice(0, -1).toLowerCase()}</Button>} />
            {notice && <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{notice}</p>}
            <SectionCard title={`${title} in this company`}>
                <DataTable>
                    <thead>
                        <tr>
                            <th className={headCell}>Name</th>
                            {showCode && <th className={headCell}>Code</th>}
                            <th className={headCell}>Status</th>
                            <th className={headCell}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {records.map((record) => (
                            <tr key={record.id} className={bodyRow}>
                                <td className={bodyCell}>{record.name}</td>
                                {showCode && <td className={bodyCell}>{record.code || '—'}</td>}
                                <td className={bodyCell}><StatusBadge value={record.active ? 'Active' : 'Inactive'} /></td>
                                <td className={bodyCell}>
                                    <div className="flex gap-2">
                                        <Button type="button" variant="outline" onClick={() => startEdit(record)}>Edit</Button>
                                        <Button type="button" variant="outline" onClick={() => setActive(record, !record.active)}>{record.active ? 'Deactivate' : 'Activate'}</Button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {records.length === 0 && (
                            <tr>
                                <td className={bodyCell} colSpan={showCode ? 4 : 3}>Nothing has been added for this company yet.</td>
                            </tr>
                        )}
                    </tbody>
                </DataTable>
            </SectionCard>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${title.slice(0, -1).toLowerCase()}` : `Add ${title.slice(0, -1).toLowerCase()}`}</DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-3">
                        <label className="grid gap-1.5 text-sm">
                            <Label>Name</Label>
                            <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                            {form.errors.name && <span className="text-xs text-rose-600">{form.errors.name}</span>}
                        </label>
                        {showCode && (
                            <label className="grid gap-1.5 text-sm">
                                <Label>Code</Label>
                                <Input value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} />
                                {form.errors.code && <span className="text-xs text-rose-600">{form.errors.code}</span>}
                            </label>
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="button" disabled={form.processing} onClick={save}>{form.processing ? 'Saving…' : 'Save'}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </HrmsLayout>
    );
}
