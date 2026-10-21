const stroke = {
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    pathLength: 120,
};

export function PlatformMark({ variant = 'building', className = 'size-10 text-blue-700' }: { variant?: 'building' | 'document'; className?: string }) {
    return (
        <svg className={`platform-mark shrink-0 ${className}`} viewBox="0 0 48 48" aria-hidden="true">
            {variant === 'building' ? (
                <>
                    <path {...stroke} d="M6 40 V20 L24 8 L42 20 V40" />
                    <path {...stroke} d="M18 40 V28 H30 V40" />
                    <path {...stroke} d="M32 18 L40 21 V31 L32 28 Z" />
                </>
            ) : (
                <>
                    <path {...stroke} d="M14 8 H28 L36 16 V40 H14 Z" />
                    <path {...stroke} d="M28 8 V16 H36" />
                    <path {...stroke} d="M19 24 H31" />
                    <path {...stroke} d="M19 30 H28" />
                </>
            )}
        </svg>
    );
}

export function PlatformEmpty({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center">
            <PlatformMark variant="document" />
            <p className="text-sm text-slate-500">{children}</p>
        </div>
    );
}
