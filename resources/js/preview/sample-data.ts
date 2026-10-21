import { summarizeMonth, type TimeLogDay } from '@/lib/attendance-metrics';

/**
 * Sample figures for interface review only.
 * Do not import this module from payroll, attendance, or employee persistence code.
 */
export const previewAsOf = '2026-10-19';

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export function octoberTimeLogs(): TimeLogDay[] {
    const asOf = 19;
    const lateDays: Record<number, number> = { 5: 22 };
    const earlyDays: Record<number, number> = { 13: 57 };
    const missingDays = new Set([14]);

    return Array.from({ length: 31 }, (_, index) => {
        const day = index + 1;
        const date = new Date(Date.UTC(2026, 9, day));
        const dow = date.getUTCDay();
        const weekday = weekdays[dow];
        const expected = dow !== 0 && dow !== 6;
        const occurred = day <= asOf;
        const missing = expected && occurred && missingDays.has(day);
        const worked = expected && occurred && !missing;
        const lateMinutes = worked ? (lateDays[day] ?? 0) : 0;
        const earlyMinutes = worked ? (earlyDays[day] ?? 0) : 0;
        const breakMinutes = worked ? (day % 4 === 0 ? 70 : 35) : 0;
        const workMinutes = worked ? 480 - breakMinutes - earlyMinutes - (lateMinutes > 0 ? 15 : 0) : 0;

        return {
            day,
            dateLabel: date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }),
            weekday,
            expected,
            occurred,
            workMinutes,
            breakMinutes,
            missing,
            lateMinutes,
            earlyMinutes,
            clockIn: worked ? (lateMinutes > 0 ? '09:52 AM' : '09:30 AM') : null,
            clockOut: worked && day < asOf ? (earlyMinutes > 0 ? '05:03 PM' : '06:15 PM') : null,
            clockInIp: worked ? '203.0.113.24' : null,
            clockOutIp: worked && day < asOf ? '203.0.113.24' : null,
            networkStatus: worked ? 'verified' : 'not_recorded',
        };
    });
}

export const octoberSummary = summarizeMonth(octoberTimeLogs());

export interface LeaveBalance {
    code: string;
    name: string;
    booked: number;
    balance: number;
    tone: 'green' | 'amber' | 'rose';
}

export const sampleLeaveBalances: LeaveBalance[] = [
    { code: 'PL', name: 'Paid Leave', booked: 5.75, balance: 3.75, tone: 'green' },
    { code: 'SL', name: 'Sick Leave', booked: 5.75, balance: 0.25, tone: 'amber' },
    { code: 'LW', name: 'Without Pay', booked: 2.5, balance: 0, tone: 'rose' },
];

export interface HolidayItem {
    id: string;
    name: string;
    date: string;
    weekday: string;
    region: 'United States' | 'India';
    office: string;
    optional: boolean;
}

export const sampleHolidays: HolidayItem[] = [
    { id: 'us-veterans', name: 'Veterans Day', date: '2026-11-11', weekday: 'Wednesday', region: 'United States', office: 'All United States offices', optional: false },
    { id: 'us-thanks', name: 'Thanksgiving', date: '2026-11-26', weekday: 'Thursday', region: 'United States', office: 'All United States offices', optional: false },
    { id: 'us-day-after', name: 'Day after Thanksgiving', date: '2026-11-27', weekday: 'Friday', region: 'United States', office: 'Austin Headquarters', optional: true },
    { id: 'us-christmas', name: 'Christmas', date: '2026-12-25', weekday: 'Friday', region: 'United States', office: 'All United States offices', optional: false },
    { id: 'in-dussehra', name: 'Dussehra', date: '2026-10-20', weekday: 'Tuesday', region: 'India', office: 'Bengaluru Office', optional: false },
    { id: 'in-diwali', name: 'Diwali', date: '2026-11-08', weekday: 'Sunday', region: 'India', office: 'Bengaluru Office', optional: false },
    { id: 'in-diwali-leave', name: 'Diwali Vacation', date: '2026-11-09', weekday: 'Monday', region: 'India', office: 'Bengaluru Office', optional: false },
];

