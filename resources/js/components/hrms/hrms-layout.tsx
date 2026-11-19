import { Button } from '@/components/ui/button';
import { branchActive, itemActive, sidebarItems, type SidebarItem } from '@/lib/navigation';
import type { Workspace } from '@/types/hrms';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, ChevronRight, LogOut, Menu, PanelLeftClose, PanelLeftOpen, X } from 'lucide-react';
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
    const context = [workspace.roleLabel, workspace.officeName, title !== workspace.roleLabel ? title : null].filter(Boolean).join(' · ');
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
        <div className="min-h-screen bg-[linear-gradient(180deg,#e8eef8_0%,#f3f6fb_28%,#f7f9fc_100%)] text-slate-950">
            <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-3">
                Skip to content
            </a>
            <div className="flex min-h-screen w-full min-w-0">
                <aside className={`${open ? 'translate-x-0' : '-translate-x-full'} ${compact ? 'md:w-[76px]' : 'md:w-60'} no-print fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-slate-200/90 bg-[linear-gradient(180deg,#ffffff_0%,#f8fbff_100%)] shadow-[4px_0_24px_-18px_rgba(15,23,42,0.35)] transition-[width,transform] md:sticky md:top-0 md:h-dvh md:translate-x-0 md:self-start md:shadow-none`}>
                    <div className={`flex items-center gap-2 border-b border-blue-50 px-3 pt-4 pb-3 ${compact ? 'md:flex-col' : ''}`}>
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
                                        <NavNode key={item.label} item={item} url={url} compact={compact} depth={0} onNavigate={() => setOpen(false)} />
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
                    <header className="no-print sticky top-0 z-20 flex items-center gap-3 border-b border-slate-200/80 bg-white/90 px-4 py-3 shadow-[0_8px_24px_-20px_rgba(15,23,42,0.45)] backdrop-blur md:px-6">
                        <Button type="button" variant="outline" size="icon" className="md:hidden" aria-label={open ? 'Close navigation' : 'Open navigation'} aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                            {open ? <X /> : <Menu />}
                        </Button>
                        <div className="flex min-w-0 flex-1 items-center gap-2">
                            <span className="hidden size-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white shadow-sm shadow-blue-600/30 sm:flex" aria-hidden="true">
                                <Building2 className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-slate-950">{workspace.companyName ?? 'Veltrix platform'}</p>
                                <p className="truncate text-xs text-slate-500">{context}</p>
                            </div>
                        </div>
                        <div className="flex min-w-0 items-center gap-2">
                            <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white shadow-sm shadow-blue-600/30">{initials(workspace.userName)}</span>
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

function NavNode({ item, url, compact, depth, onNavigate }: { item: SidebarItem; url: string; compact: boolean; depth: number; onNavigate: () => void }) {
    const childActive = item.children?.some((child) => branchActive(child, url)) ?? false;
    const [expanded, setExpanded] = useState(childActive);

    useEffect(() => {
        if (childActive) {
            setExpanded(true);
        }
    }, [childActive, url]);

    if (item.children) {
        return <NavGroup item={item} url={url} compact={compact} depth={depth} expanded={expanded} onToggle={() => setExpanded((value) => !value)} onNavigate={onNavigate} />;
    }

    if (!item.href) {
        return null;
    }

    return <NavLink item={{ ...item, href: item.href }} url={url} compact={compact} depth={depth} onNavigate={onNavigate} />;
}

function NavGroup({ item, url, compact, depth, expanded, onToggle, onNavigate }: { item: SidebarItem; url: string; compact: boolean; depth: number; expanded: boolean; onToggle: () => void; onNavigate: () => void }) {
    const Icon = item.icon;
    const containsActive = item.children?.some((child) => branchActive(child, url)) ?? false;
    const pad = depth === 0 ? 'px-2.5' : depth === 1 ? 'pl-8 pr-2.5' : 'pl-11 pr-2.5';

    return (
        <div className={depth === 0 ? 'relative' : undefined}>
            <button
                type="button"
                title={item.label}
                aria-expanded={expanded}
                onClick={onToggle}
                className={`flex w-full items-center gap-2.5 rounded-lg border-l-2 py-1.5 text-left text-sm font-medium focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none ${pad} ${compact && depth === 0 ? 'md:justify-center md:border-l-0 md:px-2' : ''} ${containsActive ? 'border-transparent text-slate-900' : 'border-transparent text-slate-600 hover:bg-slate-50 hover:text-slate-900'}`}
            >
                {depth === 0 && <Icon className="size-[18px] shrink-0" aria-hidden="true" />}
                <span className={`min-w-0 flex-1 truncate ${compact && depth === 0 ? 'md:sr-only' : ''}`}>{item.label}</span>
                <ChevronRight className={`size-4 shrink-0 text-slate-400 transition-transform ${expanded ? 'rotate-90' : ''} ${compact && depth === 0 ? 'md:hidden' : ''}`} aria-hidden="true" />
            </button>
            {expanded && item.children && (
                <>
                    <div className={`mt-0.5 flex flex-col gap-0.5 ${compact && depth === 0 ? 'md:hidden' : ''}`}>
                        {item.children.map((child) => (
                            <NavNode key={`${item.label}-${child.label}`} item={child} url={url} compact={false} depth={depth + 1} onNavigate={onNavigate} />
                        ))}
                    </div>
                    {compact && depth === 0 && (
                        <div className="absolute top-0 left-full z-50 ml-2 hidden w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg md:block">
                            {item.children.map((child) => (
                                <NavNode key={`${item.label}-flyout-${child.label}`} item={child} url={url} compact={false} depth={0} onNavigate={onNavigate} />
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function NavLink({ item, url, compact, depth, onNavigate }: { item: SidebarItem & { href: string }; url: string; compact: boolean; depth: number; onNavigate: () => void }) {
    const active = itemActive(item, url);
    const Icon = item.icon;
    const pad = depth === 0 ? 'px-2.5' : depth === 1 ? 'pl-8 pr-2.5' : 'pl-11 pr-2.5';

    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            title={item.label}
            className={`flex items-center gap-2.5 rounded-lg border-l-2 py-1.5 text-sm font-medium focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:outline-none ${pad} ${compact && depth === 0 ? 'md:justify-center md:border-l-0 md:px-2' : ''} ${active ? 'border-blue-700 bg-blue-50 text-blue-800' : 'border-transparent text-slate-600 hover:bg-slate-50 hover:text-slate-900'}`}
            aria-current={active ? 'page' : undefined}
        >
            {depth === 0 && <Icon className="size-[18px] shrink-0" aria-hidden="true" />}
            <span className={`truncate ${compact && depth === 0 ? 'md:sr-only' : ''}`}>{item.label}</span>
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
