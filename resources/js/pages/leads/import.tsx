import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

interface PreviewRow {
    line: number;
    outcome: 'new' | 'duplicate' | 'invalid';
    reason: string | null;
    name: string;
    email: string;
    phone: string;
    linkedin: string;
    state: string;
    university: string;
    graduationTerm: string;
    experience: string;
    designations: string;
    importedOn: string | null;
}

interface Preview {
    id: number;
    fileName: string;
    status: string;
    rawStatus: string;
    uploadedBy: string | null;
    uploadedAt: string | null;
    rowCount: number;
    createdCount: number;
    duplicateCount: number;
    invalidCount: number;
    canConfirm: boolean;
    truncated: boolean;
    rows: PreviewRow[];
}

const outcomeLabel: Record<PreviewRow['outcome'], string> = {
    new: 'New',
    duplicate: 'Duplicate',
    invalid: 'Invalid',
};

export default function LeadImportPage({
    workspace,
    preview = null,
    notice,
    error,
}: HrmsPageProps & { preview?: Preview | null; notice?: string | null; error?: string | null }) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const confirm = useForm({});

    return (
        <HrmsLayout workspace={workspace} title="Lead import">
            <PageIntro
                title="Lead import"
                subtitle="Upload the Excel workbook, review Sheet1, then confirm. Only new leads are saved, and they stay unassigned."
            />
            {(notice || error) && (
                <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>
            )}
            <SectionCard title="Upload workbook">
                <form
                    className="grid gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        upload.post('/leads/imports', { forceFormData: true });
                    }}
                >
                    <label className="grid gap-1 text-sm" htmlFor="lead-file">
                        <Label htmlFor="lead-file">Excel file</Label>
                        <input
                            id="lead-file"
                            className="block w-full text-sm"
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)}
                        />
                    </label>
                    <p className="text-sm text-slate-600">Use an .xlsx file. Sheet1 is read and Sheet2 is ignored. The Emaill column is Email. The Year column is the graduation term.</p>
                    {upload.errors.file && <p className="text-sm text-rose-700">{upload.errors.file}</p>}
                    <div className="flex flex-wrap items-center gap-4">
                        <Button type="submit" disabled={upload.processing || upload.data.file === null}>
                            {upload.processing ? 'Uploading…' : 'Upload and preview'}
                        </Button>
                        <Link href="/leads" className="text-sm text-blue-700">Back to leads</Link>
                    </div>
                </form>
            </SectionCard>
            {preview && (
                <div className="mt-4">
                    <SectionCard title={preview.fileName} extra={<StatusBadge value={preview.status} />}>
                        <p className="text-sm text-slate-600">
                            Uploaded by {preview.uploadedBy ?? '—'}
                            {preview.uploadedAt ? ` on ${preview.uploadedAt}` : ''}. Nothing is saved until you confirm. Duplicates and rows without a candidate name are skipped.
                        </p>
                        <dl className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {[
                                ['Total rows', preview.rowCount],
                                ['New leads', preview.createdCount],
                                ['Duplicates', preview.duplicateCount],
                                ['Invalid rows', preview.invalidCount],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-xl border border-slate-200 px-3 py-2">
                                    <dt className="text-xs text-slate-500">{label}</dt>
                                    <dd className="text-lg font-semibold text-slate-950">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        {preview.canConfirm && (
                            <form
                                className="mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    confirm.post(`/leads/imports/${preview.id}/confirm`);
                                }}
                            >
                                <Button type="submit" disabled={confirm.processing}>
                                    {confirm.processing ? 'Importing…' : 'Confirm import'}
                                </Button>
                            </form>
                        )}
                        {preview.rawStatus === 'imported' && (
                            <p className="mt-3 text-sm text-slate-700">These leads are in the company pool. Team Lead and employee assignment is a later step.</p>
                        )}
                        {preview.truncated && <p className="mt-3 text-sm text-slate-500">Showing the first 150 rows. The counts above include the whole sheet.</p>}
                        <div className="mt-4 flex flex-wrap gap-4">
                            <Link href="/leads" className="text-sm text-blue-700">Back to leads</Link>
                            <Link href="/leads/import" className="text-sm text-blue-700">Upload another file</Link>
                        </div>
                    </SectionCard>
                    <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                        <table className="w-full min-w-[1100px] text-left text-sm">
                            <thead className="bg-blue-50 text-xs text-slate-600">
                                <tr>
                                    {['Row', 'Date', 'Name', 'Email', 'Phone', 'LinkedIn', 'State', 'University', 'Graduation term', 'Experience', 'Designations', 'Result'].map((heading) => (
                                        <th key={heading} className="px-3 py-3">{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {preview.rows.map((row) => (
                                    <tr key={row.line} className="border-t border-slate-100 align-top">
                                        <td className="px-3 py-3">{row.line}</td>
                                        <td className="px-3 py-3">{row.importedOn || '—'}</td>
                                        <td className="px-3 py-3">{row.name || '—'}</td>
                                        <td className="px-3 py-3">{row.email || '—'}</td>
                                        <td className="px-3 py-3">{row.phone || '—'}</td>
                                        <td className="px-3 py-3">{row.linkedin || '—'}</td>
                                        <td className="px-3 py-3">{row.state || '—'}</td>
                                        <td className="px-3 py-3">{row.university || '—'}</td>
                                        <td className="px-3 py-3">{row.graduationTerm || '—'}</td>
                                        <td className="px-3 py-3">{row.experience || '—'}</td>
                                        <td className="px-3 py-3">{row.designations || '—'}</td>
                                        <td className="px-3 py-3">
                                            <StatusBadge value={outcomeLabel[row.outcome]} />
                                            {row.reason && <p className="mt-1 text-slate-600">{row.reason}</p>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </HrmsLayout>
    );
}