export interface PreviewEmployee {
    id: string;
    code: string;
    name: string;
    email: string;
    department: string;
    designation: string;
    manager: string;
    office: string;
    joined: string;
    status: 'Active' | 'Invited' | 'Inactive';
    team: boolean;
    phone: string;
}

export const sampleEmployees: PreviewEmployee[] = [
    { id: 'e1', code: 'VX-1042', name: 'Olive Smith', email: 'olive.smith@veltrix.example', department: 'Design', designation: 'UI/UX Designer', manager: 'Noah Bennett', office: 'Austin Headquarters', joined: '09 Mar 2024', status: 'Active', team: true, phone: '+1 512 555 0142' },
    { id: 'e2', code: 'VX-1088', name: 'Noah Bennett', email: 'noah.bennett@veltrix.example', department: 'Design', designation: 'Team Lead', manager: 'Hannah Cole', office: 'Austin Headquarters', joined: '14 Jan 2023', status: 'Active', team: true, phone: '+1 512 555 0177' },
    { id: 'e3', code: 'VX-1110', name: 'Daniel Brooks', email: 'daniel.brooks@veltrix.example', department: 'Engineering', designation: 'Frontend Engineer', manager: 'Noah Bennett', office: 'Austin Headquarters', joined: '02 Jun 2025', status: 'Active', team: true, phone: '+1 512 555 0190' },
    { id: 'e4', code: 'VX-1118', name: 'Maya Iyer', email: 'maya.iyer@veltrix.example', department: 'Engineering', designation: 'QA Engineer', manager: 'Noah Bennett', office: 'Newark Office', joined: '18 Aug 2025', status: 'Active', team: true, phone: '+1 973 555 0114' },
    { id: 'e5', code: 'VX-0981', name: 'Ethan Cole', email: 'ethan.cole@veltrix.example', department: 'People', designation: 'HR Executive', manager: 'Meera Shah', office: 'Austin Headquarters', joined: '11 Nov 2022', status: 'Active', team: false, phone: '+1 512 555 0166' },
    { id: 'e6', code: 'VX-1204', name: 'Priya Nair', email: 'priya.nair@veltrix.example', department: 'Finance', designation: 'Payroll Specialist', manager: 'Liam Ortiz', office: 'Newark Office', joined: '07 Jan 2026', status: 'Invited', team: false, phone: '+1 973 555 0133' },
    { id: 'e7', code: 'DD-2201', name: 'Aisha Rahman', email: 'aisha.rahman@deepdata.example', department: 'Engineering', designation: 'Data Analyst', manager: 'Rahul Menon', office: 'Bengaluru Office', joined: '22 Apr 2024', status: 'Active', team: false, phone: '+91 80 5550 2211' },
    { id: 'e8', code: 'VX-0877', name: 'Grace Hale', email: 'grace.hale@veltrix.example', department: 'Design', designation: 'Product Designer', manager: 'Noah Bennett', office: 'Austin Headquarters', joined: '30 Sep 2021', status: 'Inactive', team: true, phone: '+1 512 555 0108' },
];

export interface AttendanceRow {
    id: string;
    employeeId: string;
    name: string;
    office: string;
    team: boolean;
    date: string;
    clockIn: string | null;
    clockOut: string | null;
    breakLabel: string;
    workLabel: string;
    late: boolean;
    early: boolean;
    status: 'Present' | 'Absent' | 'On break' | 'Missing';
    clockInIp: string | null;
    clockOutIp: string | null;
    networkStatus: 'Verified gateway' | 'Unverified' | 'Not recorded';
}

