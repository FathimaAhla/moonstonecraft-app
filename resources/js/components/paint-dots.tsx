import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { NativeSelect, NumberInput, num } from '@/components/ops';
import { cn } from '@/lib/utils';
import * as saleAssignments from '@/routes/sale-assignments';

export type PaintColour = { id: number; name: string; colour: string | null; ml: number };

export type PaintSet = { id: number; code: string; name: string; stock: number; reorder_level: number; colours: PaintColour[]; pots: number; unit_cost: number; packed?: number };

export type FigureCombo = {
    id: number; family: string; name: string; size: number; can_make: number; unit_cost: number;
    set_id: number | null; stock: number; reorder_level: number; from_figures: number; packed?: number;
    items: { id: number; name: string; qty: number; stock: number }[];
};

export type Design = { id: number; name: string; stock: number };

/** What can be picked for one kit when selling. */
export type Pickable = { figure_combos: FigureCombo[]; designs: Design[]; paint_sets: PaintSet[] };

export type AssignRow = { id: number; label: string; qty: number; from_packed: boolean };

/** What a sold kit line needs and already got. */
export type ItemPanel = {
    figures: { family: string | null; per_kit: number; rows: AssignRow[]; remaining: number } | null;
    paint: { pots: number; rows: AssignRow[]; remaining: number } | null;
};

/**
 * One round swatch per colour. A colour without a swatch shows as a dashed ring.
 */
export function ColourDots({ colours, className }: { colours: Pick<PaintColour, 'name' | 'colour'>[]; className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-0.5 align-middle', className)}>
            {colours.map((c, i) => (
                <span
                    key={i}
                    title={c.name}
                    className={cn('inline-block size-3.5 rounded-full border border-black/20 dark:border-white/30', !c.colour && 'border-dashed')}
                    style={c.colour ? { backgroundColor: c.colour } : undefined}
                />
            ))}
        </span>
    );
}

/**
 * A paint set as dots plus its name.
 */
export function PaintSetLabel({ set, className }: { set: Pick<PaintSet, 'name' | 'colours'>; className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-2', className)}>
            <ColourDots colours={set.colours} />
            <span>{set.name}</span>
        </span>
    );
}

/**
 * Buttons with counts for building a figure combination from designs. Click a name to add it,
 * again to add it twice.
 */
export function DesignPicker({ designs, value, onChange, target }: { designs: Design[]; value: Record<string, number>; onChange: (value: Record<string, number>) => void; target?: number }) {
    const total = Object.values(value).reduce((sum, n) => sum + n, 0);
    const set = (id: number, n: number) => onChange({ ...value, [id]: Math.max(0, n) });

    return (
        <div className="space-y-1.5">
            <div className="flex flex-wrap gap-2">
                {designs.map((d) => {
                    const n = value[d.id] ?? 0;

                    return (
                        <div key={d.id} className={cn('flex items-center gap-1 rounded-md border px-2 py-1 text-sm', n > 0 && 'border-brand-teal bg-brand-teal-tint/40')}>
                            <button type="button" className="px-1 font-medium" onClick={() => set(d.id, n + 1)}>{d.name}</button>
                            <span className={cn('text-xs tabular-nums', d.stock <= 0 ? 'text-brand-coral' : 'text-muted-foreground')}>({num(d.stock, 0)})</span>
                            {n > 0 && (
                                <>
                                    <span className="rounded bg-brand-teal px-1.5 text-xs font-semibold text-white tabular-nums">×{n}</span>
                                    <button type="button" aria-label={`One less ${d.name}`} className="px-1 text-muted-foreground" onClick={() => set(d.id, n - 1)}>−</button>
                                </>
                            )}
                        </div>
                    );
                })}
            </div>
            {target !== undefined && (
                <p className={cn('text-xs', total === target ? 'text-muted-foreground' : 'text-brand-coral')}>{total} of {target} figures picked. Click a name to add it, again to add it twice.</p>
            )}
        </div>
    );
}

/**
 * Figures or paint sets given to a sold kit line, with a form to add more for the kits not chosen yet.
 */
export function AssignSection({ itemId, type, title, rows, remaining, pickable, perKit }: {
    itemId: number; type: 'figures' | 'paint'; title: string; rows: AssignRow[]; remaining: number; pickable: Pickable; perKit: number;
}) {
    const form = useForm({ type, qty: String(remaining), combo_id: '', component_id: '', designs: {} as Record<string, number> });
    const errors = form.errors as Record<string, string>;
    const options: (FigureCombo | PaintSet)[] = type === 'figures' ? pickable.figure_combos : pickable.paint_sets;

    return (
        <div className="space-y-1.5">
            <div className="font-medium">{title}</div>
            {rows.map((r) => (
                <div key={r.id} className="flex items-center gap-2">
                    <span>{r.label} × {num(r.qty, 0)}{r.from_packed && <span className="text-muted-foreground"> (packed)</span>}</span>
                    <button type="button" aria-label="Remove row" className="text-muted-foreground hover:text-brand-coral" onClick={() => router.delete(saleAssignments.destroy.url(r.id), { preserveScroll: true })}><Trash2 className="size-3.5" /></button>
                </div>
            ))}
            {remaining > 0 ? (
                <form
                    className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-2 dark:border-amber-700 dark:bg-amber-950/40"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => (type === 'paint'
                            ? { type, qty: d.qty, component_id: d.component_id }
                            : d.combo_id && d.combo_id !== 'new' ? { type, qty: d.qty, combo_id: d.combo_id } : { type, qty: d.qty, designs: d.designs }));
                        form.post(saleAssignments.store.url(itemId), { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <div className="font-medium text-amber-800 dark:text-amber-300">{num(remaining, 0)} not chosen yet</div>
                    <div className="flex flex-wrap items-center gap-2">
                        <NativeSelect className="min-w-56" value={type === 'paint' ? form.data.component_id : form.data.combo_id} onChange={(e) => form.setData(type === 'paint' ? 'component_id' : 'combo_id', e.target.value)}>
                            <option value="">{type === 'paint' ? `Choose ${perKit}-pot set...` : `Choose ${perKit}-figure combination...`}</option>
                            {options.map((o) => <option key={o.id} value={o.id}>{o.name} ({'can_make' in o ? `${num(o.stock, 0)} sets + ${num(o.from_figures, 0)} from figures` : `${num(o.stock, 0)} sets`}{o.packed ? `, ${num(o.packed, 0)} packed` : ''})</option>)}
                            {type === 'figures' && <option value="new">New combination (pick figures)...</option>}
                        </NativeSelect>
                        <NumberInput className="w-20" value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} aria-label="Kits" />
                        <Button size="sm" disabled={form.processing}>Add</Button>
                    </div>
                    {type === 'figures' && form.data.combo_id === 'new' && <DesignPicker designs={pickable.designs} value={form.data.designs} onChange={(v) => form.setData('designs', v)} target={perKit} />}
                    {(errors.assign || errors.qty) && <p className="text-sm text-red-600">{errors.assign ?? errors.qty}</p>}
                </form>
            ) : (
                rows.length > 0 && <div className="text-muted-foreground">All chosen.</div>
            )}
        </div>
    );
}
