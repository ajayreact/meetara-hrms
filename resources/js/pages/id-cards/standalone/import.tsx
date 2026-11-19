import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { FileUpload } from '@/components/ui/file-upload';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';

interface PreviewRow {
    line: number;
    outcome: 'new' | 'duplicate' | 'invalid';
    reason: string | null;
    fullName: string;
    cardNumber: string;
    designation: string;
    department: string;
    email: string;
    phone: string;
    bloodGroup: string;
    emergencyContactNumber: string;
    country: string;
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
    failedCount: number;
    canConfirm: boolean;
    truncated: boolean;
    rows: PreviewRow[];
}

interface BatchSummary {
    id: number;
    fileName: string;
    status: string;
    rawStatus: string;
    uploadedBy: string | null;
    uploadedAt: string | null;
    rowCount: number;
    createdCount: number;
    duplicateCount: number;
    failedCount: number;
}

const outcomeLabel: Record<PreviewRow['outcome'], string> = {
    new: 'New',
    duplicate: 'Duplicate',
    invalid: 'Failed',
};

export default function StandaloneIdCardImportPage({
    workspace,
    preview = null,
    batches = [],
    notice,
    error,
}: HrmsPageProps & {
    preview?: Preview | null;
    batches?: BatchSummary[];
    notice?: string | null;
    error?: string | null;
}) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const confirm = useForm({});

    return (
        <HrmsLayout workspace={workspace} title="Standalone ID import">
            <PageIntro
                title="Standalone ID card import"
                subtitle="Upload an Excel workbook, preview validation and duplicates, then confirm to create draft cards without photos. Employee records are never touched."
                actions={(
                    <div className="flex flex-wrap gap-3 text-sm">
                        <a className="text-slate-500 underline" href={route('hrms.id-cards.standalone.import.template')}>Download template</a>
                        <Link className="text-slate-500 underline" href={route('hrms.id-cards.standalone')}>Manage cards</Link>
                        {workspace.permissions.idCardsIssue && (
                            <Link className="text-slate-500 underline" href={route('hrms.id-cards.standalone.bulk')}>Bulk issue</Link>
                        )}
                    </div>
                )}
            />
            {(notice || error) && (
                <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>
            )}

            <SectionCard title="Upload workbook">
                <form
                    className="grid gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        upload.post(route('hrms.id-cards.standalone.import.store'), { forceFormData: true });
                    }}
                >
                    <div className="grid gap-1 text-sm">
                        <Label htmlFor="standalone-id-file">Excel file</Label>
                        <FileUpload
                            id="standalone-id-file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            file={upload.data.file}
                            hint="Excel .xlsx workbook, up to the server upload limit."
                            error={upload.errors.file}
                            onChange={(file) => upload.setData('file', file)}
                        />
                    </div>
                    <p className="text-sm text-slate-600">
                        Use an .xlsx file. Sheet1 is imported. Card Number is optional and auto-generated when blank. Photos are added later on each draft card.
                    </p>
                    <Button type="submit" disabled={upload.processing || upload.data.file === null}>
                        {upload.processing ? 'Uploading…' : 'Upload and preview'}
                    </Button>
                </form>
            </SectionCard>

            {preview && (
                <div className="mt-4">
                    <SectionCard title={preview.fileName} extra={<StatusBadge value={preview.status} />}>
                        <p className="text-sm text-slate-600">
                            Uploaded by {preview.uploadedBy ?? '—'}
                            {preview.uploadedAt ? ` on ${preview.uploadedAt}` : ''}. Nothing is saved until you confirm. Invalid and duplicate rows are skipped.
                        </p>
                        <dl className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {[
                                ['Total rows', preview.rowCount],
                                ['Successful', preview.createdCount],
                                ['Duplicates', preview.duplicateCount],
                                ['Failed', preview.failedCount],
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
                                    confirm.post(route('hrms.id-cards.standalone.import.confirm', preview.id));
                                }}
                            >
                                <Button type="submit" disabled={confirm.processing}>
                                    {confirm.processing ? 'Importing…' : 'Confirm import'}
                                </Button>
                            </form>
                        )}
                        {preview.rawStatus === 'imported' && (
                            <p className="mt-3 text-sm text-slate-700">
                                Draft cards are ready under Standalone ID cards. Upload photos, then use bulk or single issue.
                            </p>
                        )}
                        {preview.truncated && <p className="mt-3 text-sm text-slate-500">Showing the first 150 rows. The counts above include the whole sheet.</p>}
                    </SectionCard>
                    <div className="mt-4">
                        <DataTable wide>
                            <thead>
                                <tr>
                                    {['Row', 'Full name', 'Card number', 'Designation', 'Department', 'Email', 'Phone', 'Blood', 'Emergency', 'Country', 'Result'].map((heading) => (
                                        <th key={heading} className={`${headCell} whitespace-nowrap`}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {preview.rows.map((row) => (
                                    <tr key={row.line} className={bodyRow}>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.line}</td>
                                        <td className={`${bodyCell} whitespace-nowrap font-medium`}>{row.fullName || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.cardNumber || 'Auto'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.designation || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.department || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.email || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.phone || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.bloodGroup || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>{row.emergencyContactNumber || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap uppercase`}>{row.country || '—'}</td>
                                        <td className={`${bodyCell} whitespace-nowrap`}>
                                            <StatusBadge value={outcomeLabel[row.outcome]} />
                                            {row.reason ? <span className="ml-2 text-slate-600">{row.reason}</span> : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </DataTable>
                    </div>
                </div>
            )}

            <div className="mt-4">
                <SectionCard title="Import batch history">
                    {batches.length === 0 ? (
                        <p className="text-sm text-slate-500">No import batches yet.</p>
                    ) : (
                        <div className="overflow-hidden rounded-xl border border-slate-200">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">File</th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2">Total</th>
                                        <th className="px-3 py-2">Successful</th>
                                        <th className="px-3 py-2">Failed</th>
                                        <th className="px-3 py-2">Duplicates</th>
                                        <th className="px-3 py-2">Uploaded</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {batches.map((batch) => (
                                        <tr key={batch.id} className="border-t border-slate-100">
                                            <td className="px-3 py-2">
                                                <Link className="font-medium text-blue-700 underline" href={route('hrms.id-cards.standalone.import.show', batch.id)}>
                                                    {batch.fileName}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2">{batch.status}</td>
                                            <td className="px-3 py-2">{batch.rowCount}</td>
                                            <td className="px-3 py-2">{batch.createdCount}</td>
                                            <td className="px-3 py-2">{batch.failedCount}</td>
                                            <td className="px-3 py-2">{batch.duplicateCount}</td>
                                            <td className="px-3 py-2 text-slate-500">{batch.uploadedBy ?? '—'}{batch.uploadedAt ? ` · ${batch.uploadedAt}` : ''}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}
