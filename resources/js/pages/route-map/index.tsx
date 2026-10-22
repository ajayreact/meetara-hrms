import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { LocationMap, type MapMarker, type MapTiles } from '@/components/hrms/office-map';
import { SectionCard } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface MapOffice {
    id: number;
    name: string;
    mapLabel: string;
    address: string | null;
    status: string;
    onMap: boolean;
    latitude: number | null;
    longitude: number | null;
}

interface MapPoint {
    id: number;
    employee: string;
    event: string;
    at: string;
    status: string;
    accuracyMeters: number | null;
    latitude: number | null;
    longitude: number | null;
}

interface Acknowledgement {
    employee: string;
    version: string;
    at: string;
}

interface EmployeeChoice {
    id: number;
    name: string;
}

interface RouteMapData {
    ready: boolean;
    captureEnabled: boolean;
    canManageCapture: boolean;
    canViewPreciseLocations: boolean;
    notice: string;
    noticeVersion: string;
    retention: string;
    acknowledged: boolean;
    canAcknowledge: boolean;
    acknowledgements: Acknowledgement[];
    offices: MapOffice[];
    filters: { from: string; to: string; employee: string };
    employees: EmployeeChoice[];
    points: MapPoint[];
}

export default function RouteMapPage({ workspace, map, mapTiles, notice }: HrmsPageProps & { map: RouteMapData; mapTiles?: MapTiles; notice?: string | null }) {
    const [view, setView] = useState<'map' | 'list'>('map');
    const [selected, setSelected] = useState<string | null>(null);
    const [from, setFrom] = useState(map.filters.from);
    const [to, setTo] = useState(map.filters.to);
    const [employee, setEmployee] = useState(map.filters.employee);
    const acknowledge = useForm({});
    const mappedOffices = map.offices.filter((office) => office.onMap && office.latitude !== null && office.longitude !== null);
    const recordedPoints = map.points.filter((point) => point.latitude !== null && point.longitude !== null);
    const markers: MapMarker[] = [
        ...mappedOffices.map((office) => ({ id: `office-${office.id}`, label: office.mapLabel, latitude: office.latitude as number, longitude: office.longitude as number, tone: 'office' as const })),
        ...recordedPoints.map((point) => ({ id: `point-${point.id}`, label: `${point.employee} ${point.event}`, latitude: point.latitude as number, longitude: point.longitude as number, tone: point.event === 'Clock-out' ? 'out' as const : 'in' as const })),
    ];
    const selectedOffice = map.offices.find((office) => `office-${office.id}` === selected) ?? null;

    function applyFilters(event: React.FormEvent) {
        event.preventDefault();
        router.get(route('hrms.route-map'), { from, to, employee }, { preserveState: true, replace: true });
    }

    return (
        <HrmsLayout workspace={workspace} title="Route Map">
            <PageIntro title="Route Map" subtitle="Saved office coordinates and attendance locations that were explicitly shared. This page does not draw a travel route or track a device in the background." />
            {notice && <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{notice}</p>}
            <div className="mb-4 flex flex-wrap gap-2">
                <Button type="button" variant={view === 'map' ? 'default' : 'outline'} onClick={() => setView('map')}>Map</Button>
                <Button type="button" variant={view === 'list' ? 'default' : 'outline'} onClick={() => setView('list')}>List</Button>
            </div>
            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.8fr)]">
                <SectionCard title="Offices">
                    {mappedOffices.length === 0 ? (
                        <p className="text-sm text-slate-600">No active office has saved coordinates. Offices without coordinates remain usable elsewhere and are listed as unavailable here.</p>
                    ) : view === 'map' ? (
                        <LocationMap markers={markers.filter((marker) => marker.tone === 'office')} selectedId={selected ?? undefined} tiles={mapTiles} onSelect={setSelected} />
                    ) : (
                        <OfficeList offices={map.offices} onSelect={setSelected} />
                    )}
                    {view === 'map' && mappedOffices.length > 0 && <div className="mt-4"><OfficeList offices={map.offices} onSelect={setSelected} /></div>}
                </SectionCard>
                <SectionCard title="Selected office">
                    {selectedOffice ? (
                        <dl className="grid gap-2 text-sm">
                            <div><dt className="text-slate-500">Name</dt><dd className="font-medium">{selectedOffice.mapLabel}</dd></div>
                            <div><dt className="text-slate-500">Address</dt><dd className="font-medium">{selectedOffice.address || 'Not recorded'}</dd></div>
                            <div><dt className="text-slate-500">Status</dt><dd className="font-medium">{selectedOffice.status}</dd></div>
                        </dl>
                    ) : (
                        <p className="text-sm text-slate-600">Choose an office marker or name to see its address and status.</p>
                    )}
                </SectionCard>
            </div>
            {map.canViewPreciseLocations && (
                <div className="mt-4 grid items-start gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.8fr)]">
                    <SectionCard title="Attendance locations">
                        <form className="mb-3 flex flex-wrap items-end gap-2" onSubmit={applyFilters}>
                            <label className="text-sm text-slate-600">From
                                <input className="mt-1 block h-10 rounded-md border border-input bg-background px-3" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
                            </label>
                            <label className="text-sm text-slate-600">To
                                <input className="mt-1 block h-10 rounded-md border border-input bg-background px-3" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
                            </label>
                            {map.employees.length > 0 && (
                                <label className="text-sm text-slate-600">Employee
                                    <select className="mt-1 block h-10 rounded-md border border-input bg-background px-3" value={employee} onChange={(event) => setEmployee(event.target.value)}>
                                        <option value="">Everyone in scope</option>
                                        {map.employees.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                    </select>
                                </label>
                            )}
                            <Button type="submit" variant="outline">Apply</Button>
                        </form>
                        {!map.ready && <p className="mb-3 text-sm text-amber-800">GPS storage is not ready on this database. Attendance locations are not saved.</p>}
                        {recordedPoints.length > 0 && view === 'map' && <LocationMap markers={markers.filter((marker) => marker.tone !== 'office')} tiles={mapTiles} />}
                        {map.points.length === 0 ? (
                            <p className="mt-3 text-sm text-slate-600">No attendance location records are in this range.</p>
                        ) : (
                            <ul className="mt-3 divide-y divide-slate-100">
                                {map.points.map((point) => (
                                    <li key={point.id} className="flex flex-wrap items-start justify-between gap-2 py-3 text-sm">
                                        <span>
                                            <span className="font-medium">{point.employee}</span>
                                            <span className="block text-slate-600">{point.event} · {point.status}{point.accuracyMeters !== null ? ` · ${point.accuracyMeters} m` : ''}</span>
                                        </span>
                                        <span className="text-xs text-slate-500">{point.at}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                    <SectionCard title="Location notice">
                        <p className="text-sm leading-6 text-slate-700">{map.notice}</p>
                        <p className="mt-3 text-xs text-slate-500">Notice version {map.noticeVersion}. Retention: {map.retention}.</p>
                        {map.canAcknowledge && (
                            <Button type="button" className="mt-4" disabled={acknowledge.processing} onClick={() => acknowledge.post(route('hrms.route-map.acknowledge'))}>
                                Acknowledge notice
                            </Button>
                        )}
                        {map.acknowledged && <p className="mt-3 text-sm text-emerald-800">You have acknowledged the current notice.</p>}
                        {!map.captureEnabled && <p className="mt-3 text-sm text-slate-600">GPS capture is off. Clock-in and clock-out do not request a browser location for this map.</p>}
                        {map.acknowledgements.length > 0 && (
                            <ul className="mt-4 divide-y divide-slate-100">
                                {map.acknowledgements.map((item) => (
                                    <li key={`${item.employee}-${item.at}`} className="py-2 text-sm">
                                        <span className="font-medium">{item.employee}</span>
                                        <span className="block text-xs text-slate-500">Version {item.version} · {item.at}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>
            )}
        </HrmsLayout>
    );
}

function OfficeList({ offices, onSelect }: { offices: MapOffice[]; onSelect: (id: string) => void }) {
    return (
        <ul className="divide-y divide-slate-100">
            {offices.map((office) => (
                <li key={office.id} className="flex items-start justify-between gap-3 py-3 text-sm">
                    <button type="button" className="min-w-0 text-left" onClick={() => onSelect(`office-${office.id}`)}>
                        <span className="font-medium">{office.mapLabel}</span>
                        <span className="block text-slate-500">{office.address || 'Address not recorded'}</span>
                    </button>
                    <span className="text-xs text-slate-500">{office.onMap ? office.status : `${office.status} · Not on map`}</span>
                </li>
            ))}
        </ul>
    );
}
