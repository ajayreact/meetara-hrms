import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface Candidate {
    employeeId: number;
    cardId: number | null;
    code: string;
    name: string;
    department: string;
    designation: string;
    country: string;
    status: string | null;
    eligible: boolean;
    state: string;
    outcome?: string;
    message: string;
    blockers: string[];
    pdfUrl?: string | null;
}

interface BulkResult {
    mode: 'preview' | 'issue';
    format: string | null;
    successCount: number;
    failureCount: number;
    skippedCount: number;
    downloadUrl: string | null;
    rows: Candidate[];
    eligibleCount?: number;
    message: string;
}

const field = 'mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100';

export default function IdCardBulk({
    workspace,
    candidates = [],
    formats = [],
    summary,
    result = null,
    notice,
}: HrmsPageProps & {
    candidates?: Candidate[];
    formats?: { value: string; label: string }[];
    summary: { total: number; eligible: number; notReady: number; alreadyIssued: number };
    result?: BulkResult | null;
    notice?: string;
}) {
    const [selected, setSelected] = useState<number[]>([]);
    const [filter, setFilter] = useState<'all' | 'eligible' | 'not_ready' | 'already_issued'>('all');
    const form = useForm({
        employee_ids: [] as number[],
        format: formats[0]?.value ?? 'zip',
    });

    const visible = useMemo(() => {
        if (filter === 'all') {
            return candidates;
        }

        if (filter === 'eligible') {
            return candidates.filter((row) => row.eligible);
        }

        return candidates.filter((row) => row.state === filter);
    }, [candidates, filter]);

    const selectedRows = useMemo(
        () => candidates.filter((row) => selected.includes(row.employeeId)),
        [candidates, selected],
    );

    const selectedEligible = selectedRows.filter((row) => row.eligible).length;
    const selectedNotReady = selectedRows.filter((row) => row.state === 'not_ready').length;
    const selectedSkipped = selectedRows.filter((row) => row.state === 'already_issued' || row.state === 'missing').length;

    function toggle(id: number) {
        setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    }

    function toggleVisible() {
        const ids = visible.map((row) => row.employeeId);
        const allSelected = ids.length > 0 && ids.every((id) => selected.includes(id));
        setSelected((current) => (allSelected
            ? current.filter((id) => !ids.includes(id))
            : Array.from(new Set([...current, ...ids]))));
    }

    function selectEligible() {
        setSelected(candidates.filter((row) => row.eligible).map((row) => row.employeeId));
    }

    function preview() {
        form.transform(() => ({ employee_ids: selected }));
        form.post(route('hrms.id-cards.bulk.preview'));
    }

    function issue() {
        form.transform(() => ({ employee_ids: selected, format: form.data.format }));
        form.post(route('hrms.id-cards.bulk.issue'));
    }

    return (
        <HrmsLayout workspace={workspace} title="Bulk ID cards">
            <PageIntro
                title="Bulk ID card issuing"
                subtitle="Select employees with completed card details, preview readiness, then issue eligible cards as a ZIP or one combined PDF."
                actions={<Link className="text-sm text-slate-500 underline" href={route('hrms.id-cards')}>Single card</Link>}
            />
            {notice && <p className="mb-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}

            <div className="mb-4 grid gap-3 sm:grid-cols-4">
                {[
                    ['Candidates', summary.total],
                    ['Eligible', summary.eligible],
                    ['Not ready', summary.notReady],
                    ['Already issued', summary.alreadyIssued],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                        <p className="text-xs text-slate-400">{label}</p>
                        <p className="mt-1 text-lg font-semibold text-slate-950">{value}</p>
                    </div>
                ))}
            </div>

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)]">
                <SectionCard title="Employees">
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={toggleVisible}>Toggle visible</Button>
                        <Button type="button" variant="outline" size="sm" onClick={selectEligible}>Select all eligible</Button>
                        <Button type="button" variant="outline" size="sm" onClick={() => setSelected([])}>Clear</Button>
                        <select className={`${field} !mt-0 max-w-[12rem]`} value={filter} onChange={(event) => setFilter(event.target.value as typeof filter)}>
                            <option value="all">All</option>
                            <option value="eligible">Eligible</option>
                            <option value="not_ready">Not ready</option>
                            <option value="already_issued">Already issued</option>
                        </select>
                    </div>

                    <div className="overflow-hidden rounded-xl border border-slate-200">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">
                                        <input
                                            type="checkbox"
                                            checked={visible.length > 0 && visible.every((row) => selected.includes(row.employeeId))}
                                            onChange={toggleVisible}
                                            aria-label="Select visible employees"
                                        />
                                    </th>
                                    <th className="px-3 py-2">Employee</th>
                                    <th className="px-3 py-2">Country</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2">Readiness</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-3 py-4 text-slate-500">No employees match this filter.</td>
                                    </tr>
                                )}
                                {visible.map((row) => (
                                    <tr key={row.employeeId} className="border-t border-slate-100 align-top">
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(row.employeeId)}
                                                onChange={() => toggle(row.employeeId)}
                                                aria-label={`Select ${row.name}`}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <p className="font-medium text-slate-950">{row.name}</p>
                                            <p className="text-xs text-slate-500">{row.code} · {row.designation || '—'} · {row.department || '—'}</p>
                                        </td>
                                        <td className="px-3 py-2 uppercase text-slate-600">{row.country || '—'}</td>
                                        <td className="px-3 py-2 text-slate-600">{row.status || 'none'}</td>
                                        <td className="px-3 py-2">
                                            <p className={row.eligible ? 'text-emerald-700' : 'text-amber-800'}>{row.message}</p>
                                            {row.blockers.length > 1 && (
                                                <ul className="mt-1 list-disc pl-4 text-xs text-slate-500">
                                                    {row.blockers.slice(1).map((blocker) => <li key={blocker}>{blocker}</li>)}
                                                </ul>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                <div className="grid gap-4">
                    <SectionCard title="Selection preview">
                        <p className="text-sm text-slate-600">
                            {selected.length} selected · {selectedEligible} eligible · {selectedNotReady} not ready · {selectedSkipped} skipped
                        </p>
                        {selectedRows.length > 0 && (
                            <ul className="mt-3 max-h-56 space-y-2 overflow-auto text-sm">
                                {selectedRows.map((row) => (
                                    <li key={row.employeeId} className="rounded-lg border border-slate-100 px-3 py-2">
                                        <p className="font-medium text-slate-950">{row.name}</p>
                                        <p className={row.eligible ? 'text-emerald-700' : 'text-amber-800'}>{row.message}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="mt-4 grid gap-3">
                            <label className="grid gap-1 text-xs text-slate-500">
                                Output format
                                <select className={field} value={form.data.format} onChange={(event) => form.setData('format', event.target.value)}>
                                    {formats.map((item) => (
                                        <option key={item.value} value={item.value}>{item.label}</option>
                                    ))}
                                </select>
                                {form.errors.format && <span className="text-rose-700">{form.errors.format}</span>}
                            </label>
                            {form.errors.employee_ids && <p className="text-sm text-rose-700">{form.errors.employee_ids}</p>}
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" disabled={selected.length === 0 || form.processing} onClick={preview}>
                                    Preview selection
                                </Button>
                                <Button type="button" disabled={selected.length === 0 || form.processing} onClick={issue}>
                                    Issue selected
                                </Button>
                            </div>
                        </div>
                    </SectionCard>

                    {result && (
                        <SectionCard title={result.mode === 'preview' ? 'Preview result' : 'Issue result'}>
                            <p className="text-sm text-slate-700">{result.message}</p>
                            {result.mode === 'issue' && (
                                <p className="mt-2 text-sm text-slate-600">
                                    Success {result.successCount} · Failed {result.failureCount} · Skipped {result.skippedCount}
                                </p>
                            )}
                            {result.downloadUrl && (
                                <a
                                    className="mt-3 inline-flex h-9 items-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-900 hover:bg-slate-50"
                                    href={result.downloadUrl}
                                >
                                    Download {result.format === 'combined' ? 'combined PDF' : 'ZIP'}
                                </a>
                            )}
                            <ul className="mt-3 max-h-72 space-y-2 overflow-auto text-sm">
                                {result.rows.map((row) => (
                                    <li key={`${row.employeeId}-${row.outcome ?? row.state}`} className="rounded-lg border border-slate-100 px-3 py-2">
                                        <p className="font-medium text-slate-950">{row.name || row.code || `Employee ${row.employeeId}`}</p>
                                        <p className="text-slate-600">{row.message}</p>
                                        {row.pdfUrl && result.mode === 'issue' && row.outcome === 'issued' && (
                                            <a className="mt-1 inline-block text-xs text-blue-700 underline" href={row.pdfUrl} target="_blank" rel="noreferrer">
                                                Open PDF
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    )}
                </div>
            </div>
        </HrmsLayout>
    );
}
