import type { Workspace } from '@/types/hrms';
import { Link } from '@inertiajs/react';

export function PayrollNav({ workspace }: { workspace: Workspace }) {
    if (!workspace.permissions.payrollView && !workspace.permissions.payrollImport) {
        return null;
    }

    const links = [
        ['/payroll', 'Payroll'],
        ...(workspace.permissions.payrollView ? [['/payroll/runs', 'Legacy payroll']] : []),
        ['/payslips', 'Payslips'],
    ];

    return (
        <nav className="mb-4 flex flex-wrap gap-2" aria-label="Payroll">
            {links.map(([href, label]) => (
                <Link key={href} href={href} className="rounded-full bg-white px-3 py-1 text-sm text-slate-700 ring-1 ring-slate-200 hover:text-blue-700">{label}</Link>
            ))}
        </nav>
    );
}
