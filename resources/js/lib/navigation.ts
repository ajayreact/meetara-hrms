import type { Workspace } from '@/types/hrms';
import {
    BarChart3,
    Briefcase,
    Building2,
    CalendarCheck,
    CalendarDays,
    Contact,
    CalendarRange,
    Inbox,
    LayoutDashboard,
    Megaphone,
    LifeBuoy,
    Map,
    Network,
    Receipt,
    ClipboardList,
    FileText,
    IdCard,
    ScrollText,
    Wallet,
    Settings,
    Shield,
    Timer,
    UserRound,
    Users,
    type LucideIcon,
} from 'lucide-react';

export interface SidebarItem {
    label: string;
    href?: string;
    icon: LucideIcon;
    section: string;
    children?: SidebarItem[];
    active?: (url: string) => boolean;
}

export function sidebarItems(workspace: Workspace): SidebarItem[] {
    if (workspace.role === 'super_admin') {
        return [
            { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, section: 'Platform' },
            { label: 'Companies', href: '/companies', icon: Building2, section: 'Platform' },
            { label: 'Audit log', href: '/audit', icon: ScrollText, section: 'Platform' },
            { label: 'Settings', href: '/company/settings', icon: Settings, section: 'Platform' },
            { label: 'Help Desk', href: '/help-desk', icon: LifeBuoy, section: 'Platform' },
        ];
    }

    const items: SidebarItem[] = [
        { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, section: 'Workspace' },
        { label: 'My Profile', href: '/my-profile', icon: UserRound, section: 'Workspace' },
        { label: 'Inbox', href: '/inbox', icon: Inbox, section: 'Workspace' },
        { label: 'Announcements', href: '/announcements', icon: Megaphone, section: 'Workspace' },
    ];

    if (workspace.role === 'team_lead') {
        items.push({ label: 'My team', href: '/employees', icon: Users, section: 'Workspace' });
        items.push({ label: 'My leads', href: '/my-leads', icon: Contact, section: 'Workspace' });
        if (workspace.permissions.leadsReport) {
            items.push({ label: 'Lead reports', href: '/my-leads/reports', icon: BarChart3, section: 'Workspace' });
        }
    } else if (workspace.permissions.employees) {
        items.push({ label: 'Employees', href: '/employees', icon: Users, section: 'People' });
    }
    if (workspace.permissions.leadsViewAll) {
        items.push({ label: 'Leads', href: '/leads', icon: Contact, section: 'People' });
    }
    if (workspace.permissions.leadsManageSources) {
        items.push({ label: 'Lead sources', href: '/leads/sources', icon: Contact, section: 'People' });
    }
    if (workspace.permissions.leadsViewAll && workspace.permissions.leadsReport) {
        items.push({ label: 'Lead reports', href: '/leads/reports', icon: BarChart3, section: 'People' });
    }
    if (workspace.role === 'employee' && workspace.permissions.leadsFollowUpOwn) {
        items.push({ label: 'My leads', href: '/leads/mine', icon: Contact, section: 'Workspace' });
        items.push({ label: 'My report', href: '/leads/mine/report', icon: BarChart3, section: 'Workspace' });
    }

    if (workspace.role === 'company_admin' || workspace.role === 'hr') {
        items.push({
            label: 'Offer Letters',
            icon: ScrollText,
            section: 'People',
            children: [
                {
                    label: 'India',
                    icon: ScrollText,
                    section: 'People',
                    children: [
                        { label: 'Indian Offer Letters', href: '/offer-letters', icon: ScrollText, section: 'People', active: isIndianOfferLetters },
                        { label: 'Indian Offer Letter Settings', href: '/offer-letters/templates', icon: ScrollText, section: 'People', active: isIndiaOfferSettings },
                    ],
                },
                {
                    label: 'USA',
                    icon: ScrollText,
                    section: 'People',
                    children: [
                        { label: 'USA Offer Letters', href: '/usa-offers', icon: ScrollText, section: 'People', active: isUsaOfferLetters },
                        { label: 'USA Offer Letter Settings', href: '/usa-offers/settings', icon: ScrollText, section: 'People', active: isUsaOfferSettings },
                    ],
                },
            ],
        });
        items.push({ label: 'STEM I-983', href: '/i983', icon: ClipboardList, section: 'People' });
        items.push({ label: 'Candidate forms', href: '/candidates', icon: ClipboardList, section: 'People' });
    }

    if (workspace.permissions.candidatesManage) {
        items.push({ label: 'Candidate Management', href: '/candidate-records', icon: Contact, section: 'People' });
    }

    if (workspace.permissions.idCardsManage || workspace.permissions.idCardsIssue) {
        const idCards: SidebarItem[] = [];

        if (workspace.permissions.idCardsManage) {
            idCards.push(
                { label: 'Employee ID Cards', href: '/id-cards', icon: IdCard, section: 'People', active: isEmployeeIdCards },
                { label: 'Standalone ID Cards', href: '/id-cards/standalone', icon: IdCard, section: 'People', active: isStandaloneIdCards },
                { label: 'Standalone ID Import', href: '/id-cards/standalone/import', icon: IdCard, section: 'People', active: (url) => pathOf(url).startsWith('/id-cards/standalone/import') },
                { label: 'ID Card Settings', href: '/id-cards/settings', icon: IdCard, section: 'People', active: (url) => pathOf(url).startsWith('/id-cards/settings') },
            );
        }

        if (workspace.permissions.idCardsIssue) {
            idCards.push(
                { label: 'Bulk ID cards', href: '/id-cards/bulk', icon: IdCard, section: 'People', active: (url) => pathOf(url).startsWith('/id-cards/bulk') },
                { label: 'Bulk standalone ID cards', href: '/id-cards/standalone/bulk', icon: IdCard, section: 'People', active: (url) => pathOf(url).startsWith('/id-cards/standalone/bulk') },
            );
        }

        items.push({ label: 'ID Cards', icon: IdCard, section: 'People', children: idCards });
    }

    items.push({
        label: 'Attendance',
        href: workspace.permissions.attendanceManage ? '/attendance' : '/my-attendance',
        icon: CalendarCheck,
        section: 'Time',
    });

    if (workspace.permissions.attendanceManage) {
        items.push({ label: 'My Attendance', href: '/my-attendance', icon: CalendarDays, section: 'Time' });
    }

    items.push({ label: 'Leave', href: '/leave', icon: CalendarRange, section: 'Time' });

    if (workspace.role === 'employee') {
        items.push({ label: 'My Documents', href: '/my-documents', icon: FileText, section: 'People' });
    }

    if (workspace.role === 'company_admin' || workspace.role === 'hr') {
        items.push({ label: 'Documents', href: '/documents', icon: FileText, section: 'People' });
    }

    if (workspace.permissions.routeMap) {
        items.push({ label: 'Route Map', href: '/route-map', icon: Map, section: 'Time' });
    }

    if (workspace.permissions.salaryManage) {
        items.push({ label: 'Salary', href: '/salary', icon: Wallet, section: 'Pay' });
    }

    if (workspace.permissions.payrollView || workspace.permissions.payrollImport) {
        items.push({ label: 'Payroll', href: '/payroll', icon: Receipt, section: 'Pay' });
    }

    if (workspace.permissions.payrollView) {
        items.push({ label: 'Legacy payroll', href: '/payroll/runs', icon: Receipt, section: 'Pay' });
    }

    if (workspace.permissions.payrollPay) {
        items.push({ label: 'Payments', href: '/payments', icon: Receipt, section: 'Pay' });
    }

    items.push(
        { label: 'Overtime', href: '/overtime', icon: Timer, section: 'Time' },
        { label: 'Holidays', href: '/holidays', icon: CalendarDays, section: 'Time' },
    );

    items.push({
        label: workspace.role === 'employee' || workspace.role === 'team_lead' ? 'My Payslips' : 'Payslips',
        href: '/payslips',
        icon: Receipt,
        section: 'Pay',
    });

    items.push({ label: 'Help Desk', href: '/help-desk', icon: LifeBuoy, section: 'Support' });

    if (workspace.permissions.offices) {
        items.push({ label: 'Departments', href: '/departments', icon: Network, section: 'Support' });
        items.push({ label: 'Designations', href: '/designations', icon: Briefcase, section: 'Support' });
        items.push({ label: 'Offices', href: '/offices', icon: Building2, section: 'Support' });
    }

    if (workspace.role === 'company_admin') {
        items.push({ label: 'Access levels', href: '/access-levels', icon: Shield, section: 'Support' });
    }

    if (workspace.permissions.settings) {
        items.push({ label: 'Settings', href: '/company/settings', icon: Settings, section: 'Support' });
    }

    return items;
}

