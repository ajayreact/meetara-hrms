import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';

interface HolidayRow {
    id: number;
    name: string;
    date: string;
    weekday: string;
    calendar: string;
    officeId: number | null;
    status: string;
}

interface OfficeOption {
    id: number;
    name: string;
}

export default function HolidaysPage({
    workspace,
    holidays = [],
    calendar = 'india',
    year = new Date().getFullYear(),
    canManage = false,
    offices = [],
    notice,
    error,
}: HrmsPageProps & {
    holidays?: HolidayRow[];
    calendar?: 'india' | 'usa';
    year?: number;
    canManage?: boolean;
    offices?: OfficeOption[];
    notice?: string | null;
    error?: string | null;
}) {
    const form = useForm({
        name: '',
        date: '',
        calendar,
        location_id: '' as number | '',
        status: 'active',
    });

    function filter(nextCalendar: string, nextYear: number) {
        router.get('/holidays', { calendar: nextCalendar, year: nextYear }, { preserveState: true, preserveScroll: true });
    }

    return (
        <HrmsLayout workspace={workspace} title="Holidays">
            <PageIntro title="Holidays" subtitle="India and United States calendars stay separate. A company-wide holiday applies to every office. An office holiday applies only to that office." />
            {(notice || error) && (
                <p className={`mb-4 rounded-lg px-3 py-2 text-sm ${error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}`}>{error ?? notice}</p>
            )}
            <div className={`grid gap-4 ${canManage ? 'xl:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)]' : ''}`}>
            <SectionCard
                title={calendar === 'usa' ? 'United States calendar' : 'India calendar'}
                extra={
                    <div className="flex gap-2">
                        <select aria-label="Holiday calendar" className="h-9 rounded-lg border border-slate-200 px-3 text-sm" value={calendar} onChange={(event) => filter(event.target.value, year)}>
                            <option value="usa">United States</option>
                            <option value="india">India</option>
                        </select>
                        <select aria-label="Holiday year" className="h-9 rounded-lg border border-slate-200 px-3 text-sm" value={year} onChange={(event) => filter(calendar, Number(event.target.value))}>
                            {[year - 1, year, year + 1].map((value) => (
                                <option key={value} value={value}>{value}</option>
                            ))}
                        </select>
                    </div>
                }
            >
                {holidays.length === 0 ? (
                    <p className="text-sm text-slate-500">No holidays are recorded for this calendar and year.</p>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {holidays.map((holiday) => (
                            <li key={holiday.id} className="py-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <span>
                                        <span className="block font-medium">{holiday.name}</span>
                                        <span className="text-xs text-slate-500">{holiday.officeId ? 'One office' : 'All offices'} · {holiday.calendar}</span>
                                    </span>
                                    <span className="flex items-center gap-3 text-slate-600">
                                        <span>{holiday.weekday}, {holiday.date}</span>
                                        <StatusBadge value={holiday.status === 'inactive' ? 'Inactive' : 'Active'} />
                                    </span>
                                </div>
                                {canManage && (
                                    <details className="mt-2">
                                        <summary className="cursor-pointer text-sm font-medium text-blue-700">Edit</summary>
                                        <HolidayEditor holiday={holiday} offices={offices} />
                                    </details>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            {canManage && (
                    <SectionCard title="Add a holiday">
                        <form
                            className="grid gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/holidays');
                            }}
                        >
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="holiday-name">Name</Label><Input id="holiday-name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></label>
                            <label className="grid gap-1.5 text-sm"><Label htmlFor="holiday-date">Date</Label><Input id="holiday-date" type="date" value={form.data.date} onChange={(event) => form.setData('date', event.target.value)} /></label>
                            <label className="grid gap-1.5 text-sm">
                                <Label htmlFor="holiday-calendar">Calendar</Label>
                                <select id="holiday-calendar" className="h-10 rounded-md border border-input px-3" value={form.data.calendar} onChange={(event) => form.setData('calendar', event.target.value as 'india' | 'usa')}>
                                    <option value="india">India</option>
                                    <option value="usa">United States</option>
                                </select>
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <Label htmlFor="holiday-office">Office</Label>
                                <select id="holiday-office" className="h-10 rounded-md border border-input px-3" value={form.data.location_id} onChange={(event) => form.setData('location_id', event.target.value === '' ? '' : Number(event.target.value))}>
                                    <option value="">All offices</option>
                                    {offices.map((office) => (
                                        <option key={office.id} value={office.id}>{office.name}</option>
                                    ))}
                                </select>
                            </label>
                            <div>
                                <Button type="submit" disabled={form.processing}>Add holiday</Button>
                            </div>
                        </form>
                    </SectionCard>
            )}
            </div>
        </HrmsLayout>
    );
}

function HolidayEditor({ holiday, offices }: { holiday: HolidayRow; offices: OfficeOption[] }) {
    const form = useForm({
        name: holiday.name,
        date: holiday.date,
        calendar: holiday.calendar === 'United States' ? 'usa' : 'india',
        location_id: holiday.officeId ?? ('' as number | ''),
        status: holiday.status,
    });

    return (
        <form
            className="mt-2 grid gap-2 sm:grid-cols-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(`/holidays/${holiday.id}`);
            }}
        >
            <Input aria-label={`Name for ${holiday.name}`} className="h-9 w-full" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
            <select aria-label={`Office for ${holiday.name}`} className="h-9 rounded-md border border-input px-2 text-sm" value={form.data.location_id ?? ''} onChange={(event) => form.setData('location_id', event.target.value === '' ? '' : Number(event.target.value))}>
                <option value="">All offices</option>
                {offices.map((office) => (
                    <option key={office.id} value={office.id}>{office.name}</option>
                ))}
            </select>
            <Button type="submit" variant="outline" disabled={form.processing}>Save</Button>
            <Button
                type="button"
                variant="outline"
                disabled={form.processing}
                onClick={() => { form.transform((data) => ({ ...data, status: holiday.status === 'inactive' ? 'active' : 'inactive' })); form.put(`/holidays/${holiday.id}`); }}
            >
                {holiday.status === 'inactive' ? 'Activate' : 'Deactivate'}
            </Button>
        </form>
    );
}
