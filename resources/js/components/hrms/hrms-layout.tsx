import { Button } from '@/components/ui/button';
import { isNavActive, sidebarItems, type SidebarItem } from '@/lib/navigation';
import type { Workspace } from '@/types/hrms';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, LogOut, Menu, PanelLeftClose, PanelLeftOpen, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export function initials(name: string): string {
    const letters = name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? '');

    return letters.join('') || '?';
}

export function greetingName(name: string): string {
    const hour = new Date().getHours();
    const hello = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const first = name.trim().split(/\s+/)[0] || name;

    return `${hello}, ${first}`;
}

export function HrmsLayout({ workspace, title, children }: { workspace: Workspace; title: string; children: React.ReactNode }) {
    const { url } = usePage<SharedData>();
    const [open, setOpen] = useState(false);
    const [compact, setCompact] = useState(false);
    const items = sidebarItems(workspace);
    const sections = items.reduce<string[]>((names, item) => (names.includes(item.section) ? names : [...names, item.section]), []);

    useEffect(() => {
        setCompact(window.localStorage.getItem('meetara-sidebar') === 'compact');
    }, []);

    function logout() {
        router.post(route('logout'));
    }

    function toggleCompact() {
        setCompact((value) => {
            window.localStorage.setItem('meetara-sidebar', value ? 'expanded' : 'compact');

            return !value;
        });
    }

    return (
        <div className="min-h-screen bg-[#f3f6fb] text-slate-950">
            <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-3">
                Skip to content
            </a>
            <div className="flex min-h-screen w-full min-w-0">
                <aside className={`${open ? 'translate-x-0' : '-translate-x-full'} ${compact ? 'md:w-[76px]' : 'md:w-60'} no-print fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-slate-200 bg-white shadow-sm transition-[width,transform] md:sticky md:top-0 md:h-dvh md:translate-x-0 md:self-start md:shadow-none`}>
                    <div className={`flex items-center gap-2 px-3 pt-4 pb-3 ${compact ? 'md:flex-col' : ''}`}>
                        <Link href="/dashboard" className="flex min-w-0 flex-1 justify-center md:justify-start" onClick={() => setOpen(false)}>
                            <img src="/brand/veltrix-logo.png" alt="Veltrix" className={`h-9 w-auto object-contain ${compact ? 'max-w-[148px] md:max-w-10' : 'max-w-[148px]'}`} />
                        </Link>
                        <Button type="button" variant="ghost" size="icon" className="hidden md:inline-flex" aria-label={compact ? 'Expand navigation' : 'Collapse navigation'} onClick={toggleCompact}>
                            {compact ? <PanelLeftOpen /> : <PanelLeftClose />}
                        </Button>
                    </div>
                    <nav aria-label="Primary" className="flex flex-1 flex-col gap-2.5 overflow-y-auto px-2 pb-3">
                        {sections.map((section) => (
                            <div key={section}>
                                <p className={`px-2.5 pb-1 text-[11px] font-semibold tracking-wide text-slate-400 uppercase ${compact ? 'md:sr-only' : ''}`}>{section}</p>
                                <div className="flex flex-col gap-0.5">
                                    {items.filter((item) => item.section === section).map((item) => (
                                        <NavLink key={item.href} item={item} url={url} compact={compact} onNavigate={() => setOpen(false)} />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>
                    <div className="border-t border-slate-100 p-2">
                        <button type="button" onClick={logout} className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none">
                            <LogOut className="size-[18px] shrink-0" aria-hidden="true" />
                            <span className={compact ? 'md:sr-only' : ''}>Logout</span>
                        </button>
                    </div>
                </aside>
                {open && <button type="button" aria-label="Close menu" className="fixed inset-0 z-30 bg-slate-900/40 md:hidden" onClick={() => setOpen(false)} />}

                <div className="min-w-0 flex-1">
                    <header className="no-print sticky top-0 z-20 flex items-center gap-3 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur md:px-6">
                        <Button type="button" variant="outline" size="icon" className="md:hidden" aria-label={open ? 'Close navigation' : 'Open navigation'} aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                            {open ? <X /> : <Menu />}
                        </Button>
                        <div className="flex min-w-0 flex-1 items-center gap-2">
                            <span className="hidden size-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700 sm:flex" aria-hidden="true">
                                <Building2 className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-slate-950">{workspace.companyName ?? 'Veltrix platform'}</p>
                                <p className="truncate text-xs text-slate-500">{workspace.roleLabel}{workspace.officeName ? ` · ${workspace.officeName}` : ''} · {title}</p>
                            </div>
                        </div>
                        <div className="flex min-w-0 items-center gap-2">
                            <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-semibold text-blue-700">{initials(workspace.userName)}</span>
                            <span className="hidden min-w-0 text-right sm:block">
                                <span className="block truncate text-sm font-medium text-slate-900">{workspace.userName}</span>
                                <span className="block text-xs text-slate-500">{workspace.roleLabel}</span>
                            </span>
                        </div>
                    </header>
                    <Head title={title} />
                    <main id="main" className="px-4 py-4 md:px-6 xl:px-8">
                        {children}
                    </main>
                </div>
            </div>
        </div>
    );
}

function NavLink({ item, url, compact, onNavigate }: { item: SidebarItem; url: string; compact: boolean; onNavigate: () => void }) {
    const active = isNavActive(item.href, url);
    const Icon = item.icon;

    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            title={item.label}
            className={`flex items-center gap-2.5 rounded-lg border-l-2 px-2.5 py-1.5 text-sm font-medium focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none ${compact ? 'md:justify-center md:border-l-0 md:px-2' : ''} ${active ? 'border-blue-700 bg-blue-50 text-blue-800' : 'border-transparent text-slate-600 hover:bg-slate-50 hover:text-slate-900'}`}
            aria-current={active ? 'page' : undefined}
        >
            <Icon className="size-[18px] shrink-0" aria-hidden="true" />
            <span className={`truncate ${compact ? 'md:sr-only' : ''}`}>{item.label}</span>
        </Link>
    );
}

export function PageIntro({ title, subtitle, actions, mark }: { title: string; subtitle?: string; actions?: React.ReactNode; mark?: React.ReactNode }) {
    return (
        <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div className="flex min-w-0 items-start gap-3">
                {mark}
                <div className="min-w-0">
                    <p className="text-xs font-medium tracking-wide text-slate-400 uppercase">Veltrix</p>
                    <h1 className="text-2xl font-semibold tracking-tight text-slate-950">{title}</h1>
                    {subtitle && <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-500">{subtitle}</p>}
                </div>
            </div>
            {actions}
        </div>
    );
}
