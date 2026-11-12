import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export interface LeadFollowUpTarget {
    id: number;
    name: string;
    status: string;
    followUpOn: string | null;
    remarks: string;
    retainsOnSave?: boolean;
}

export function LeadFollowUpDialog({
    open,
    lead,
    statuses,
    url,
    onClose,
}: {
    open: boolean;
    lead: LeadFollowUpTarget | null;
    statuses: { value: string; label: string }[];
    url: string;
    onClose: () => void;
}) {
    const form = useForm({
        pipeline_status: lead?.status ?? 'new',
        follow_up_on: lead?.followUpOn ?? '',
        remarks: lead?.remarks ?? '',
        return_to: typeof window !== 'undefined' ? `${window.location.pathname}${window.location.search}` : '',
    });

    useEffect(() => {
        if (!open || lead === null) {
            return;
        }

        form.setData({
            pipeline_status: lead.status,
            follow_up_on: lead.followUpOn ?? '',
            remarks: lead.remarks,
            return_to: `${window.location.pathname}${window.location.search}`,
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the opened lead changes
    }, [open, lead?.id]);

    function tomorrow(): string {
        const date = new Date();
        date.setDate(date.getDate() + 1);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    return (
        <Dialog open={open} onOpenChange={(next) => { if (!next) onClose(); }}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Update status</DialogTitle>
                    <DialogDescription>
                        {lead
                            ? (lead.retainsOnSave
                                ? `Save a status, next-call date, or remark for ${lead.name}. The first save retains this lead to you so you can keep working it.`
                                : `Save a status, next-call date, or remark for ${lead.name}. Ownership does not change.`)
                            : 'Save a follow-up for this lead.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (url === '') {
                            return;
                        }
                        form.post(url, {
                            preserveScroll: true,
                            onSuccess: () => onClose(),
                        });
                    }}
                >
                    <label className="grid gap-1 text-xs text-slate-500">
                        <Label htmlFor="lead-follow-up-status">Pipeline status</Label>
                        <select
                            id="lead-follow-up-status"
                            aria-label="Pipeline status"
                            className="h-10 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900"
                            value={form.data.pipeline_status}
                            onChange={(event) => form.setData('pipeline_status', event.target.value)}
                        >
                            {statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                        </select>
                        {form.errors.pipeline_status && <p className="text-sm text-rose-700">{form.errors.pipeline_status}</p>}
                    </label>
                    <div className="grid gap-1">
                        <Label htmlFor="lead-follow-up-date">Follow-up date</Label>
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                id="lead-follow-up-date"
                                type="date"
                                value={form.data.follow_up_on}
                                onChange={(event) => form.setData('follow_up_on', event.target.value)}
                            />
                            <Button type="button" variant="outline" size="sm" onClick={() => form.setData('follow_up_on', tomorrow())}>Tomorrow</Button>
                            <Button type="button" variant="ghost" size="sm" onClick={() => form.setData('follow_up_on', '')}>Clear</Button>
                        </div>
                        {form.errors.follow_up_on && <p className="text-sm text-rose-700">{form.errors.follow_up_on}</p>}
                    </div>
                    <label className="grid gap-1 text-xs text-slate-500">
                        <Label htmlFor="lead-follow-up-remarks">Remarks</Label>
                        <textarea
                            id="lead-follow-up-remarks"
                            aria-label="Remarks"
                            className="min-h-24 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                            value={form.data.remarks}
                            onChange={(event) => form.setData('remarks', event.target.value)}
                        />
                        {form.errors.remarks && <p className="text-sm text-rose-700">{form.errors.remarks}</p>}
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing || url === ''}>{form.processing ? 'Saving…' : 'Save follow-up'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function reminderLabel(reminder: string, followUpOn: string | null = null): string {
    if (followUpOn === null || followUpOn === '') {
        return '—';
    }

    if (reminder === 'overdue') {
        return `Overdue · ${followUpOn}`;
    }

    if (reminder === 'due_today') {
        return `Due today · ${followUpOn}`;
    }

    return `Call on ${followUpOn}`;
}

export function reminderTone(reminder: string): string {
    if (reminder === 'overdue') {
        return 'bg-rose-50 text-rose-700';
    }

    if (reminder === 'due_today') {
        return 'bg-amber-50 text-amber-800';
    }

    if (reminder === 'upcoming') {
        return 'bg-sky-50 text-sky-700';
    }

    return 'bg-slate-100 text-slate-500';
}

export function phoneLabel(phone: string): string {
    const compact = phone.replace(/\s+/g, '').trim();

    return compact === '' ? '—' : compact.replaceAll('-', '\u2011');
}
