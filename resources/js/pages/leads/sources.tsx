import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

interface SourceRow {
    id: number;
    name: string;
    active: boolean;
    leads: number;
    converted: number;
    conversionRate: number;
}

export default function LeadSourcesPage({ workspace, sources, notice, error }: HrmsPageProps & { sources: SourceRow[]; notice?: string | null; error?: string | null }) {
    const created = useForm({ name: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        created.post('/leads/sources', { preserveScroll: true, onSuccess: () => created.reset('name') });
    }

    return (
        <HrmsLayout workspace={workspace} title="Lead sources">
            <PageIntro
                title="Lead sources"
                subtitle="Head of Operations manages the sources used on new leads. A lead keeps the source name it was given."
                actions={<Link href="/leads" className="text-sm font-medium text-blue-700">Back to leads</Link>}
            />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            <SectionCard title="Add a source">
                <form className="flex flex-wrap items-end gap-2" onSubmit={save}>
                    <label className="grid min-w-64 flex-1 gap-1 text-xs text-slate-500">
                        Source name
                        <Input aria-label="Source name" value={created.data.name} onChange={(event) => created.setData('name', event.target.value)} className="bg-white" />
                    </label>
                    <Button type="submit" disabled={created.processing}>Save source</Button>
                </form>
                {created.errors.name && <p className="mt-2 text-sm text-rose-700">{created.errors.name}</p>}
            </SectionCard>
            <div className="mt-4">
                <SectionCard title="Sources and conversion">
                    {sources.length === 0 ? (
                        <p className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No lead sources yet.</p>
                    ) : (
                        <DataTable>
                            <thead>
                                <tr>
                                    <th className={headCell}>Source</th>
                                    <th className={headCell}>Leads</th>
                                    <th className={headCell}>Converted</th>
                                    <th className={headCell}>Conversion</th>
                                    <th className={headCell}>Status</th>
                                    <th className={headCell}>Save</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sources.map((source) => <SourceEditor key={source.id} source={source} />)}
                            </tbody>
                        </DataTable>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function SourceEditor({ source }: { source: SourceRow }) {
    const form = useForm({ name: source.name, active: source.active });

    return (
        <tr className={bodyRow}>
            <td className={bodyCell}>
                <Input aria-label={`Source name ${source.name}`} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className="bg-white" />
                {form.errors.name && <p className="mt-1 text-xs text-rose-700">{form.errors.name}</p>}
            </td>
            <td className={bodyCell}>{source.leads}</td>
            <td className={bodyCell}>{source.converted}</td>
            <td className={bodyCell}>{source.conversionRate}%</td>
            <td className={bodyCell}>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} />
                    Active
                </label>
            </td>
            <td className={bodyCell}>
                <Button type="button" size="sm" variant="outline" disabled={form.processing} onClick={() => form.post(`/leads/sources/${source.id}`, { preserveScroll: true })}>Save</Button>
            </td>
        </tr>
    );
}
