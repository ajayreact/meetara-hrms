import { useEffect, useRef, useState } from 'react';

const TILE = 256;
const DEFAULT_CENTER = { lat: 20.5937, lng: 78.9629 };
const DEFAULT_TILE_TEMPLATE = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const DEFAULT_TILE_DISCLOSURE = 'Opening this map loads tiles from OpenStreetMap and sends the area you are viewing to that service. No API key is used.';

export interface MapTiles {
    template: string;
    disclosure: string;
}

function tileTemplate(template?: string): string {
    if (template && /^https:\/\/[a-z0-9.-]+(?::\d+)?\/(?:[a-z0-9._~%-]+\/)*\{z\}\/\{x\}\/\{y\}\.png$/i.test(template)) {
        return template;
    }

    return DEFAULT_TILE_TEMPLATE;
}

function tileUrl(template: string, zoom: number, x: number, y: number): string {
    return template.replaceAll('{z}', String(zoom)).replaceAll('{x}', String(x)).replaceAll('{y}', String(y));
}

function latLngToWorld(latitude: number, longitude: number, zoom: number): { x: number; y: number } {
    const scale = TILE * 2 ** zoom;
    const x = ((longitude + 180) / 360) * scale;
    const sine = Math.sin((latitude * Math.PI) / 180);
    const y = (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * scale;

    return { x, y };
}

function worldToLatLng(x: number, y: number, zoom: number): { lat: number; lng: number } {
    const scale = TILE * 2 ** zoom;
    const longitude = (x / scale) * 360 - 180;
    const latitude = (180 / Math.PI) * Math.atan(Math.sinh(Math.PI - (2 * Math.PI * y) / scale));

    return {
        lat: Math.min(90, Math.max(-90, latitude)),
        lng: Math.min(180, Math.max(-180, longitude)),
    };
}

export function OfficeMapPicker({
    latitude,
    longitude,
    disabled,
    tiles,
    onSelect,
}: {
    latitude: string;
    longitude: string;
    disabled?: boolean;
    tiles?: MapTiles;
    onSelect: (latitude: string, longitude: string) => void;
}) {
    const parsedLat = Number(latitude);
    const parsedLng = Number(longitude);
    const hasPoint = latitude !== '' && longitude !== '' && Number.isFinite(parsedLat) && Number.isFinite(parsedLng);
    const center = hasPoint ? { lat: parsedLat, lng: parsedLng } : DEFAULT_CENTER;
    const [zoom, setZoom] = useState(hasPoint ? 16 : 4);
    const frame = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 640, height: 220 });

    useEffect(() => {
        const element = frame.current;

        if (!element) {
            return;
        }

        const measure = () => setSize({ width: element.clientWidth, height: element.clientHeight });
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const template = tileTemplate(tiles?.template);
    const world = latLngToWorld(center.lat, center.lng, zoom);
    const left = world.x - size.width / 2;
    const top = world.y - size.height / 2;
    const tileCount = 2 ** zoom;
    const xStart = Math.floor(left / TILE);
    const xEnd = Math.floor((left + size.width) / TILE);
    const yStart = Math.max(0, Math.floor(top / TILE));
    const yEnd = Math.min(tileCount - 1, Math.floor((top + size.height) / TILE));
    const tileImages: { key: string; x: number; y: number; left: number; top: number }[] = [];

    for (let y = yStart; y <= yEnd; y += 1) {
        for (let x = xStart; x <= xEnd; x += 1) {
            const wrapped = ((x % tileCount) + tileCount) % tileCount;
            tileImages.push({
                key: `${zoom}-${wrapped}-${y}-${x}`,
                x: wrapped,
                y,
                left: x * TILE - left,
                top: y * TILE - top,
            });
        }
    }

    return (
        <div className="grid gap-2">
            <div
                ref={frame}
                className="relative h-48 overflow-hidden rounded-xl border border-slate-200 bg-slate-100"
                onClick={(event) => {
                    if (disabled) {
                        return;
                    }

                    const rect = event.currentTarget.getBoundingClientRect();
                    const next = worldToLatLng(world.x + (event.clientX - rect.left - rect.width / 2), world.y + (event.clientY - rect.top - rect.height / 2), zoom);
                    onSelect(next.lat.toFixed(6), next.lng.toFixed(6));
                    setZoom((value) => (value < 16 ? 16 : value));
                }}
                role="application"
                aria-label="Office map. Click to set latitude and longitude."
            >
                {tileImages.map((tile) => (
                    <img
                        key={tile.key}
                        alt=""
                        src={tileUrl(template, zoom, tile.x, tile.y)}
                        referrerPolicy="origin"
                        className="absolute max-w-none"
                        style={{ left: tile.left, top: tile.top, width: TILE, height: TILE }}
                        draggable={false}
                    />
                ))}
                {hasPoint && <span className="pointer-events-none absolute left-1/2 top-1/2 z-10 size-4 -translate-x-1/2 -translate-y-full text-blue-600" aria-hidden="true"><svg viewBox="0 0 24 24" className="size-6 fill-blue-600 stroke-white" strokeWidth="1.5"><path d="M12 22s7-7.2 7-12a7 7 0 1 0-14 0c0 4.8 7 12 7 12z" /><circle cx="12" cy="10" r="2.5" className="fill-white" /></svg></span>}
                <div className="absolute top-2 left-2 z-20 flex flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm" onClick={(event) => event.stopPropagation()}>
                    <button type="button" className="px-2 py-1 text-sm leading-none text-slate-700 hover:bg-slate-50" aria-label="Zoom in" onClick={() => setZoom((value) => Math.min(18, value + 1))}>+</button>
                    <button type="button" className="border-t border-slate-200 px-2 py-1 text-sm leading-none text-slate-700 hover:bg-slate-50" aria-label="Zoom out" onClick={() => setZoom((value) => Math.max(2, value - 1))}>−</button>
                </div>
            </div>
            <p className="text-xs leading-5 text-slate-500">{tiles?.disclosure || DEFAULT_TILE_DISCLOSURE} Manual coordinates are saved with the office.</p>
        </div>
    );
}

export interface MapMarker {
    id: string;
    label: string;
    latitude: number;
    longitude: number;
    tone: 'office' | 'in' | 'out';
}

export function LocationMap({ markers, selectedId, tiles, onSelect }: { markers: MapMarker[]; selectedId?: string; tiles?: MapTiles; onSelect?: (id: string) => void }) {
    const first = markers[0];
    const [zoom, setZoom] = useState(markers.length > 1 ? 4 : 15);
    const frame = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 640, height: 320 });
    const [tilesFailed, setTilesFailed] = useState(false);
    const center = first ? { lat: first.latitude, lng: first.longitude } : DEFAULT_CENTER;

    useEffect(() => {
        const element = frame.current;

        if (!element) {
            return;
        }

        const measure = () => setSize({ width: element.clientWidth, height: element.clientHeight });
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    if (!first) {
        return null;
    }

    const template = tileTemplate(tiles?.template);
    const world = latLngToWorld(center.lat, center.lng, zoom);
    const left = world.x - size.width / 2;
    const top = world.y - size.height / 2;
    const tileCount = 2 ** zoom;
    const xStart = Math.floor(left / TILE);
    const xEnd = Math.floor((left + size.width) / TILE);
    const yStart = Math.max(0, Math.floor(top / TILE));
    const yEnd = Math.min(tileCount - 1, Math.floor((top + size.height) / TILE));
    const tileImages: { key: string; x: number; y: number; left: number; top: number }[] = [];

    for (let y = yStart; y <= yEnd; y += 1) {
        for (let x = xStart; x <= xEnd; x += 1) {
            const wrapped = ((x % tileCount) + tileCount) % tileCount;
            tileImages.push({ key: `${zoom}-${wrapped}-${y}-${x}`, x: wrapped, y, left: x * TILE - left, top: y * TILE - top });
        }
    }

    return (
        <div className="grid gap-2">
            <div ref={frame} className="relative h-80 overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                {tileImages.map((tile) => (
                    <img key={tile.key} alt="" src={tileUrl(template, zoom, tile.x, tile.y)} referrerPolicy="origin" className="absolute max-w-none" style={{ left: tile.left, top: tile.top, width: TILE, height: TILE }} draggable={false} onError={() => setTilesFailed(true)} />
                ))}
                {tilesFailed && (
                    <p role="status" className="absolute inset-x-3 top-3 z-20 rounded-lg bg-white/95 px-3 py-2 text-xs text-slate-700 shadow">
                        Map tiles from OpenStreetMap could not be loaded. Markers are still placed by their saved coordinates. Use the List view for names and addresses.
                    </p>
                )}
                {markers.map((marker) => {
                    const point = latLngToWorld(marker.latitude, marker.longitude, zoom);
                    const tone = marker.tone === 'in' ? 'bg-emerald-600' : marker.tone === 'out' ? 'bg-rose-600' : 'bg-blue-700';

                    return (
                        <button
                            key={marker.id}
                            type="button"
                            className={`absolute z-10 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow ${tone} ${selectedId === marker.id ? 'ring-2 ring-slate-950' : ''}`}
                            style={{ left: point.x - left, top: point.y - top }}
                            aria-label={marker.label}
                            onClick={() => onSelect?.(marker.id)}
                        />
                    );
                })}
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <button type="button" className="rounded border border-slate-200 px-2 py-1" onClick={() => setZoom((value) => Math.min(18, value + 1))}>Zoom in</button>
                <button type="button" className="rounded border border-slate-200 px-2 py-1" onClick={() => setZoom((value) => Math.max(2, value - 1))}>Zoom out</button>
                <span>{tiles?.disclosure || DEFAULT_TILE_DISCLOSURE} Points are not connected into a route.</span>
            </div>
        </div>
    );
}
