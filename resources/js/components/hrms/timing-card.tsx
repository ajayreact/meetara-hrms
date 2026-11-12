import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { AlarmClock, Clock3 } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface TimingState {
    state: 'unavailable' | 'out' | 'in' | 'break' | 'closed';
    clockIn: string | null;
    breakIn: string | null;
    breakSeconds: number;
    officeName: string | null;
    officeNow: string;
    canClockIn: boolean;
    canStartBreak: boolean;
    canEndBreak: boolean;
    canClockOut: boolean;
    gpsRequired?: boolean;
    gpsCapture?: boolean;
    gpsNoticePending?: boolean;
    shiftNotice?: string | null;
}

function formatClock(date: Date): string {
    return date.toLocaleTimeString('en-US', { hour12: false });
}

function formatElapsed(totalSeconds: number): string {
    const seconds = Math.max(0, totalSeconds);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remain = seconds % 60;

    return [hours, minutes, remain].map((part) => String(part).padStart(2, '0')).join(':');
}

export function TimingCard({ timing, notice, className = '' }: { timing: TimingState; notice?: string | null; className?: string }) {
    const [now, setNow] = useState(() => new Date(timing.officeNow));
    const [breakSeconds, setBreakSeconds] = useState(timing.breakSeconds);
    const [confirmOut, setConfirmOut] = useState(false);
    const [locating, setLocating] = useState(false);
    const [locationMessage, setLocationMessage] = useState<string | null>(null);
    const form = useForm({});
    const statusLabel = timing.state === 'break' ? 'On break' : timing.state === 'in' ? 'Clocked in' : timing.state === 'closed' ? 'Clocked out' : 'Clocked out';

    useEffect(() => {
        const base = new Date(timing.officeNow).getTime();
        const started = Date.now();
        setNow(new Date(base));
        const timer = window.setInterval(() => setNow(new Date(base + (Date.now() - started))), 1000);

        return () => window.clearInterval(timer);
    }, [timing.officeNow]);

    useEffect(() => {
        setBreakSeconds(timing.breakSeconds);

        if (timing.state !== 'break') {
            return;
        }

        const timer = window.setInterval(() => setBreakSeconds((value) => value + 1), 1000);

        return () => window.clearInterval(timer);
    }, [timing.breakSeconds, timing.state]);

    function submit(action: string, extra: Record<string, string | number> = {}) {
        form.transform((data) => ({ ...data, ...extra }));
        form.post(route(action), {
            preserveScroll: true,
            onFinish: () => {
                form.transform((data) => data);
                setLocating(false);
                setConfirmOut(false);
            },
        });
    }

    function captureThen(action: string) {
        setLocationMessage(null);

        if (!navigator.geolocation) {
            setLocationMessage('This browser did not share a location. The attendance action was sent without coordinates.');
            submit(action, { capture_result: 'unavailable' });
            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                submit(action, {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy_meters: Math.round(position.coords.accuracy),
                    capture_result: 'recorded',
                });
            },
            (error) => {
                const denied = error.code === error.PERMISSION_DENIED;
                setLocationMessage(denied ? 'Location permission was denied. The attendance action was sent without coordinates.' : 'A location was not available in time. The attendance action was sent without coordinates.');
                submit(action, { capture_result: denied ? 'denied' : 'unavailable' });
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
        );
    }

    function clock(action: 'hrms.attendance.clock-in' | 'hrms.attendance.clock-out') {
        setLocationMessage(null);

        if (timing.gpsCapture && !timing.gpsRequired) {
            captureThen(action);
            return;
        }

        if (!timing.gpsRequired) {
            submit(action);
            return;
        }

        if (typeof window !== 'undefined' && window.isSecureContext === false) {
            setConfirmOut(false);
            setLocationMessage('This page is not a secure context, so the browser will not share a location. Open the app at http://localhost, http://127.0.0.1, or HTTPS. The attendance action was not sent.');
            return;
        }

        if (!navigator.geolocation) {
            setConfirmOut(false);
            setLocationMessage('This browser cannot provide a location. The attendance action was not sent.');
            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                submit(action, {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy_meters: Math.round(position.coords.accuracy),
                });
            },
            (error) => {
                setLocating(false);
                setConfirmOut(false);
                if (error.code === error.PERMISSION_DENIED) {
                    setLocationMessage('Location permission was denied. The attendance action was not sent.');
                    return;
                }
                if (error.code === error.TIMEOUT) {
                    setLocationMessage('The browser did not share a location before the request timed out. The attendance action was not sent.');
                    return;
                }
                setLocationMessage('A browser location is unavailable. The attendance action was not sent.');
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
        );
    }

    const messages = [form.errors.attendance, locationMessage, form.errors.attendance ? null : notice].filter((item): item is string => typeof item === 'string' && item !== '');

    return (
        <section className={`rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ${className}`.trim()}>
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-base font-semibold">My Timing</h2>
                <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">{statusLabel}</span>
            </div>
            <div className="grid grid-cols-2 overflow-hidden rounded-xl border border-slate-200">
                <div className="border-r border-slate-200 px-3 py-3">
                    <p className="text-xs text-slate-500">Current Time</p>
                    <p className="mt-1 font-mono text-2xl font-semibold text-orange-500">{formatClock(now)}</p>
                </div>
                <div className="px-3 py-3">
                    <p className="text-xs text-slate-500">Break Time</p>
                    <p className="mt-1 font-mono text-2xl font-semibold text-orange-500">{timing.state === 'break' ? formatElapsed(breakSeconds) : '00:00:00'}</p>
                </div>
            </div>
            <div className="mt-3 grid grid-cols-2 gap-2">
                <Button type="button" className="bg-emerald-600 hover:bg-emerald-700" disabled={!timing.canClockIn || form.processing || locating} onClick={() => clock('hrms.attendance.clock-in')}>
                    {form.processing || locating ? 'Working…' : 'Clock In'}
                </Button>
                <Button type="button" variant="outline" className="border-orange-300 text-orange-600" disabled={!timing.canStartBreak || form.processing} onClick={() => submit('hrms.attendance.break-start')}>
                    Start Break
                </Button>
                <Button type="button" variant="outline" className="border-rose-300 text-rose-600" disabled={!timing.canEndBreak || form.processing} onClick={() => submit('hrms.attendance.break-end')}>
                    End Break
                </Button>
                <Button type="button" className="bg-rose-500 hover:bg-rose-600" disabled={!timing.canClockOut || form.processing} onClick={() => setConfirmOut(true)}>
                    Clock Out
                </Button>
            </div>
            <div className="mt-3 space-y-2">
                <InfoRow icon={Clock3} label="Clock In" value={timing.clockIn ?? '—'} />
                <InfoRow icon={AlarmClock} label="Break In" value={timing.breakIn ?? '—'} />
            </div>
            {timing.shiftNotice ? <p className="mt-3 text-xs text-slate-600">{timing.shiftNotice}</p> : null}
            <p className="mt-3 text-xs text-slate-500">
                {timing.officeName ? `Clock-in and clock-out are checked against the approved public network for ${timing.officeName}.` : 'Clock-in and clock-out are recorded only after the server verifies an approved office network.'}
                {timing.gpsRequired ? ' This office also requires a browser location inside the saved radius. The server checks that distance, and GPS alone is not enough. The browser can share that location from http://localhost, http://127.0.0.1, or HTTPS. An http LAN address cannot.' : ''}
                {timing.gpsCapture ? ' Optional GPS capture is on, so a browser location may be saved with this attendance action after the network check.' : ''}
                {timing.gpsNoticePending ? ' GPS capture is enabled, but it is not requested until the Route Map notice is acknowledged.' : ''}
                {' '}A Wi-Fi name is not detected.
            </p>
            {messages.map((item) => <p key={item} className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{item}</p>)}
            <Dialog open={confirmOut} onOpenChange={setConfirmOut}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Clock out?</DialogTitle>
                        <DialogDescription>This closes the current attendance session after the same office network check as clock-in. An open break must be ended first.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirmOut(false)}>Cancel</Button>
                        <Button type="button" className="bg-rose-500 hover:bg-rose-600" disabled={form.processing || locating} onClick={() => clock('hrms.attendance.clock-out')}>
                            Clock Out
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function InfoRow({ icon: Icon, label, value }: { icon: typeof Clock3; label: string; value: string }) {
    return (
        <div className="flex items-center justify-between rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <span className="flex items-center gap-2 text-slate-500">
                <Icon className="size-4" aria-hidden="true" />
                {label}
            </span>
            <span className="font-medium">{value}</span>
        </div>
    );
}
