import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { OfficeMapPicker, type MapTiles } from '@/components/hrms/office-map';
import { StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { indiaToday, nextDate, zoneTimes, type ZoneTime } from '@/lib/shift-times';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';
import { Building2, CalendarDays, CircleCheck, MapPin, Pencil, Plus, ShieldCheck, Wifi } from 'lucide-react';
import { useState } from 'react';

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
    mapLabel: string | null;
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
    map_label: string;
    latitude: string;
    longitude: string;
    verification_radius_meters: number;
    gps_verification_enabled: boolean;
}

const days = [
    ['monday', 'Monday'],
    ['tuesday', 'Tuesday'],
    ['wednesday', 'Wednesday'],
    ['thursday', 'Thursday'],
    ['friday', 'Friday'],
    ['saturday', 'Saturday'],
    ['sunday', 'Sunday'],
] as const;

const decimalPattern = /^-?\d+(\.\d+)?$/;

function blankOffice(timezone: string): OfficeFormData {
    return {
        name: '',
        address: '',
        timezone,
        status: 'active',
        map_label: '',
        latitude: '',
        longitude: '',
        verification_radius_meters: 150,
        gps_verification_enabled: false,
    };
}

function officeFormData(office: OfficeRecord): OfficeFormData {
    return {
        name: office.name,
        address: office.address ?? '',
        timezone: office.timezone,
        status: office.status,
        map_label: office.mapLabel ?? '',
        latitude: office.latitude === null ? '' : String(office.latitude),
        longitude: office.longitude === null ? '' : String(office.longitude),
        verification_radius_meters: office.verificationRadiusMeters,
        gps_verification_enabled: office.gpsVerificationEnabled,
    };
}

function coordinateSentence(latitude: number | null, longitude: number | null): string {
    return latitude === null || longitude === null
        ? 'Saved coordinates: not configured. Route Map lists this office as Not on map.'
        : `Saved coordinates: ${latitude}, ${longitude}.`;
}

function locationErrors(data: OfficeFormData): Partial<Record<keyof OfficeFormData, string>> {
    const errors: Partial<Record<keyof OfficeFormData, string>> = {};
    const latitude = data.latitude.trim();
    const longitude = data.longitude.trim();

    if (latitude === '' && longitude !== '') {
        errors.latitude = 'Enter a latitude with the longitude, or clear both.';
    } else if (latitude !== '' && (!decimalPattern.test(latitude) || Math.abs(Number(latitude)) > 90)) {
        errors.latitude = 'Latitude must be a number from -90 to 90.';
    }

    if (longitude === '' && latitude !== '') {
        errors.longitude = 'Enter a longitude with the latitude, or clear both.';
    } else if (longitude !== '' && (!decimalPattern.test(longitude) || Math.abs(Number(longitude)) > 180)) {
        errors.longitude = 'Longitude must be a number from -180 to 180.';
    }

    const radius = data.verification_radius_meters;
    if (!Number.isInteger(radius) || radius < 25 || radius > 2000) {
        errors.verification_radius_meters = 'Radius must be a whole number from 25 to 2000 meters.';
    }

    if (data.map_label.length > 120) {
        errors.map_label = 'Map display name must be 120 characters or fewer.';
    }

    return errors;
}

function checkLocation(form: ReturnType<typeof useForm<OfficeFormData>>): boolean {
    const errors = locationErrors(form.data);
    form.clearErrors('latitude', 'longitude', 'verification_radius_meters', 'map_label');

    if (Object.keys(errors).length > 0) {
        form.setError(errors);
        return false;
    }

    return true;
}

interface DetectedAddress {
    ip: string | null;
    approvable: boolean;
    message: string;
}