export function itemActive(item: SidebarItem, url: string): boolean {
    if (item.active) {
        return item.active(url);
    }

    if (!item.href) {
        return false;
    }

    return isNavActive(item.href, url);
}

export function branchActive(item: SidebarItem, url: string): boolean {
    if (itemActive(item, url)) {
        return true;
    }

    return item.children?.some((child) => branchActive(child, url)) ?? false;
}

function pathOf(url: string): string {
    return url.split('?')[0] ?? url;
}

function isIndianOfferLetters(url: string): boolean {
    const path = pathOf(url);

    return path === '/offer-letters' || path === '/offer-letters/create' || /^\/offer-letters\/\d+(?:\/.*)?$/.test(path);
}

function isIndiaOfferSettings(url: string): boolean {
    const [path, query = ''] = url.split('?');

    if (path === '/offer-letters/setup') {
        return true;
    }

    if (path !== '/offer-letters/templates') {
        return false;
    }

    return new URLSearchParams(query).get('country') !== 'usa';
}

function isUsaOfferLetters(url: string): boolean {
    const path = pathOf(url);

    if (!path.startsWith('/usa-offers') || path === '/usa-offers/settings' || path.startsWith('/usa-offers/settings/')) {
        return false;
    }

    return path === '/usa-offers' || path === '/usa-offers/create' || /^\/usa-offers\/\d+(?:\/.*)?$/.test(path);
}

