import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { OfficeMapPicker } from '@/components/hrms/office-map';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';

interface Verification {
    locationConfigured: boolean;
    networkConfigured: boolean;
    gpsEnabled: boolean;
    attendanceReady: boolean;
    methods: string[];
    message: string;
}

interface OfficeRecord {
    id: number;
    name: string;
    address: string | null;
    timezone: string;
    status: string;
    latitude: number | null;
    longitude: number | null;
    verificationRadiusMeters: number;
    gpsVerificationEnabled: boolean;
    verification: Verification;
    networks: { id: number; cidr: string }[];
}

interface Schedule {
    workStart: string;
    workEnd: string;
    graceMinutes: number;
    workingDays: string[];
}

interface OfficeFormData {
    name: string;
    address: string;
    timezone: string;
    status: string;
    latitude: string;
    longitude: string;
    verification_radius_meters: number;
    gps_verification_enabled: boolean;
}

const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

export default function OfficesPage({ workspace, offices, schedule, timezones, notice, locationFieldsAvailable }: HrmsPageProps & {
    offices: OfficeRecord[];
    schedule: Schedule;
    timezones: string[];
    notice?: string | null;
    locationFieldsAvailable: boolean;
}) {
    const officeForm = useForm<OfficeFormData>({
        name: '',
        address: '',
        timezone: timezones[0] ?? 'UTC',
        status: 'active',
        latitude: '',
        longitude: '',
        verification_radius_meters: 150,
        gps_verification_enabled: false,
    });
    const scheduleForm = useForm({
        work_start: schedule.workStart,
        work_end: schedule.workEnd,
        grace_minutes: schedule.graceMinutes,
        working_days: schedule.workingDays,
    });

    return (
        <HrmsLayout workspace={workspace} title="Offices">
            <PageIntro title="Offices and attendance" subtitle={`${workspace.companyName ?? 'Your company'} can keep more than one office. A browser cannot read the connected Wi-Fi name.`} />
            {notice && <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{notice}</p>}
            {!locationFieldsAvailable && (
                <p className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Location and GPS fields are visible here, but this database does not have the office location columns yet. Apply the office location migration before saving coordinates.
                </p>
            )}
            <SectionCard title="Working schedule">
                <form
                    className="grid gap-3 lg:grid-cols-[repeat(3,minmax(0,12rem))_1fr]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        scheduleForm.put(route('hrms.offices.schedule'));
                    }}
                >
                    <Field label="Work start" error={scheduleForm.errors.work_start}><Input type="time" value={scheduleForm.data.work_start} onChange={(event) => scheduleForm.setData('work_start', event.target.value)} /></Field>
                    <Field label="Work end" error={scheduleForm.errors.work_end}><Input type="time" value={scheduleForm.data.work_end} onChange={(event) => scheduleForm.setData('work_end', event.target.value)} /></Field>
                    <Field label="Grace minutes" error={scheduleForm.errors.grace_minutes}><Input type="number" min={0} max={120} value={scheduleForm.data.grace_minutes} onChange={(event) => scheduleForm.setData('grace_minutes', Number(event.target.value))} /></Field>
                    <div>
                        <p className="mb-2 text-sm">Working days</p>
                        <div className="flex flex-wrap gap-3 text-sm">
                            {days.map((day) => (
                                <label key={day} className="flex items-center gap-2 capitalize">
                                    <input
                                        type="checkbox"
                                        checked={scheduleForm.data.working_days.includes(day)}
                                        onChange={(event) => {
                                            const next = event.target.checked
                                                ? [...scheduleForm.data.working_days, day]
                                                : scheduleForm.data.working_days.filter((value) => value !== day);
                                            scheduleForm.setData('working_days', next);
                                        }}
                                    />
                                    {day}
                                </label>
                            ))}
                        </div>
                        {scheduleForm.errors.working_days && <p className="mt-1 text-xs text-rose-600">{scheduleForm.errors.working_days}</p>}
                    </div>
                    <div className="lg:col-span-4 flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={scheduleForm.processing}>Save schedule</Button>
                        <p className="text-xs text-slate-500">Hours and grace apply to the company shift. Each office keeps its own time zone.</p>
                    </div>
                </form>
            </SectionCard>
            <div className="mt-4 grid gap-4">
                {offices.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">No offices are recorded for this company yet.</p>
                ) : offices.map((office) => (
                    <OfficeCard key={`${office.id}-${office.latitude ?? ''}-${office.longitude ?? ''}-${office.verificationRadiusMeters}-${office.gpsVerificationEnabled}`} office={office} timezones={timezones} locationFieldsAvailable={locationFieldsAvailable} />
                ))}
            </div>
            <div className="mt-4">
                <SectionCard title="Add office">
                    <form
                        className="grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            officeForm.post(route('hrms.offices.store'), { onSuccess: () => officeForm.reset('name', 'address', 'latitude', 'longitude') });
                        }}
                    >
                        <OfficeFields form={officeForm} timezones={timezones} locationFieldsAvailable={locationFieldsAvailable} savedAddress={null} savedLatitude={null} savedLongitude={null} />
                        <div>
                            <Button type="submit" disabled={officeForm.processing}>Save office</Button>
                        </div>
                    </form>
                </SectionCard>
            </div>
        </HrmsLayout>
    );
}

