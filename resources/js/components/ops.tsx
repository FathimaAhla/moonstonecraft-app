import { Link, router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};

export function rs(value: number | string | null | undefined, digits = 2): string {
    const n = Number(value ?? 0);

    return `Rs. ${n.toLocaleString('en-LK', { minimumFractionDigits: digits, maximumFractionDigits: digits })}`;
}

export function num(value: number | string | null | undefined, digits = 2): string {
    return Number(value ?? 0).toLocaleString('en-LK', { maximumFractionDigits: digits });
}

export function pct(value: number): string {
    return `${(value * 100).toFixed(1)}%`;
}

export function today(): string {
    return new Date().toISOString().slice(0, 10);
}

export function PageHeader({ title, description, children }: { title: string; description?: string; children?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight text-foreground">{title}</h1>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

export function Page({ children }: { children: ReactNode }) {
    return <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">{children}</div>;
}

export function Panel({ title, children, className, actions, flush = false }: { title?: string; children: ReactNode; className?: string; actions?: ReactNode; flush?: boolean }) {
    return (
        <section className={cn('overflow-hidden rounded-lg border bg-card shadow-xs', className)}>
            {(title || actions) && (
                <div className="flex items-center justify-between gap-2 border-b px-4 py-3">
                    {title && <h2 className="text-sm font-semibold">{title}</h2>}
                    {actions}
                </div>
            )}
            <div className={flush ? '' : 'p-4'}>{children}</div>
        </section>
    );
}

export function Table({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div className="overflow-x-auto">
            <table
                className={cn(
                    'w-full text-sm [&_td]:px-3 [&_td]:py-2 [&_td]:whitespace-nowrap [&_th]:h-10 [&_th]:whitespace-nowrap [&_th]:px-3 [&_th]:text-left [&_th]:text-xs [&_th]:font-medium [&_th]:tracking-wide [&_th]:text-muted-foreground [&_th]:uppercase [&_thead]:border-b [&_thead]:bg-muted/60 [&_tbody_tr]:border-b [&_tbody_tr]:border-border/70 [&_tbody_tr:last-child]:border-0 [&_tbody_tr:hover]:bg-muted/40',
                    className,
                )}
            >
                {children}
            </table>
        </div>
    );
}

export function EmptyRow({ colSpan, children }: { colSpan: number; children: ReactNode }) {
    return (
        <tr>
            <td colSpan={colSpan} className="py-8! text-center text-muted-foreground">
                {children}
            </td>
        </tr>
    );
}

/**
 * Create / edit form in a modal. The page owns the open state and closes it on success.
 */
export function FormModal({
    open,
    onOpenChange,
    title,
    description,
    onSubmit,
    processing,
    submitLabel = 'Save',
    wide = false,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    onSubmit: () => void;
    processing?: boolean;
    submitLabel?: string;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn('max-h-[90vh] overflow-y-auto', wide ? 'sm:max-w-3xl' : 'sm:max-w-lg')}>
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        onSubmit();
                    }}
                >
                    <DialogHeader className="border-b pb-4">
                        <DialogTitle>{title}</DialogTitle>
                        {description && <DialogDescription>{description}</DialogDescription>}
                    </DialogHeader>
                    <div className="grid gap-4">{children}</div>
                    <DialogFooter className="border-t pt-4">
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function EditButton({ onClick, label = 'Edit' }: { onClick: () => void; label?: string }) {
    return (
        <button type="button" onClick={onClick} title={label} aria-label={label} className="inline-flex size-7 items-center justify-center rounded-md text-primary hover:bg-muted">
            <Pencil className="size-4" />
        </button>
    );
}

export function Field({ label, error, className, children }: { label: string; error?: string; className?: string; children: ReactNode }) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

export function NativeSelect({ className, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30 [&>option]:bg-background',
                className,
            )}
            {...props}
        />
    );
}

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
    return (
        <textarea
            className={cn(
                'flex min-h-16 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                className,
            )}
            {...props}
        />
    );
}

export function NumberInput(props: ComponentProps<typeof Input>) {
    return <Input type="number" step="any" inputMode="decimal" {...props} />;
}

export function Stat({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'good' | 'bad' }) {
    return (
        <div className="relative overflow-hidden rounded-lg border bg-card p-4 shadow-xs">
            <span className={cn('absolute inset-y-0 left-0 w-1 bg-brand-teal', tone === 'good' && 'bg-brand-leaf', tone === 'bad' && 'bg-brand-coral')} />
            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</div>
            <div className={cn('mt-1 text-xl font-semibold whitespace-nowrap tabular-nums xl:text-2xl', tone === 'good' && 'text-brand-leaf dark:text-emerald-400', tone === 'bad' && 'text-brand-coral')}>
                {value}
            </div>
            {hint && <div className="mt-1 text-xs text-muted-foreground">{hint}</div>}
        </div>
    );
}

const statusStyles: Record<string, string> = {
    paid: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    partial: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    unpaid: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    cod_pending: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant="outline" className={cn('border-transparent capitalize', statusStyles[status])}>
            {status.replace('_', ' ')}
        </Badge>
    );
}

export function Pagination({ links }: { links: Paginated<unknown>['links'] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap gap-1">
            {links.map((link, i) =>
                link.url ? (
                    <Link
                        key={i}
                        href={link.url}
                        preserveScroll
                        className={cn('rounded-md border px-2.5 py-1 text-sm', link.active && 'bg-primary text-primary-foreground')}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span key={i} className="rounded-md px-2.5 py-1 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: link.label }} />
                ),
            )}
        </nav>
    );
}

export function destroyWithConfirm(url: string, message = 'Delete this item?'): void {
    if (window.confirm(message)) {
        router.delete(url, { preserveScroll: true });
    }
}

export function DeleteButton({ url, message, label = 'Delete', withText = false }: { url: string; message?: string; label?: string; withText?: boolean }) {
    if (withText) {
        return (
            <Button type="button" variant="outline" onClick={() => destroyWithConfirm(url, message)} className="text-red-600 hover:text-red-700 dark:text-red-400">
                <Trash2 /> {label}
            </Button>
        );
    }

    return (
        <button type="button" onClick={() => destroyWithConfirm(url, message)} title={label} aria-label={label} className="inline-flex size-7 items-center justify-center rounded-md text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950">
            <Trash2 className="size-4" />
        </button>
    );
}
