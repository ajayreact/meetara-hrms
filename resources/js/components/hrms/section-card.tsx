export function SectionCard({ title, extra, children, className = '', divided = false }: { title: string; extra?: React.ReactNode; children: React.ReactNode; className?: string; divided?: boolean }) {
    return (
        <section className={`rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ${className}`}>
            <div className={`mb-3 flex flex-wrap items-center justify-between gap-3 ${divided ? 'border-b border-slate-100 pb-3' : ''}`}>
                <h2 className="text-[15px] font-semibold text-slate-950">{title}</h2>
                {extra}
            </div>
            {children}
        </section>
    );
}

const tones: Record<string, string> = {
    Present: 'bg-emerald-50 text-emerald-700',
    Late: 'bg-amber-50 text-amber-700',
    'Early out': 'bg-rose-50 text-rose-700',
    Active: 'bg-emerald-50 text-emerald-700',
    Open: 'bg-blue-50 text-blue-700',
    'In progress': 'bg-amber-50 text-amber-700',
    Waiting: 'bg-amber-50 text-amber-700',
    Pending: 'bg-amber-50 text-amber-700',
    'Pending approval': 'bg-amber-50 text-amber-800',
    Draft: 'bg-slate-100 text-slate-600',
    Generated: 'bg-blue-50 text-blue-700',
    Issued: 'bg-indigo-50 text-indigo-700',
    Declined: 'bg-rose-50 text-rose-700',
    Calculated: 'bg-blue-50 text-blue-700',
    'Under Review': 'bg-amber-50 text-amber-700',
    'Pending Review': 'bg-amber-50 text-amber-700',
    'Changes Requested': 'bg-amber-50 text-amber-800',
    Approved: 'bg-emerald-50 text-emerald-700',
    Processed: 'bg-emerald-50 text-emerald-700',
    Paid: 'bg-emerald-50 text-emerald-700',
    Reversed: 'bg-rose-50 text-rose-700',
    Rejected: 'bg-rose-50 text-rose-700',
    Cancelled: 'bg-slate-100 text-slate-600',
    Resolved: 'bg-slate-100 text-slate-600',
    Absent: 'bg-rose-50 text-rose-700',
    Holiday: 'bg-sky-50 text-sky-700',
    'Holiday Work': 'bg-indigo-50 text-indigo-700',
    'Weekly Off': 'bg-slate-100 text-slate-600',
    Missing: 'bg-rose-50 text-rose-700',
    Inactive: 'bg-slate-100 text-slate-500',
    Recovered: 'bg-slate-100 text-slate-600',
    Invited: 'bg-amber-50 text-amber-700',
    'Invitation Pending': 'bg-amber-50 text-amber-700',
    'Invitation Expired': 'bg-rose-50 text-rose-700',
    'On break': 'bg-orange-50 text-orange-700',
    High: 'bg-rose-50 text-rose-700',
    Medium: 'bg-amber-50 text-amber-700',
    Low: 'bg-slate-100 text-slate-600',
    'Sample preview': 'bg-amber-50 text-amber-800',
    Completed: 'bg-emerald-50 text-emerald-700',
    Verified: 'bg-emerald-50 text-emerald-700',
    'Not started': 'bg-slate-100 text-slate-600',
    Accepted: 'bg-emerald-50 text-emerald-700',
    'Not applicable': 'bg-slate-100 text-slate-600',
    Expired: 'bg-slate-100 text-slate-600',
    Revoked: 'bg-rose-50 text-rose-700',
    'Email not sent': 'bg-amber-50 text-amber-800',
    'Location configured': 'bg-emerald-50 text-emerald-700',
    'Location not configured': 'bg-slate-100 text-slate-600',
    'Approved network configured': 'bg-emerald-50 text-emerald-700',
    'Approved network missing': 'bg-rose-50 text-rose-700',
    'GPS verification on': 'bg-blue-50 text-blue-700',
    'GPS verification off': 'bg-slate-100 text-slate-600',
    'Attendance verification ready': 'bg-emerald-50 text-emerald-700',
    'Attendance verification blocked': 'bg-rose-50 text-rose-700',
    'Not linked': 'bg-slate-100 text-slate-600',
    Valid: 'bg-emerald-50 text-emerald-700',
    'Needs correction': 'bg-rose-50 text-rose-700',
};

export function StatusBadge({ value }: { value: string }) {
    return <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${tones[value] ?? 'bg-slate-100 text-slate-600'}`}>{value}</span>;
}
