import { ChevronsUpDown, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

export type SearchOption = { value: string; label: string; hint?: string };

/**
 * Searchable dropdown. The list renders in a portal so table wrappers never clip it.
 */
export function SearchSelect({
    value,
    options,
    onChange,
    placeholder = 'Select...',
    disabled = false,
    invalid = false,
    clearable = false,
}: {
    value: string;
    options: SearchOption[];
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    clearable?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [active, setActive] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);

    const selected = options.find((o) => o.value === value);
    const filtered = useMemo(() => {
        const t = term.trim().toLowerCase();

        return t ? options.filter((o) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(t)) : options;
    }, [options, term]);

    const close = () => {
        setOpen(false);
        setTerm('');
    };

    const openPanel = () => {
        if (disabled) {
            return;
        }

        setRect(trigger.current?.getBoundingClientRect() ?? null);
        setActive(0);
        setOpen(true);
    };

    useEffect(() => {
        if (!open) {
            return;
        }

        const onDown = (e: MouseEvent) => {
            if (!panel.current?.contains(e.target as Node) && !trigger.current?.contains(e.target as Node)) {
                close();
            }
        };
        const onMove = (e: Event) => {
            if (!panel.current?.contains(e.target as Node)) {
                close();
            }
        };

        document.addEventListener('mousedown', onDown);
        window.addEventListener('scroll', onMove, true);
        window.addEventListener('resize', onMove);

        return () => {
            document.removeEventListener('mousedown', onDown);
            window.removeEventListener('scroll', onMove, true);
            window.removeEventListener('resize', onMove);
        };
    }, [open]);

    const pick = (option: SearchOption) => {
        onChange(option.value);
        close();
        trigger.current?.focus();
    };

    return (
        <>
            <div className="relative">
                <button
                    ref={trigger}
                    type="button"
                    disabled={disabled}
                    aria-invalid={invalid}
                    onClick={() => (open ? close() : openPanel())}
                    className={cn(
                        'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-1 text-left text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
                        invalid && 'border-destructive',
                    )}
                >
                    <span className={cn('truncate', !selected && 'text-muted-foreground')}>{selected ? selected.label : placeholder}</span>
                    <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                </button>
                {clearable && selected && !disabled && (
                    <button type="button" aria-label="Clear" onClick={() => onChange('')} className="absolute top-1/2 right-8 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground">
                        <X className="size-3.5" />
                    </button>
                )}
            </div>
            {open &&
                rect &&
                createPortal(
                    <div
                        ref={panel}
                        style={{ position: 'fixed', top: rect.bottom + 4, left: rect.left, width: Math.max(rect.width, 240) }}
                        className="z-[100] rounded-md border bg-popover text-popover-foreground shadow-md"
                    >
                        <input
                            autoFocus
                            value={term}
                            placeholder="Search..."
                            onChange={(e) => {
                                setTerm(e.target.value);
                                setActive(0);
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'ArrowDown') {
                                    e.preventDefault();
                                    setActive((a) => Math.min(a + 1, filtered.length - 1));
                                } else if (e.key === 'ArrowUp') {
                                    e.preventDefault();
                                    setActive((a) => Math.max(a - 1, 0));
                                } else if (e.key === 'Enter') {
                                    e.preventDefault();

                                    if (filtered[active]) {
                                        pick(filtered[active]);
                                    }
                                } else if (e.key === 'Escape') {
                                    e.preventDefault();
                                    close();
                                    trigger.current?.focus();
                                }
                            }}
                            className="w-full rounded-t-md border-b bg-transparent px-3 py-2 text-sm outline-none"
                        />
                        <ul className="max-h-60 overflow-y-auto py-1">
                            {filtered.length === 0 && <li className="px-3 py-2 text-sm text-muted-foreground">No matches</li>}
                            {filtered.map((o, i) => (
                                <li key={o.value}>
                                    <button
                                        type="button"
                                        onMouseEnter={() => setActive(i)}
                                        onClick={() => pick(o)}
                                        className={cn('flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-sm', i === active && 'bg-accent text-accent-foreground', o.value === value && 'font-medium')}
                                    >
                                        <span className="truncate">{o.label}</span>
                                        {o.hint && <span className="shrink-0 text-xs text-muted-foreground">{o.hint}</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>,
                    document.body,
                )}
        </>
    );
}
