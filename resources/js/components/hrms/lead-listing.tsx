import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useEffect, useRef, useState } from 'react';

export const LEAD_PAGE_SIZES = [10, 25, 50, 100] as const;

export function useDeferredLeadSearch(applied: string, onSearch: (value: string) => void): { term: string; setTerm: (value: string) => void } {
    const [term, setTerm] = useState(applied);
    const appliedRef = useRef(applied);
    const searchRef = useRef(onSearch);
    searchRef.current = onSearch;

    useEffect(() => {
        appliedRef.current = applied;
        setTerm(applied);
    }, [applied]);

    useEffect(() => {
        const handle = window.setTimeout(() => {
            if (term.trim() !== appliedRef.current.trim()) {
                searchRef.current(term);
            }
        }, 300);

        return () => window.clearTimeout(handle);
    }, [term]);

    return { term, setTerm };
}

export function LeadSearchField({
    applied,
    onSearch,
    placeholder = 'Name, email, phone, or source',
    ariaLabel = 'Search leads',
}: {
    applied: string;
    onSearch: (value: string) => void;
    placeholder?: string;
    ariaLabel?: string;
}) {
    const { term, setTerm } = useDeferredLeadSearch(applied, onSearch);

    return (
        <div className="flex min-w-[16rem] flex-1 items-end gap-2">
            <label className="grid min-w-0 flex-1 gap-1 text-xs text-slate-500">
                Search
                <Input aria-label={ariaLabel} value={term} onChange={(event) => setTerm(event.target.value)} placeholder={placeholder} className="bg-white" />
            </label>
            <Button type="button" variant="ghost" disabled={term === '' && applied === ''} onClick={() => { setTerm(''); onSearch(''); }}>Clear</Button>
        </div>
    );
}

function pageItems(page: number, pages: number): Array<number | 'gap'> {
    if (pages <= 7) {
        return Array.from({ length: pages }, (_, index) => index + 1);
    }

    const items: Array<number | 'gap'> = [1];
    const start = Math.max(2, page - 1);
    const end = Math.min(pages - 1, page + 1);

    if (start > 2) {
        items.push('gap');
    }

    for (let number = start; number <= end; number += 1) {
        items.push(number);
    }

    if (end < pages - 1) {
        items.push('gap');
    }

    items.push(pages);

    return items;
}

export function LeadPagination({
    page,
    pages,
    matching,
    perPage,
    onPage,
    onPerPage,
}: {
    page: number;
    pages: number;
    matching: number;
    perPage: number;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}) {
    const start = matching === 0 ? 0 : (page - 1) * perPage + 1;
    const end = matching === 0 ? 0 : Math.min(page * perPage, matching);

    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
            <p className="text-sm text-slate-600">Showing {start}-{end} of {matching} · page {page} of {pages}</p>
            <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2 text-xs text-slate-500">
                    Rows
                    <select aria-label="Rows per page" className="h-8 rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-900" value={perPage} onChange={(event) => onPerPage(Number(event.target.value))}>
                        {LEAD_PAGE_SIZES.map((size) => <option key={size} value={size}>{size}</option>)}
                    </select>
                </label>
                <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>Previous</Button>
                {pageItems(page, pages).map((item, index) => (
                    item === 'gap'
                        ? <span key={`gap-${index}`} className="px-1 text-slate-400">...</span>
                        : <Button key={item} type="button" size="sm" variant={item === page ? 'default' : 'outline'} aria-current={item === page ? 'page' : undefined} onClick={() => onPage(item)}>{item}</Button>
                ))}
                <Button type="button" variant="outline" size="sm" disabled={page >= pages} onClick={() => onPage(page + 1)}>Next</Button>
            </div>
        </div>
    );
}
