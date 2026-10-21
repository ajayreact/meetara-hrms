import type { Workspace } from '@/types/hrms';
import {
    Building2,
    CalendarCheck,
    CalendarDays,
    CalendarRange,
    Inbox,
    LayoutDashboard,
    LifeBuoy,
    Map,
    Receipt,
    ScrollText,
    Wallet,
    Settings,
    Timer,
    UserRound,
    Users,
    type LucideIcon,
} from 'lucide-react';

export interface SidebarItem {
    label: string;
    href: string;
    icon: LucideIcon;
    section: string;
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
    ];

    if (workspace.permissions.employees) {
        items.push({ label: 'Employees', href: '/employees', icon: Users, section: 'People' });
    }
    if (workspace.role === 'company_admin' || workspace.role === 'hr') {
        items.push({ label: 'Offer letters', href: '/offer-letters', icon: ScrollText, section: 'People' });
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

    if (workspace.permissions.routeMap) {
        items.push({ label: 'Route Map', href: '/route-map', icon: Map, section: 'Time' });
    }

    if (workspace.permissions.salaryManage) {
        items.push({ label: 'Salary', href: '/salary', icon: Wallet, section: 'Pay' });
    }

    if (workspace.permissions.payrollView) {
        items.push({ label: 'Payroll', href: '/payroll', icon: Receipt, section: 'Pay' });
        items.push({ label: 'Payroll Runs', href: '/payroll/runs', icon: Receipt, section: 'Pay' });
        items.push({ label: 'Payroll Review', href: '/payroll/runs?status=review', icon: Receipt, section: 'Pay' });
    }

    if (workspace.permissions.payrollPay) {
        items.push({ label: 'Payments', href: '/payments', icon: Receipt, section: 'Pay' });
    }

    items.push(
        { label: 'Overtime', href: '/overtime', icon: Timer, section: 'Time' },
        { label: 'Holidays', href: '/holidays', icon: CalendarDays, section: 'Time' },
    );

    if (workspace.role !== 'team_lead') {
        items.push({ label: 'Payslips', href: '/payslips', icon: Receipt, section: 'Pay' });
    }

    items.push({ label: 'Help Desk', href: '/help-desk', icon: LifeBuoy, section: 'Support' });

    if (workspace.permissions.offices) {
        items.push({ label: 'Offices', href: '/offices', icon: Building2, section: 'Support' });
    }

    if (workspace.permissions.settings) {
        items.push({ label: 'Settings', href: '/company/settings', icon: Settings, section: 'Support' });
    }

    return items;
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

    if (hrefPath === '/payroll/runs' && actual.get('status') === 'review') {
        return false;
    }

    return path === hrefPath || path.startsWith(`${hrefPath}/`);
}