function isUsaOfferSettings(url: string): boolean {
    const path = pathOf(url);

    return path === '/usa-offers/settings' || path.startsWith('/usa-offers/settings/');
}

function isEmployeeIdCards(url: string): boolean {
    const path = pathOf(url);

    return path === '/id-cards' || /^\/id-cards\/\d+(?:\/.*)?$/.test(path);
}

function isStandaloneIdCards(url: string): boolean {
    const path = pathOf(url);

    return path === '/id-cards/standalone' || /^\/id-cards\/standalone\/\d+(?:\/.*)?$/.test(path);
}

export function isNavActive(href: string, url: string): boolean {
    const [path, query = ''] = url.split('?');
    const [hrefPath, hrefQuery = ''] = href.split('?');
    const actual = new URLSearchParams(query);

    if (hrefQuery !== '') {
        const expected = new URLSearchParams(hrefQuery);

        for (const [key, value] of expected.entries()) {
            if (actual.get(key) !== value) {
                return false;
            }
        }

        return path === hrefPath;
    }

    if (hrefPath === '/dashboard' || hrefPath === '/payroll') {
        return path === hrefPath;
    }

    if (hrefPath === '/leads') {
        return (path === '/leads' || path.startsWith('/leads/')) && !path.startsWith('/leads/reports') && !path.startsWith('/leads/sources');
    }

    if (hrefPath === '/leads/mine') {
        return path === '/leads/mine' || /^\/leads\/mine\/\d+$/.test(path);
    }

    if (hrefPath === '/my-leads') {
        return path === '/my-leads' || /^\/my-leads\/\d+$/.test(path);
    }

    if (hrefPath === '/payroll/runs' && actual.get('status') === 'review') {
        return false;
    }

    return path === hrefPath || path.startsWith(`${hrefPath}/`);
}
