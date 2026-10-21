import type { LucideIcon } from 'lucide-react';
import { useRef } from 'react';

export interface PageTab {
    id: string;
    label: string;
    icon?: LucideIcon;
}

export function PageTabs({ label, tabs, value, onChange }: { label: string; tabs: PageTab[]; value: string; onChange: (id: string) => void }) {
    const list = useRef<HTMLDivElement>(null);

    function move(current: string, direction: 1 | -1) {
        const index = tabs.findIndex((tab) => tab.id === current);
        const next = tabs[(index + direction + tabs.length) % tabs.length];

        if (!next) {
            return;
        }

        onChange(next.id);
        const button = list.current?.querySelector<HTMLButtonElement>(`#tab-${next.id}`);
        button?.focus();
    }

    return (
        <div ref={list} role="tablist" aria-label={label} className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
            {tabs.map((tab) => {
                const selected = tab.id === value;

                return (
                    <button
                        key={tab.id}
                        id={`tab-${tab.id}`}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={`panel-${tab.id}`}
                        tabIndex={selected ? 0 : -1}
                        className={`inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-blue-600 ${selected ? 'border-blue-700 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`}
                        onClick={() => onChange(tab.id)}
                        onKeyDown={(event) => {
                            if (event.key === 'ArrowRight') {
                                event.preventDefault();
                                move(tab.id, 1);
                            }
                            if (event.key === 'ArrowLeft') {
                                event.preventDefault();
                                move(tab.id, -1);
                            }
                        }}
                    >
                        {tab.icon && <tab.icon className="size-4" aria-hidden="true" />}
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}

export function TabPanel({ id, active, children }: { id: string; active: boolean; children: React.ReactNode }) {
    return (
        <div role="tabpanel" id={`panel-${id}`} aria-labelledby={`tab-${id}`} hidden={!active}>
            {children}
        </div>
    );
}
