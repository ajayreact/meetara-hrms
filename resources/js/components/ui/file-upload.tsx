import { cn } from '@/lib/utils';
import { useId, useRef, useState } from 'react';

interface FileUploadProps {
    id?: string;
    name?: string;
    accept?: string;
    disabled?: boolean;
    required?: boolean;
    hint?: string;
    error?: string;
    buttonLabel?: string;
    emptyLabel?: string;
    file?: File | null;
    ariaLabel?: string;
    className?: string;
    resetAfterChange?: boolean;
    onChange?: (file: File | null) => void;
}

export function FileUpload({
    id,
    name,
    accept,
    disabled = false,
    required = false,
    hint,
    error,
    buttonLabel = 'Choose File',
    emptyLabel = 'No file selected',
    file,
    ariaLabel,
    className,
    resetAfterChange = false,
    onChange,
}: FileUploadProps) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const inputRef = useRef<HTMLInputElement>(null);
    const [pickedName, setPickedName] = useState('');
    const shownName = file ? file.name : (file === null ? '' : pickedName);

    return (
        <div className={cn('grid gap-1.5', className)}>
            <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                <label
                    htmlFor={inputId}
                    className={cn(
                        'inline-flex h-10 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-slate-900 shadow-sm hover:bg-slate-50 focus-within:ring-2 focus-within:ring-blue-600 focus-within:ring-offset-2',
                        disabled && 'pointer-events-none opacity-50',
                    )}
                >
                    {buttonLabel}
                    <input
                        ref={inputRef}
                        id={inputId}
                        name={name}
                        type="file"
                        accept={accept}
                        disabled={disabled}
                        required={required}
                        aria-label={ariaLabel}
                        aria-invalid={error ? true : undefined}
                        className="sr-only"
                        onChange={(event) => {
                            const next = event.target.files?.[0] ?? null;
                            setPickedName(next?.name ?? '');
                            onChange?.(next);
                            if (resetAfterChange && inputRef.current) {
                                inputRef.current.value = '';
                            }
                        }}
                    />
                </label>
                <span className="min-w-0 truncate text-sm text-slate-600" aria-live="polite">
                    {shownName || emptyLabel}
                </span>
            </div>
            {hint ? <p className="text-xs leading-5 text-slate-500">{hint}</p> : null}
            {error ? <p className="text-sm text-rose-700" role="alert">{error}</p> : null}
        </div>
    );
}