export default function OfficesPage({ workspace, offices, schedule, timezones, notice, locationFieldsAvailable, mapLabelAvailable = false, mapTiles, detectedAddress }: HrmsPageProps & {
    offices: OfficeRecord[];
    schedule: Schedule;
    timezones: string[];
    notice?: string | null;
    locationFieldsAvailable: boolean;
    mapLabelAvailable?: boolean;
    mapTiles?: MapTiles;
    detectedAddress: DetectedAddress;
}) {
    const defaultTimezone = timezones[0] ?? 'UTC';
    const officeForm = useForm<OfficeFormData>(blankOffice(defaultTimezone));
    const scheduleForm = useForm({
        work_start: schedule.workStart,
        work_end: schedule.workEnd,
        grace_minutes: schedule.graceMinutes,
        working_days: schedule.workingDays,
    });
    const [previewDate, setPreviewDate] = useState(indiaToday);
    const [adding, setAdding] = useState(false);
    const overnight = scheduleForm.data.work_end !== '' && scheduleForm.data.work_start !== '' && scheduleForm.data.work_end < scheduleForm.data.work_start;
    const endDate = overnight ? nextDate(previewDate) : previewDate;

    function closeAddForm() {
        officeForm.reset();
        officeForm.clearErrors();
        setAdding(false);
    }

    return (
        <HrmsLayout workspace={workspace} title="Offices">
            <p className="mb-3 text-sm text-slate-500">
                <a href="/dashboard" className="hover:text-slate-800">Home</a>
                <span className="px-1.5 text-slate-300">/</span>
                Offices
            </p>
            <PageIntro title="Offices & attendance" subtitle="Manage office locations, work schedules, and attendance verification." />
            {notice && <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{notice}</p>}
            {!locationFieldsAvailable && (
                <p className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Location and GPS fields are visible here, but this database does not have the office location columns yet. Apply the office location migration before saving coordinates.
                </p>
            )}
            <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        scheduleForm.clearErrors('work_end');
                        if (scheduleForm.data.work_start !== '' && scheduleForm.data.work_start === scheduleForm.data.work_end) {
                            scheduleForm.setError('work_end', 'The end time must be different from the start time.');
                            return;
                        }
                        scheduleForm.put(route('hrms.offices.schedule'));
                    }}
                >
                    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><CalendarDays className="size-4" aria-hidden="true" /></span>
                            <div>
                                <h2 className="text-[15px] font-semibold text-slate-950">Working schedule</h2>
                                <p className="mt-0.5 text-sm text-slate-500">Set your company&apos;s working hours and schedule. Each office uses its own time zone.</p>
                            </div>
                        </div>
                        <Button type="submit" disabled={scheduleForm.processing}>Save schedule</Button>
                    </div>
                        <div className="grid items-end gap-3 lg:grid-cols-[repeat(3,minmax(0,11rem))_minmax(0,1fr)]">
                        <Field label="Work start (India time)" error={scheduleForm.errors.work_start}><Input type="time" value={scheduleForm.data.work_start} onChange={(event) => scheduleForm.setData('work_start', event.target.value)} /></Field>
                        <Field label="Work end (India time)" error={scheduleForm.errors.work_end}><Input type="time" value={scheduleForm.data.work_end} onChange={(event) => scheduleForm.setData('work_end', event.target.value)} /></Field>
                        <Field label="Grace minutes" error={scheduleForm.errors.grace_minutes}><Input type="number" min={0} max={120} value={scheduleForm.data.grace_minutes} onChange={(event) => scheduleForm.setData('grace_minutes', Number(event.target.value))} /></Field>
                        <div>
                            <p className="mb-2 text-sm font-medium">Working days</p>
                            <div className="flex flex-wrap gap-x-3 gap-y-2 text-sm">
                                {days.map(([day, label]) => (
                                    <label key={day} className="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            aria-label={day}
                                            checked={scheduleForm.data.working_days.includes(day)}
                                            onChange={(event) => {
                                                const next = event.target.checked
                                                    ? [...scheduleForm.data.working_days, day]
                                                    : scheduleForm.data.working_days.filter((value) => value !== day);
                                                scheduleForm.setData('working_days', next);
                                            }}
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            {scheduleForm.errors.working_days && <p className="mt-1 text-xs text-rose-600">{scheduleForm.errors.working_days}</p>}
                        </div>
                    </div>
                    <div className="mt-4 grid gap-3 lg:grid-cols-3">
                        <ZonePreview title="Start in the United States (Preview for selected date)" date={previewDate} time={scheduleForm.data.work_start} />
                        <ZonePreview title={overnight ? 'End in the United States (Next India day)' : 'End in the United States'} date={endDate} time={scheduleForm.data.work_end} />
                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Preview US times for date.</span>
                                <Input type="date" value={previewDate} onChange={(event) => setPreviewDate(event.target.value)} />
                            </label>
                            {overnight && <p className="mt-2 text-sm text-slate-600">The end time is earlier than the start time, so it is saved as the following day in India.</p>}
                            <p className="mt-2 text-xs leading-5 text-slate-500">US times automatically adjust for daylight saving time. Converted times are a preview for the selected date.</p>
                        </div>
                    </div>
                    <p className="mt-3 text-xs text-slate-500">Hours are saved in India time and applied in each office time zone. An India office uses Asia/Kolkata.</p>
                </form>
            </section>
            <div className="mt-4 grid gap-4">
                {offices.length === 0 && <p className="rounded-xl border border-dashed border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">No offices are recorded for this company yet.</p>}
                {offices.map((office) => (
                    <OfficeCard key={`${office.id}-${office.latitude ?? ''}-${office.longitude ?? ''}-${office.mapLabel ?? ''}-${office.verificationRadiusMeters}-${office.gpsVerificationEnabled}`} office={office} timezones={timezones} locationFieldsAvailable={locationFieldsAvailable} mapLabelAvailable={mapLabelAvailable} mapTiles={mapTiles} detectedAddress={detectedAddress} />
                ))}
            </div>
            <section className="mt-4 rounded-2xl border border-dashed border-slate-300 bg-white p-4 shadow-sm sm:p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500"><Plus className="size-4" aria-hidden="true" /></span>
                        <div>
                            <h2 className="text-[15px] font-semibold text-slate-950">Add another office</h2>
                            <p className="mt-0.5 text-sm text-slate-500">Create a location with its own schedule and attendance verification settings.</p>
                        </div>
                    </div>
                    {!adding && <Button type="button" onClick={() => setAdding(true)}><Plus className="size-4" aria-hidden="true" />Add office</Button>}
                </div>
                {adding && (
                    <form
                        className="mt-5 grid gap-4 border-t border-slate-100 pt-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (!checkLocation(officeForm)) {
                                return;
                            }
                            officeForm.post(route('hrms.offices.store'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    officeForm.reset();
                                    officeForm.clearErrors();
                                    setAdding(false);
                                },
                            });
                        }}
                    >
                        <OfficeFields form={officeForm} timezones={timezones} locationFieldsAvailable={locationFieldsAvailable} mapLabelAvailable={mapLabelAvailable} mapTiles={mapTiles} savedAddress={null} savedLatitude={null} savedLongitude={null} />
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={officeForm.processing}>Save office</Button>
                            <Button type="button" variant="outline" disabled={officeForm.processing} onClick={closeAddForm}>Cancel</Button>
                        </div>
                    </form>
                )}
            </section>
        </HrmsLayout>
    );
}