export const sampleAttendanceRows: AttendanceRow[] = [
    { id: 'a1', employeeId: 'e1', name: 'Olive Smith', office: 'Austin Headquarters', team: true, date: 'Mon 19 Oct 2026', clockIn: '09:30 AM', clockOut: null, breakLabel: '00 H : 20 M', workLabel: '04 H : 15 M', late: false, early: false, status: 'On break', clockInIp: '203.0.113.24', clockOutIp: null, networkStatus: 'Verified gateway' },
    { id: 'a2', employeeId: 'e2', name: 'Noah Bennett', office: 'Austin Headquarters', team: true, date: 'Mon 19 Oct 2026', clockIn: '09:06 AM', clockOut: null, breakLabel: '00 H : 00 M', workLabel: '05 H : 40 M', late: false, early: false, status: 'Present', clockInIp: '203.0.113.24', clockOutIp: null, networkStatus: 'Verified gateway' },
    { id: 'a3', employeeId: 'e3', name: 'Daniel Brooks', office: 'Austin Headquarters', team: true, date: 'Mon 19 Oct 2026', clockIn: '09:41 AM', clockOut: null, breakLabel: '00 H : 15 M', workLabel: '05 H : 02 M', late: true, early: false, status: 'Present', clockInIp: '203.0.113.24', clockOutIp: null, networkStatus: 'Verified gateway' },
    { id: 'a4', employeeId: 'e4', name: 'Maya Iyer', office: 'Newark Office', team: true, date: 'Mon 19 Oct 2026', clockIn: '09:28 AM', clockOut: '04:40 PM', breakLabel: '00 H : 30 M', workLabel: '06 H : 42 M', late: false, early: true, status: 'Present', clockInIp: '203.0.113.30', clockOutIp: '203.0.113.30', networkStatus: 'Verified gateway' },
    { id: 'a5', employeeId: 'e5', name: 'Ethan Cole', office: 'Austin Headquarters', team: false, date: 'Mon 19 Oct 2026', clockIn: null, clockOut: null, breakLabel: '00 H : 00 M', workLabel: '00 H : 00 M', late: false, early: false, status: 'Absent', clockInIp: null, clockOutIp: null, networkStatus: 'Not recorded' },
    { id: 'a6', employeeId: 'e6', name: 'Priya Nair', office: 'Newark Office', team: false, date: 'Mon 19 Oct 2026', clockIn: null, clockOut: null, breakLabel: '00 H : 00 M', workLabel: '00 H : 00 M', late: false, early: false, status: 'Missing', clockInIp: null, clockOutIp: null, networkStatus: 'Unverified' },
];

export interface OfficeLocation {
    id: string;
    company: string;
    name: string;
    address: string;
    timezone: string;
    workingDays: string;
    shifts: string;
    status: 'Active' | 'Inactive';
    gateway: string;
    verification: 'Verified' | 'Pending server check' | 'Not configured';
    policy: string;
}

export const sampleOffices: OfficeLocation[] = [
    {
        id: 'aus',
        company: 'Veltrix Digital Solutions Inc',
        name: 'Austin Headquarters',
        address: '600 Congress Avenue, Austin, TX 78701',
        timezone: 'America/Chicago',
        workingDays: 'Monday – Friday',
        shifts: '09:00 – 18:00',
        status: 'Active',
        gateway: '203.0.113.24',
        verification: 'Pending server check',
        policy: 'Clock-in is accepted only when the server sees an authorized office gateway address.',
    },
    {
        id: 'ewr',
        company: 'Veltrix Digital Solutions Inc',
        name: 'Newark Office',
        address: '1 Riverfront Plaza, Newark, NJ 07102',
        timezone: 'America/New_York',
        workingDays: 'Monday – Friday',
        shifts: '09:00 – 17:30',
        status: 'Active',
        gateway: '203.0.113.30',
        verification: 'Not configured',
        policy: 'Gateway verification is required before office attendance can be marked verified.',
    },
    {
        id: 'blr',
        company: 'Deep Data Inc',
        name: 'Bengaluru Office',
        address: '80 Feet Road, Indiranagar, Bengaluru 560038',
        timezone: 'Asia/Kolkata',
        workingDays: 'Monday – Friday',
        shifts: '10:00 – 19:00',
        status: 'Active',
        gateway: '198.51.100.18',
        verification: 'Pending server check',
        policy: 'India holiday calendar and Asia/Kolkata office hours apply to this location.',
    },
];

