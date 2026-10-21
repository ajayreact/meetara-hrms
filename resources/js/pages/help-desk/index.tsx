import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface TicketRow {
    id: number;
    title: string;
    category: string;
    priority: string;
    status: string;
    rawStatus: string;
    assignee?: string | null;
}

interface TicketDetail extends TicketRow {
    description: string | null;
    assigneeId: number | null;
    comments: { id: number; body: string; author?: string; at: string }[];
    history: { action: string; at: string }[];
}

interface Staff {
    id: number;
    name: string;
}

interface TicketFilters {
    q: string;
    status: string;
    priority: string;
    category: string;
}

export default function HelpDeskPage({ workspace, tickets = [], selected = null, canManage = false, canOpen = false, scoped = false, openBlocked = null, staff = [], filters = { q: '', status: '', priority: '', category: '' }, notice, error }: HrmsPageProps & {
    tickets?: TicketRow[];
    selected?: TicketDetail | null;
    canManage?: boolean;
    canOpen?: boolean;
    scoped?: boolean;
    openBlocked?: string | null;
    staff?: Staff[];
    filters?: TicketFilters;
    notice?: string | null;
    error?: string | null;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState(filters.q);
    const form = useForm({ category: 'Attendance', title: '', description: '', priority: 'medium' });
    const comment = useForm({ body: '' });
    const assign = useForm({ assignee_id: selected?.assigneeId ?? '' });
    const status = useForm({ status: '' });

    function apply(next: Partial<TicketFilters>) {
        const current = { ...filters, q: query, ...next };
        routerFilters(current, selected?.id);
    }

    return (
        <HrmsLayout workspace={workspace} title="Help Desk">
            <PageIntro title="Help Desk" subtitle={openBlocked && !scoped ? openBlocked : 'Tickets are saved for your company. Attachments are not accepted.'} />
            {(notice || error) && <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>}
            {scoped && (
                <>
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        {canOpen && <Button type="button" onClick={() => setOpen((value) => !value)}>{open ? 'Close form' : 'Create ticket'}</Button>}
                        {openBlocked && <p className="text-sm text-slate-500">{openBlocked}</p>}
                    </div>
                    {open && canOpen && (
                        <SectionCard title="New ticket" className="mb-4">
                            <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); form.post('/help-desk'); }}>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="grid gap-1.5 text-sm"><Label htmlFor="category">Category</Label>
                                        <select id="category" className="h-10 rounded-lg border border-input bg-white px-3" value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>
                                            {['Attendance', 'Leave', 'Payroll', 'Facilities', 'Other'].map((item) => <option key={item}>{item}</option>)}
                                        </select>
                                    </label>
                                    <label className="grid gap-1.5 text-sm"><Label htmlFor="priority">Priority</Label>
                                        <select id="priority" className="h-10 rounded-lg border border-input bg-white px-3" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                                            <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
                                        </select>
                                    </label>
                                </div>
                                <label className="grid gap-1.5 text-sm"><Label htmlFor="title">Subject</Label><Input id="title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} /><InputError message={form.errors.title} /></label>
                                <label className="grid gap-1.5 text-sm"><Label htmlFor="description">Description</Label><textarea id="description" className="min-h-24 rounded-lg border border-input px-3 py-2" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /><InputError message={form.errors.description} /></label>
                                <div><Button type="submit" disabled={form.processing}>{form.processing ? 'Working…' : 'Open ticket'}</Button></div>
                            </form>
                        </SectionCard>
                    )}
                    <form className="mb-4 grid gap-2 md:grid-cols-2 xl:grid-cols-5" onSubmit={(event) => { event.preventDefault(); apply({ q: query }); }}>
                        <Input aria-label="Search tickets" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Subject or ticket number" />
                        <select aria-label="Ticket status" className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={filters.status} onChange={(event) => apply({ status: event.target.value, q: query })}>
                            <option value="">All statuses</option>
                            <option value="open">Open</option>
                            <option value="in_progress">In progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <select aria-label="Ticket priority" className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={filters.priority} onChange={(event) => apply({ priority: event.target.value, q: query })}>
                            <option value="">All priorities</option>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                        <select aria-label="Ticket category" className="h-10 rounded-lg border border-input bg-white px-3 text-sm" value={filters.category} onChange={(event) => apply({ category: event.target.value, q: query })}>
                            <option value="">All categories</option>
                            {['Attendance', 'Leave', 'Payroll', 'Facilities', 'Other'].map((item) => <option key={item}>{item}</option>)}
                        </select>
                        <div className="flex gap-2">
                            <Button type="submit" variant="outline">Search</Button>
                            {(filters.q || filters.status || filters.priority || filters.category) && <Button type="button" variant="ghost" onClick={() => { setQuery(''); routerFilters({ q: '', status: '', priority: '', category: '' }, selected?.id); }}>Clear filters</Button>}
                        </div>
                    </form>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <SectionCard title="Tickets">
                            {tickets.length === 0 ? <p className="text-sm text-slate-500">{filters.q || filters.status || filters.priority || filters.category ? 'No tickets match these filters.' : 'No tickets yet.'}</p> : (
                                <ul className="divide-y divide-slate-100">
                                    {tickets.map((ticket) => (
                                        <li key={ticket.id}>
                                            <Link href={ticketHref(ticket.id, filters)} className={`flex items-center justify-between gap-3 px-2 py-3 text-sm ${selected?.id === ticket.id ? 'bg-blue-50' : 'hover:bg-slate-50'}`}>
                                                <span>
                                                    <span className="font-medium">{ticket.title}</span>
                                                    <span className="block text-xs text-slate-500">#{ticket.id} · {ticket.category}{ticket.assignee ? ` · ${ticket.assignee}` : ''}</span>
                                                </span>
                                                <span className="flex shrink-0 gap-1"><StatusBadge value={ticket.priority} /><StatusBadge value={ticket.status} /></span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                        <SectionCard title={selected ? selected.title : 'Ticket'}>
                            {selected === null ? <p className="text-sm text-slate-500">Select a ticket to see its description and history.</p> : (
                                <div className="space-y-3 text-sm">
                                    <div className="flex flex-wrap gap-2"><StatusBadge value={selected.status} /><StatusBadge value={selected.priority} /></div>
                                    <p className="text-slate-500">#{selected.id} · {selected.category}{selected.assignee ? ` · Assigned to ${selected.assignee}` : ''}</p>
                                    <p className="text-slate-700">{selected.description}</p>
                                    {canManage && staff.length > 0 && (
                                        <form className="flex flex-wrap gap-2" onSubmit={(event) => { event.preventDefault(); assign.post(`/help-desk/${selected.id}/assign`); }}>
                                            <select aria-label="Assignee" className="h-10 rounded-lg border border-input bg-white px-3" value={assign.data.assignee_id} onChange={(event) => assign.setData('assignee_id', Number(event.target.value))}>
                                                <option value="">Choose support staff</option>
                                                {staff.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                            </select>
                                            <Button type="submit" variant="outline" disabled={assign.processing}>Assign</Button>
                                        </form>
                                    )}
                                    {selected.rawStatus === 'open' && <Button type="button" variant="outline" disabled={status.processing} onClick={() => { status.transform(() => ({ status: 'cancelled' })); status.post(`/help-desk/${selected.id}/status`); }}>Cancel</Button>}
                                    {canManage && selected.rawStatus === 'open' && <Button type="button" disabled={status.processing} onClick={() => { status.transform(() => ({ status: 'in_progress' })); status.post(`/help-desk/${selected.id}/status`); }}>Start</Button>}
                                    {canManage && selected.rawStatus === 'in_progress' && <Button type="button" disabled={status.processing} onClick={() => { status.transform(() => ({ status: 'resolved' })); status.post(`/help-desk/${selected.id}/status`); }}>Resolve</Button>}
                                    <ul className="space-y-2">
                                        {selected.comments.length === 0 ? <li className="text-slate-500">No comments yet.</li> : selected.comments.map((item) => (
                                            <li key={item.id} className="rounded-xl bg-slate-50 px-3 py-2">
                                                <p>{item.body}</p>
                                                <p className="mt-1 text-xs text-slate-500">{item.author ?? 'Not recorded'} · {item.at}</p>
                                            </li>
                                        ))}
                                    </ul>
                                    <form className="grid gap-2" onSubmit={(event) => { event.preventDefault(); comment.post(`/help-desk/${selected.id}/comments`); }}>
                                        <textarea aria-label="Comment" className="min-h-20 rounded-lg border border-input px-3 py-2" value={comment.data.body} onChange={(event) => comment.setData('body', event.target.value)} />
                                        <InputError message={comment.errors.body} />
                                        <Button type="submit" disabled={comment.processing}>{comment.processing ? 'Working…' : 'Add comment'}</Button>
                                    </form>
                                    {selected.history.length > 0 && <ul className="text-xs text-slate-500">{selected.history.map((item) => <li key={`${item.action}-${item.at}`}>{item.action} · {item.at}</li>)}</ul>}
                                </div>
                            )}
                        </SectionCard>
                    </div>
                </>
            )}
        </HrmsLayout>
    );
}

function routerFilters(filters: TicketFilters, ticket?: number) {
    router.get('/help-desk', {
        q: filters.q || undefined,
        status: filters.status || undefined,
        priority: filters.priority || undefined,
        category: filters.category || undefined,
        ticket,
    }, { preserveState: true, preserveScroll: true });
}

function ticketHref(id: number, filters: TicketFilters): string {
    const params = new URLSearchParams({ ticket: String(id) });
    if (filters.q) params.set('q', filters.q);
    if (filters.status) params.set('status', filters.status);
    if (filters.priority) params.set('priority', filters.priority);
    if (filters.category) params.set('category', filters.category);

    return `/help-desk?${params.toString()}`;
}