function StatusChip({ icon, label, tone }: { icon: React.ReactNode; label: string; tone: 'green' | 'muted' | 'rose' }) {
    const colors = {
        green: 'bg-emerald-50 text-emerald-700',
        muted: 'bg-slate-100 text-slate-600',
        rose: 'bg-rose-50 text-rose-700',
    };

    return <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${colors[tone]}`}>{icon}{label}</span>;
}

function OfficeCard({ office, timezones, locationFieldsAvailable, mapLabelAvailable, mapTiles, detectedAddress }: { office: OfficeRecord; timezones: string[]; locationFieldsAvailable: boolean; mapLabelAvailable: boolean; mapTiles?: MapTiles; detectedAddress: DetectedAddress }) {
    const form = useForm<OfficeFormData>(officeFormData(office));
    const network = useForm({ cidr: '' });
    const detected = useForm({ confirm_detected: false });
    const [editing, setEditing] = useState(true);
    const savedCoordinates = coordinateSentence(office.latitude, office.longitude);
    const unsavedSelection = form.data.latitude !== (office.latitude === null ? '' : String(office.latitude)) || form.data.longitude !== (office.longitude === null ? '' : String(office.longitude));

    function cancelEdit() {
        form.setData(officeFormData(office));
        form.clearErrors();
        setEditing(false);
    }

    function saveOffice() {
        if (checkLocation(form)) {
            form.put(route('hrms.offices.update', office.id), { preserveScroll: true });
        }
    }

    function addNetwork() {
        network.post(route('hrms.offices.networks.store', office.id), { preserveScroll: true, onSuccess: () => network.reset() });
    }

    function approveDetected() {
        detected.post(route('hrms.offices.networks.detected', office.id), { preserveScroll: true, onSuccess: () => detected.reset() });
    }

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600"><Building2 className="size-4" aria-hidden="true" /></span>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-base font-semibold text-slate-950">{office.name}</h2>
                            <StatusBadge value={office.status === 'active' ? 'Active' : 'Inactive'} />
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <StatusChip icon={<MapPin className="size-3.5" aria-hidden="true" />} label={office.verification.locationConfigured ? 'Location configured' : 'Location not configured'} tone={office.verification.locationConfigured ? 'green' : 'muted'} />
                            <StatusChip icon={<Wifi className="size-3.5" aria-hidden="true" />} label={office.verification.networkConfigured ? 'Approved network configured' : 'Approved network missing'} tone={office.verification.networkConfigured ? 'green' : 'rose'} />
                            <StatusChip icon={<MapPin className="size-3.5" aria-hidden="true" />} label={office.verification.gpsEnabled ? 'GPS verification on' : 'GPS verification off'} tone="muted" />
                            <StatusChip icon={<CircleCheck className="size-3.5" aria-hidden="true" />} label={office.verification.attendanceReady ? 'Attendance verification ready' : 'Attendance verification blocked'} tone={office.verification.attendanceReady ? 'green' : 'rose'} />
                        </div>
                    </div>
                </div>
                <Button type="button" variant="outline" aria-expanded={editing} onClick={() => setEditing(true)}><Pencil className="size-4" aria-hidden="true" />Edit office</Button>
            </div>
            {!editing && (
            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-slate-500">Address</dt>
                    <dd className="mt-0.5 font-medium text-slate-800">{office.address || 'Not recorded'}</dd>
                </div>
                <div>
                    <dt className="text-slate-500">Time zone</dt>
                    <dd className="mt-0.5 font-medium text-slate-800">{office.timezone}</dd>
                </div>
                <div className="sm:col-span-2">
                    <dt className="text-slate-500">Attendance verification</dt>
                    <dd className={`mt-1 rounded-lg px-3 py-2 ${office.verification.attendanceReady ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}>{office.verification.message}</dd>
                </div>
                <div className="sm:col-span-2">
                    <dt className="text-slate-500">Map</dt>
                    <dd className="mt-0.5 text-slate-700">{savedCoordinates}</dd>
                </div>
                {office.networks.length > 0 && (
                    <div className="sm:col-span-2">
                        <dt className="text-slate-500">Approved public networks</dt>
                        <dd className="mt-1 flex flex-wrap gap-2">{office.networks.map((item) => <span key={item.id} className="rounded-md bg-slate-50 px-2 py-1 font-mono text-xs text-slate-700">{item.cidr}</span>)}</dd>
                    </div>
                )}
            </dl>
            )}
            {editing && (
                <div className="mt-4 border-t border-slate-100 pt-4">
                    <form
                        className="grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            saveOffice();
                        }}
                    >
                        <OfficeFields
                            form={form}
                            timezones={timezones}
                            locationFieldsAvailable={locationFieldsAvailable}
                            mapLabelAvailable={mapLabelAvailable}
                            mapTiles={mapTiles}
                            savedAddress={office.address}
                            savedLatitude={office.latitude}
                            savedLongitude={office.longitude}
                            includeStatus
                            showGps={false}
                            unsavedSelection={unsavedSelection}
                            belowDetails={(
                                <div className="grid content-start gap-3">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800"><ShieldCheck className="size-4 text-blue-600" aria-hidden="true" />Attendance verification</h3>
                                    <p className={`flex items-start gap-2 rounded-lg px-3 py-2 text-sm ${office.verification.attendanceReady ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}>
                                        {office.verification.attendanceReady && <CircleCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />}
                                        <span>{office.verification.message}</span>
                                    </p>
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
                                    <div><Button type="button" variant="outline" disabled={form.processing} onClick={saveOffice}>Save verification</Button></div>
                                </div>
                            )}
                            belowMap={(
                                <div className="grid content-start gap-3">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800"><Wifi className="size-4 text-blue-600" aria-hidden="true" />Approved public networks</h3>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <p className="text-xs font-medium text-slate-700">Public address observed for this company</p>
                                <p className="mt-1 font-mono text-sm text-slate-900">{detectedAddress.ip ?? 'No public address'}</p>
                                <p className="mt-1 text-xs text-slate-600">{detectedAddress.message} Each company keeps its own public office address. Addresses such as 192.168, 10.x, 172.16, and localhost are never saved.</p>
                                {detectedAddress.approvable && (
                                    <label className="mt-2 flex items-start gap-2 text-sm">
                                        <input type="checkbox" className="mt-1" checked={detected.data.confirm_detected} onChange={(event) => detected.setData('confirm_detected', event.target.checked)} />
                                        <span>Approve {detectedAddress.ip}/32 for {office.name}. This does not approve any other address.</span>
                                    </label>
                                )}
                                {detectedAddress.approvable && <Button type="button" className="mt-2" size="sm" disabled={detected.processing || !detected.data.confirm_detected} onClick={approveDetected}>Approve detected address</Button>}
                                {detected.errors.confirm_detected && <p className="mt-2 text-xs text-rose-600">{detected.errors.confirm_detected}</p>}
                            </div>
                            <p className="text-xs text-slate-500">These are public IP addresses or CIDR ranges. A private address such as 192.168.1.10 is not the address the server can verify. Wi-Fi names are not collected. Removing an address stops future clock-ins and clock-outs from it and does not rewrite existing attendance.</p>
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
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    value={network.data.cidr}
                                    onChange={(event) => network.setData('cidr', event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            addNetwork();
                                        }
                                    }}
                                    placeholder="203.0.113.0/24"
                                    aria-label="Approved network"
                                />
                                <Button type="button" disabled={network.processing} onClick={addNetwork}>Add</Button>
                            </div>
                            {network.errors.cidr && <p className="text-xs text-rose-600">{network.errors.cidr}</p>}
                                </div>
                            )}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" variant="outline" disabled={form.processing}>Save office</Button>
                            <Button type="button" variant="outline" disabled={form.processing} onClick={cancelEdit}>Cancel</Button>
                        </div>
                    </form>
                </div>
            )}
        </section>
    );
}

function OfficeFields({
    form,
    timezones,
    locationFieldsAvailable,
    mapLabelAvailable,
    mapTiles,
    savedAddress,
    savedLatitude,
    savedLongitude,
    includeStatus = false,
    showGps = true,
    unsavedSelection = false,
    belowDetails,
    belowMap,
}: {
    form: ReturnType<typeof useForm<OfficeFormData>>;
    timezones: string[];
    locationFieldsAvailable: boolean;
    mapLabelAvailable: boolean;
    mapTiles?: MapTiles;
    savedAddress: string | null;
    savedLatitude: number | null;
    savedLongitude: number | null;
    includeStatus?: boolean;
    showGps?: boolean;
    unsavedSelection?: boolean;
    belowDetails?: React.ReactNode;
    belowMap?: React.ReactNode;
}) {
    return (
        <div className="grid items-start gap-4 xl:grid-cols-2">
            <div className="grid content-start gap-4">
            <section className="grid content-start gap-3">
                <h3 className="text-sm font-semibold text-slate-800">Office details and address</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Office name" error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                    <Field label="Time zone" error={form.errors.timezone}>
                        <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} aria-label="Time zone">
                            {timezones.map((zone) => <option key={zone}>{zone}</option>)}
                        </select>
                    </Field>
                    {includeStatus && (
                        <Field label="Status" error={form.errors.status}>
                            <select className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={form.data.status} aria-label="Status" onChange={(event) => form.setData('status', event.target.value)}>
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
            {belowDetails}
            </div>
            <div className="grid content-start gap-4">
            <section className="grid content-start gap-3">
                <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800"><MapPin className="size-4 text-blue-600" aria-hidden="true" />Map location and coordinates</h3>
                <p className="text-sm text-slate-600">{coordinateSentence(savedLatitude, savedLongitude)}</p>
                {unsavedSelection && <p className="text-sm text-slate-700">Unsaved selection: {form.data.latitude || 'blank'}, {form.data.longitude || 'blank'}.</p>}
                <p className="text-xs text-slate-500">Pick a point on the map or type both coordinates. Addresses are not looked up automatically, because no approved geocoding service is configured.</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Map display name" error={form.errors.map_label}>
                        <Input disabled={!mapLabelAvailable} maxLength={120} value={form.data.map_label} onChange={(event) => form.setData('map_label', event.target.value)} placeholder="Optional name shown on the map" />
                    </Field>
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
                    tiles={mapTiles}
                    disabled={!locationFieldsAvailable}
                    onSelect={(latitude, longitude) => {
                        form.setData({ ...form.data, latitude, longitude });
                    }}
                />
                {(form.data.latitude !== '' || form.data.longitude !== '') && (
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={!locationFieldsAvailable || form.data.gps_verification_enabled}
                            onClick={() => form.setData({ ...form.data, latitude: '', longitude: '' })}
                        >
                            Clear coordinates
                        </Button>
                        {form.data.gps_verification_enabled && <p className="mt-1 text-xs text-slate-500">Turn off GPS verification before clearing the coordinates.</p>}
                    </div>
                )}
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
            {belowMap}
            </div>
        </div>
    );
}

function UsaFlag() {
    return (
        <svg viewBox="0 0 190 100" className="mt-0.5 h-3.5 w-6 shrink-0 rounded-[2px] border border-slate-200" aria-hidden="true">
            <rect width="190" height="100" fill="#fff" />
            {Array.from({ length: 13 }, (_, index) => index % 2 === 0 ? <rect key={index} y={(index * 100) / 13} width="190" height={100 / 13} fill="#b22234" /> : null)}
            <rect width="80" height={(7 * 100) / 13} fill="#3c3b6e" />
        </svg>
    );
}

function ZonePreview({ title, date, time }: { title: string; date: string; time: string }) {
    const rows = zoneTimes(date, time);

    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
            <p className="flex items-start gap-2 text-sm font-medium text-slate-800">
                <UsaFlag />
                {title}
            </p>
            {rows.length === 0 ? (
                <p className="mt-1 text-xs text-slate-500">Enter a time to see New York, Detroit, and Dallas.</p>
            ) : (
                <ul className="mt-2 space-y-1 text-sm text-slate-700">
                    {rows.map((row: ZoneTime) => (
                        <li key={row.label}>
                            <span className="text-slate-500">{row.label}: </span>
                            <span className="font-medium">{row.clock} {row.abbreviation}</span>
                            {row.date && <span className="text-slate-500"> · {row.date}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <label className="grid gap-1.5 text-sm"><span className="text-sm leading-none font-medium">{label}</span>{children}{error && <span className="text-xs text-rose-600">{error}</span>}</label>;
}
