import { useEffect, useRef, useState } from 'react';

const TILE = 256;
const DEFAULT_CENTER = { lat: 20.5937, lng: 78.9629 };

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
    onSelect,
}: {
    latitude: string;
    longitude: string;
    disabled?: boolean;
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

    const world = latLngToWorld(center.lat, center.lng, zoom);
    const left = world.x - size.width / 2;
    const top = world.y - size.height / 2;
    const tileCount = 2 ** zoom;
    const xStart = Math.floor(left / TILE);
    const xEnd = Math.floor((left + size.width) / TILE);
    const yStart = Math.max(0, Math.floor(top / TILE));
    const yEnd = Math.min(tileCount - 1, Math.floor((top + size.height) / TILE));
    const tiles: { key: string; x: number; y: number; left: number; top: number }[] = [];

    for (let y = yStart; y <= yEnd; y += 1) {
        for (let x = xStart; x <= xEnd; x += 1) {
            const wrapped = ((x % tileCount) + tileCount) % tileCount;
            tiles.push({
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
                className="relative h-56 overflow-hidden rounded-lg border border-slate-200 bg-slate-100"
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
                {tiles.map((tile) => (
                    <img
                        key={tile.key}
                        alt=""
                        src={`https://tile.openstreetmap.org/${zoom}/${tile.x}/${tile.y}.png`}
                        className="absolute max-w-none"
                        style={{ left: tile.left, top: tile.top, width: TILE, height: TILE }}
                        draggable={false}
                    />
                ))}
                {hasPoint && <span className="pointer-events-none absolute left-1/2 top-1/2 z-10 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-rose-600 shadow" />}
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <button type="button" className="rounded border border-slate-200 px-2 py-1" onClick={() => setZoom((value) => Math.min(18, value + 1))}>Zoom in</button>
                <button type="button" className="rounded border border-slate-200 px-2 py-1" onClick={() => setZoom((value) => Math.max(2, value - 1))}>Zoom out</button>
                <span>Map tiles are from OpenStreetMap and need no API key. Manual coordinates are saved with the office.</span>
            </div>
        </div>
    );
}
