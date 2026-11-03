import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export interface ReminderRow {
    id: number;
    candidateId: number;
    name: string;
    phone: string;
    when: string;
    notes: string;
    owner: string;
    status: string;
    bucket: string;
}

export interface TaskRow {
    id: number;
    title: string;
    description: string;
    assignee: string;
    priority: string;
    status: string;
    dueOn: string;
    remarks: string;
    overdue: boolean;
}

export interface WorkplacePayload {
    summary: {
        activeEmployees: number;
        newEmployees: number;
        pendingFollowUps: number;
        overdueReminders: number;
        tasksAssigned: number;
        tasksInProgress: number;
        tasksCompleted: number;
        tasksOverdue: number;
    } | null;
    tasks: TaskRow[];
    assignees: { id: number; name: string }[];
    canAssignTasks: boolean;
    reminders: { today: ReminderRow[]; upcoming: ReminderRow[]; overdue: ReminderRow[] };
    candidates: { id: number; name: string; email: string; phone: string; statusLabel: string; remarks: string; nextReminder: string | null; history: { action: string; note: string; at: string }[] }[];
    birthdays: { today: { id: number; name: string; initials: string; label: string }[]; upcoming: { id: number; name: string; initials: string; label: string }[] };
    announcements: { id: string; title: string; message: string; url: string; initials: string; read: boolean }[];
    notices: { unread: number; items: { id: string; title: string; message: string; url: string; read: boolean }[] };
}

