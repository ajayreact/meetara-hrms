import type { ReactNode } from 'react';

export const headCell = 'sticky top-0 z-10 bg-blue-50 px-3 py-3 text-left text-xs font-semibold tracking-wide text-slate-600 uppercase';
export const bodyCell = 'h-[52px] border-t border-slate-100 px-3 align-middle';
export const bodyRow = 'hover:bg-blue-50/60';
export const selectedRow = 'bg-blue-50 shadow-[inset_3px_0_0_0_#1d4ed8]';

export function DataTable({ children, wide = false, fit = false }: { children: ReactNode; wide?: boolean; fit?: boolean }) {
    if (fit) {
        return (
            <div className="w-full min-w-0 max-w-full rounded-2xl border border-slate-200">
                <table className="w-full table-fixed border-separate border-spacing-0 text-left text-sm">
                    {children}
                </table>
            </div>
        );
    }

    return (
        <div className="max-h-[32rem] w-full min-w-0 max-w-full overflow-auto rounded-2xl border border-slate-200">
            <table className={`${wide ? 'w-max min-w-full' : 'w-full min-w-[720px]'} border-separate border-spacing-0 text-left text-sm`}>
                {children}
            </table>
        </div>
    );
}