function OfficeCard({ office, timezones, locationFieldsAvailable }: { office: OfficeRecord; timezones: string[]; locationFieldsAvailable: boolean }) {
    const form = useForm<OfficeFormData>({
        name: office.name,
        address: office.address ?? '',
        timezone: office.timezone,
        status: office.status,
        latitude: office.latitude === null ? '' : String(office.latitude),
        longitude: office.longitude === null ? '' : String(office.longitude),
        verification_radius_meters: office.verificationRadiusMeters,
        gps_verification_enabled: office.gpsVerificationEnabled,
    });
    const network = useForm({ cidr: '' });

    return (
        <SectionCard title={office.name} extra={<StatusBadge value={office.status === 'active' ? 'Active' : 'Inactive'} />}>
            <div className="mb-4 flex flex-wrap gap-2">
                <StatusBadge value={office.verification.locationConfigured ? 'Location configured' : 'Location not configured'} />
                <StatusBadge value={office.verification.networkConfigured ? 'Approved network configured' : 'Approved network missing'} />
                <StatusBadge value={office.verification.gpsEnabled ? 'GPS verification on' : 'GPS verification off'} />
                <StatusBadge value={office.verification.attendanceReady ? 'Attendance verification ready' : 'Attendance verification blocked'} />
            </div>
            <form
                className="grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(route('hrms.offices.update', office.id));
                }}
            >
                <OfficeFields
                    form={form}
                    timezones={timezones}
                    locationFieldsAvailable={locationFieldsAvailable}
                    savedAddress={office.address}
                    savedLatitude={office.latitude}
                    savedLongitude={office.longitude}
                    includeStatus
                    showGps={false}
                />
                <div>
                    <Button type="submit" variant="outline" disabled={form.processing}>Save office</Button>
                </div>
            </form>
            <div className="mt-4 grid gap-4 border-t border-slate-100 pt-4 xl:grid-cols-2">
                <div className="grid content-start gap-3">
                    <h3 className="text-sm font-semibold text-slate-800">Attendance verification</h3>
                    <p className={`rounded-lg px-3 py-2 text-sm ${office.verification.attendanceReady ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}>{office.verification.message}</p>
                    {office.verification.methods.length > 0 && <p className="text-sm text-slate-600">Enabled methods: {office.verification.methods.join('; ')}.</p>}
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            disabled={!locationFieldsAvailable}
                            checked={form.data.gps_verification_enabled}
                            onChange={(event) => form.setData('gps_verification_enabled', event.target.checked)}
                        />
                        <span>Require a browser location inside the radius, in addition to the approved public network. GPS by itself does not prove someone is in the office, and it does not replace the network check.</span>
                    </label>
                    {form.errors.gps_verification_enabled && <p className="text-xs text-rose-600">{form.errors.gps_verification_enabled}</p>}
                    <p className="text-xs text-slate-500">A website cannot read the connected Wi-Fi name. Approved public networks remain the network check.</p>
                    <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.put(route('hrms.offices.update', office.id))}>Save verification</Button>
                </div>
                <div className="grid content-start gap-3">
                <h3 className="text-sm font-semibold text-slate-800">Approved public networks</h3>
                <p className="text-xs text-slate-500">These are public IP addresses or CIDR ranges. A private address such as 192.168.1.10 is not the address the server can verify. Wi-Fi names are not collected.</p>
                {office.networks.length === 0 ? (
                    <p className="text-sm text-slate-500">No approved network is configured. Clock-in at this office is blocked.</p>
                ) : (
                    <ul className="space-y-2 text-sm">
                        {office.networks.map((item) => (
                            <li key={item.id} className="flex items-center justify-between gap-3">
                                <span className="font-mono">{item.cidr}</span>
                                <Button type="button" size="sm" variant="outline" onClick={() => router.delete(route('hrms.offices.networks.destroy', { office: office.id, network: item.id }))}>Remove</Button>
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    className="flex gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        network.post(route('hrms.offices.networks.store', office.id), { preserveScroll: true, onSuccess: () => network.reset() });
                    }}
                >
                    <Input value={network.data.cidr} onChange={(event) => network.setData('cidr', event.target.value)} placeholder="203.0.113.0/24" aria-label="Approved network" />
                    <Button type="submit" disabled={network.processing}>Add</Button>
                </form>
                {network.errors.cidr && <p className="text-xs text-rose-600">{network.errors.cidr}</p>}
                </div>
            </div>
        </SectionCard>
    );
}

function OfficeFields({
    form,
    timezones,
    locationFieldsAvailable,
    savedAddress,
    savedLatitude,
    savedLongitude,
    includeStatus = false,
    showGps = true,
}: {
    form: ReturnType<typeof useForm<OfficeFormData>>;
    timezones: string[];
    locationFieldsAvailable: boolean;
    savedAddress: string | null;
    savedLatitude: number | null;
    savedLongitude: number | null;
    includeStatus?: boolean;
    showGps?: boolean;
}) {
    return (
        <div className="grid gap-6 xl:grid-cols-2">
            <section className="grid content-start gap-3">
                <h3 className="text-sm font-semibold text-slate-800">Office details and address</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Office name" error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                    <Field label="Time zone" error={form.errors.timezone}>
                        <select className="h-10 rounded-md border border-input px-3 text-sm" value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} aria-label="Time zone">
                            {timezones.map((zone) => <option key={zone}>{zone}</option>)}
                        </select>
                    </Field>
                    {includeStatus && (
                        <Field label="Status" error={form.errors.status}>
                            <select className="h-10 rounded-md border border-input px-3 text-sm" value={form.data.status} aria-label="Status" onChange={(event) => form.setData('status', event.target.value)}>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </Field>
                    )}
                    <div className={includeStatus ? '' : 'sm:col-span-2'}>
                        <Field label="Physical office address" error={form.errors.address}><Input value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} /></Field>
                    </div>
                </div>
                <p className="text-sm text-slate-600">Saved address: {savedAddress || 'not configured'}.</p>
            </section>
            <section className="grid content-start gap-3">
                <h3 className="text-sm font-semibold text-slate-800">Map location and coordinates</h3>
                <p className="text-sm text-slate-600">Saved coordinates: {savedLatitude === null || savedLongitude === null ? 'not configured' : `${savedLatitude}, ${savedLongitude}`}.</p>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="Latitude" error={form.errors.latitude}>
                        <Input inputMode="decimal" disabled={!locationFieldsAvailable} value={form.data.latitude} onChange={(event) => form.setData('latitude', event.target.value)} placeholder="-90 to 90" />
                    </Field>
                    <Field label="Longitude" error={form.errors.longitude}>
                        <Input inputMode="decimal" disabled={!locationFieldsAvailable} value={form.data.longitude} onChange={(event) => form.setData('longitude', event.target.value)} placeholder="-180 to 180" />
                    </Field>
                    <Field label="Verification radius (meters)" error={form.errors.verification_radius_meters}>
                        <Input type="number" min={25} max={2000} disabled={!locationFieldsAvailable} value={form.data.verification_radius_meters} onChange={(event) => form.setData('verification_radius_meters', Number(event.target.value))} />
                    </Field>
                </div>
                <OfficeMapPicker
                    latitude={form.data.latitude}
                    longitude={form.data.longitude}
                    disabled={!locationFieldsAvailable}
                    onSelect={(latitude, longitude) => {
                        form.setData({ ...form.data, latitude, longitude });
                    }}
                />
                {showGps && (
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            disabled={!locationFieldsAvailable}
                            checked={form.data.gps_verification_enabled}
                            onChange={(event) => form.setData('gps_verification_enabled', event.target.checked)}
                        />
                        <span>Require a browser location inside the radius, in addition to the approved public network. GPS by itself does not prove someone is in the office, and it does not replace the network check. A website cannot read the Wi-Fi name.</span>
                    </label>
                )}
                {showGps && form.errors.gps_verification_enabled && <p className="text-xs text-rose-600">{form.errors.gps_verification_enabled}</p>}
            </section>
        </div>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <div className="grid gap-1.5 text-sm"><Label>{label}</Label>{children}{error && <span className="text-xs text-rose-600">{error}</span>}</div>;
}