export interface PayslipPreview {
    id: string;
    period: string;
    range: string;
    gross: number;
    deductions: number;
    net: number;
    currency: 'USD';
    earnings: { label: string; amount: number }[];
    deductionLines: { label: string; amount: number }[];
}

export const samplePayslips: PayslipPreview[] = [
    {
        id: 'preview-2026-09',
        period: 'September 2026',
        range: '01 Sep 2026 – 30 Sep 2026',
        gross: 8420,
        deductions: 1864.5,
        net: 6555.5,
        currency: 'USD',
        earnings: [
            { label: 'Basic salary', amount: 6200 },
            { label: 'House allowance', amount: 1500 },
            { label: 'Special allowance', amount: 720 },
        ],
        deductionLines: [
            { label: 'Federal income tax', amount: 1240 },
            { label: 'Social security', amount: 421.5 },
            { label: 'Health plan', amount: 203 },
        ],
    },
    {
        id: 'preview-2026-08',
        period: 'August 2026',
        range: '01 Aug 2026 – 31 Aug 2026',
        gross: 8420,
        deductions: 1790,
        net: 6630,
        currency: 'USD',
        earnings: [
            { label: 'Basic salary', amount: 6200 },
            { label: 'House allowance', amount: 1500 },
            { label: 'Special allowance', amount: 720 },
        ],
        deductionLines: [
            { label: 'Federal income tax', amount: 1180 },
            { label: 'Social security', amount: 421 },
            { label: 'Health plan', amount: 189 },
        ],
    },
];

export const sampleCompanies = [
    { name: 'Veltrix Digital Solutions Inc', status: 'Active', plan: 'Growth', seats: '128 listed', renewal: '12 Jan 2027', offices: 2 },
    { name: 'Deep Data Inc', status: 'Active', plan: 'Starter', seats: '46 listed', renewal: '03 Mar 2027', offices: 1 },
];

export const sampleAudit = [
    { id: 'l1', when: '19 Oct 2026, 09:14', actor: 'Hannah Cole', action: 'Opened company settings', company: 'Veltrix Digital Solutions Inc' },
    { id: 'l2', when: '18 Oct 2026, 16:02', actor: 'Avery Lang', action: 'Viewed subscription status', company: 'Deep Data Inc' },
    { id: 'l3', when: '18 Oct 2026, 11:27', actor: 'Meera Shah', action: 'Reviewed a leave request', company: 'Veltrix Digital Solutions Inc' },
];

export const sampleInbox = [
    { id: 'm1', from: 'Meera Shah', subject: 'Leave request received', time: 'Today, 10:15 AM', unread: true },
    { id: 'm2', from: 'People team', subject: 'Holiday calendar for your office', time: 'Yesterday', unread: false },
    { id: 'm3', from: 'Help Desk', subject: 'Ticket HD-104 is waiting on you', time: '17 Oct 2026', unread: false },
];

export const sampleTickets = [
    { id: 'HD-104', category: 'System Admin', title: 'VPN access for Austin office', priority: 'High', assignee: 'Ethan Cole', status: 'Open', comments: 3, updated: '19 Oct 2026' },
    { id: 'HD-098', category: 'Payroll', title: 'Question about August payslip preview', priority: 'Medium', assignee: 'Liam Ortiz', status: 'Waiting', comments: 1, updated: '16 Oct 2026' },
    { id: 'HD-091', category: 'Facilities', title: 'Badge not opening Newark floor 4', priority: 'Low', assignee: 'Unassigned', status: 'Resolved', comments: 5, updated: '12 Oct 2026' },
];