export function WorkplacePanels({ workplace }: { workplace?: WorkplacePayload }) {
    const data = workplace ?? emptyWorkplace();
    const [openCandidate, setOpenCandidate] = useState<number | null>(null);
    const candidate = data.candidates.find((item) => item.id === openCandidate) ?? null;
    const taskForm = useForm({
        title: '',
        description: '',
        assignee_employee_id: data.assignees[0] ? String(data.assignees[0].id) : '',
        priority: 'medium',
        start_on: '',
        due_on: '',
        expected_on: '',
        remarks: '',
    });

    return (
        <div className="mt-4 grid gap-4 xl:grid-cols-2">
            {data.summary && (
                <SectionCard title="Team summary">
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <Summary label="Active employees" value={data.summary.activeEmployees} />
                        <Summary label="New in 30 days" value={data.summary.newEmployees} />
                        <Summary label="Pending follow-ups" value={data.summary.pendingFollowUps} />
                        <Summary label="Overdue reminders" value={data.summary.overdueReminders} />
                        <Summary label="Tasks assigned" value={data.summary.tasksAssigned} />
                        <Summary label="Tasks in progress" value={data.summary.tasksInProgress} />
                        <Summary label="Completed tasks" value={data.summary.tasksCompleted} />
                        <Summary label="Overdue tasks" value={data.summary.tasksOverdue} />
                    </dl>
                </SectionCard>
            )}
            <SectionCard title="Notifications" extra={<span className="text-sm text-slate-500">{data.notices.unread} unread</span>}>
                {data.notices.items.length === 0 ? <p className="text-sm text-slate-500">No notifications yet.</p> : (
                    <ul className="space-y-2 text-sm">
                        {data.notices.items.map((item) => (
                            <li key={item.id} className="rounded-lg border border-slate-100 px-3 py-2">
                                <p className="font-medium">{item.title}</p>
                                <p className="text-slate-600">{item.message}</p>
                                <div className="mt-1 flex gap-2">
                                    {item.url && <a className="text-blue-700 underline" href={item.url}>Open</a>}
                                    {!item.read && <button type="button" className="text-blue-700 underline" onClick={() => router.post(route('hrms.inbox.read', item.id))}>Mark read</button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            <SectionCard title="New employee announcements">
                {data.announcements.length === 0 ? <p className="text-sm text-slate-500">No welcome announcements yet.</p> : (
                    <ul className="space-y-3">
                        {data.announcements.map((item) => (
                            <li key={item.id} className="flex gap-3 text-sm">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">{item.initials || 'HR'}</span>
                                <span>
                                    <span className="block font-medium">{item.title}</span>
                                    <span className="text-slate-600">{item.message}</span>
                                    {item.url && <a className="mt-1 block text-blue-700 underline" href={item.url}>View profile</a>}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            <SectionCard title="Upcoming birthdays">
                {data.birthdays.today.length === 0 && data.birthdays.upcoming.length === 0 ? <p className="text-sm text-slate-500">No birthdays in the next 7 days.</p> : (
                    <ul className="space-y-2 text-sm">
                        {data.birthdays.today.map((person) => (
                            <li key={person.id} className="font-medium">{person.label} – {person.name} 🎂</li>
                        ))}
                        {data.birthdays.upcoming.map((person) => (
                            <li key={person.id}>{person.label} – {person.name}</li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            <ReminderList title="Today's reminders" rows={data.reminders.today} onOpen={setOpenCandidate} />
            <ReminderList title="Upcoming reminders" rows={data.reminders.upcoming} onOpen={setOpenCandidate} />
            <ReminderList title="Overdue follow-ups" rows={data.reminders.overdue} onOpen={setOpenCandidate} tone="text-rose-700" />
            <SectionCard title="My assigned tasks">
                {data.canAssignTasks && data.assignees.length > 0 && (
                    <form className="mb-4 grid gap-2 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); taskForm.post(route('hrms.work-tasks.store')); }}>
                        <Input placeholder="Task title" value={taskForm.data.title} onChange={(event) => taskForm.setData('title', event.target.value)} />
                        <select className="h-10 rounded-md border px-3 text-sm" value={taskForm.data.assignee_employee_id} onChange={(event) => taskForm.setData('assignee_employee_id', event.target.value)}>
                            {data.assignees.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                        </select>
                        <Input placeholder="Description" value={taskForm.data.description} onChange={(event) => taskForm.setData('description', event.target.value)} />
                        <select className="h-10 rounded-md border px-3 text-sm" value={taskForm.data.priority} onChange={(event) => taskForm.setData('priority', event.target.value)}>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                        <Input type="date" aria-label="Start date" value={taskForm.data.start_on} onChange={(event) => taskForm.setData('start_on', event.target.value)} />
                        <Input type="date" aria-label="Due date" value={taskForm.data.due_on} onChange={(event) => taskForm.setData('due_on', event.target.value)} />
                        <Input type="date" aria-label="Expected completion" value={taskForm.data.expected_on} onChange={(event) => taskForm.setData('expected_on', event.target.value)} />
                        <Input placeholder="Remarks" value={taskForm.data.remarks} onChange={(event) => taskForm.setData('remarks', event.target.value)} />
                        <Button type="submit" disabled={taskForm.processing}>Assign task</Button>
                    </form>
                )}
                {data.tasks.length === 0 ? <p className="text-sm text-slate-500">No tasks are assigned in this view.</p> : (
                    <ul className="space-y-3 text-sm">
                        {data.tasks.map((task) => <TaskItem key={task.id} task={task} />)}
                    </ul>
                )}
            </SectionCard>
            {candidate && (
                <SectionCard title={candidate.name}>
                    <p className="text-sm text-slate-600">{candidate.statusLabel} · {candidate.phone || 'No phone'} · {candidate.email}</p>
                    <p className="mt-2 text-sm">{candidate.remarks || 'No remarks.'}</p>
                    {candidate.nextReminder && <p className="mt-1 text-sm">Next reminder {candidate.nextReminder}</p>}
                    <ul className="mt-2 space-y-1 text-xs text-slate-500">
                        {candidate.history.map((event, index) => <li key={`${event.at}-${index}`}>{event.note} · {event.at}</li>)}
                    </ul>
                </SectionCard>
            )}
        </div>
    );
}

function ReminderList({ title, rows, onOpen, tone = 'text-slate-800' }: { title: string; rows: ReminderRow[]; onOpen: (id: number) => void; tone?: string }) {
    return (
        <SectionCard title={title} extra={<span className="text-sm text-slate-400">{rows.length}</span>}>
            {rows.length === 0 ? <p className="text-sm text-slate-500">Nothing in this list.</p> : (
                <ul className="space-y-3 text-sm">
                    {rows.map((row) => <ReminderItem key={row.id} row={row} tone={tone} onOpen={onOpen} />)}
                </ul>
            )}
        </SectionCard>
    );
}

function ReminderItem({ row, tone, onOpen }: { row: ReminderRow; tone: string; onOpen: (id: number) => void }) {
    const [when, setWhen] = useState(row.when.replace(' ', 'T').slice(0, 16));

    return (
        <li className="rounded-lg border border-slate-100 px-3 py-2">
            <p className={`font-medium ${tone}`}>{row.name}</p>
            <p className="text-slate-600">{row.phone || 'No phone'} · {row.when} · {row.owner}</p>
            <p className="text-slate-600">{row.notes}</p>
            <p className="text-xs text-slate-500">{row.status}</p>
            <div className="mt-2 flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="outline" onClick={() => onOpen(row.candidateId)}>Candidate details</Button>
                <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.follow-ups.complete', row.id))}>Complete</Button>
                <Input className="h-8 w-44" type="datetime-local" value={when} onChange={(event) => setWhen(event.target.value)} />
                <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.follow-ups.reschedule', row.id), { remind_at: when, notes: row.notes })}>Reschedule</Button>
            </div>
        </li>
    );
}

function TaskItem({ task }: { task: TaskRow }) {
    const [status, setStatus] = useState(task.status);
    const [note, setNote] = useState('');

    return (
        <li className="rounded-lg border border-slate-100 px-3 py-2">
            <div className="flex items-center justify-between gap-2">
                <span className="font-medium">{task.title}</span>
                <StatusBadge value={task.overdue ? 'Overdue' : task.status.replaceAll('_', ' ')} />
            </div>
            <p className="text-slate-600">{task.assignee} · {task.priority}{task.dueOn ? ` · due ${task.dueOn}` : ''}</p>
            <div className="mt-2 flex flex-wrap gap-2">
                <select className="h-8 rounded-md border px-2 text-sm" value={status} onChange={(event) => setStatus(event.target.value)}>
                    <option value="assigned">Assigned</option>
                    <option value="in_progress">In Progress</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <Input className="h-8 w-48" placeholder="Progress note" value={note} onChange={(event) => setNote(event.target.value)} />
                <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('hrms.work-tasks.progress', task.id), { status, note })}>Update</Button>
            </div>
        </li>
    );
}

function Summary({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-semibold">{value}</dd>
        </div>
    );
}

function emptyWorkplace(): WorkplacePayload {
    return {
        summary: null,
        tasks: [],
        assignees: [],
        canAssignTasks: false,
        reminders: { today: [], upcoming: [], overdue: [] },
        candidates: [],
        birthdays: { today: [], upcoming: [] },
        announcements: [],
        notices: { unread: 0, items: [] },
    };
}
