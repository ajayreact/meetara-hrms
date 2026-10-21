import type { ReactNode } from 'react';

export const headCell = 'sticky top-0 z-10 bg-blue-50 px-3 py-3 text-left text-xs font-semibold tracking-wide text-slate-600 uppercase';
export const bodyCell = 'h-[52px] border-t border-slate-100 px-3 align-middle';
export const bodyRow = 'hover:bg-blue-50/60';
export const selectedRow = 'bg-blue-50 shadow-[inset_3px_0_0_0_#1d4ed8]';

export function DataTable({ children }: { children: ReactNode }) {
    return (
        <div className="max-h-[32rem] overflow-auto rounded-2xl border border-slate-200">
            <table className="w-full min-w-[720px] border-separate border-spacing-0 text-left text-sm">
                {children}
            </table>
        </div>
    );
}
