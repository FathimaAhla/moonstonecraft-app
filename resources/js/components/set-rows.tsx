import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { NativeSelect, NumberInput } from '@/components/ops';
import { DesignPicker } from '@/components/paint-dots';
import type { FigureCombo, PaintSet, Pickable } from '@/components/paint-dots';

export type FigureRow = { combo_id: string; designs: Record<string, number>; qty: string };
export type PaintRow = { component_id: string; qty: string };

/** What a kit needs to show set rows. */
export type SetNeeds = { figures_per_kit: number; paint_pots_per_kit: number } & Pickable;

/**
 * Rows typed on a sale or packing, in the shape the server takes.
 */
export function setRowsPayload(figures: FigureRow[], paint: PaintRow[]) {
    return {
        figures: figures.filter((r) => r.combo_id || Object.values(r.designs).some(Boolean))
            .map((r) => (r.combo_id && r.combo_id !== 'new' ? { combo_id: r.combo_id, qty: r.qty } : { designs: r.designs, qty: r.qty })),
        paint: paint.filter((r) => r.component_id).map((r) => ({ component_id: r.component_id, qty: r.qty })),
    };
}

const comboHint = (c: FigureCombo) => [`${c.stock} sets`, `+${c.from_figures} from figures`, c.packed ? `${c.packed} packed` : null].filter(Boolean).join(', ');
const paintHint = (p: PaintSet) => [`${p.stock} sets`, p.packed ? `${p.packed} packed` : null].filter(Boolean).join(', ');

/**
 * Which figure sets went into how many kits, e.g. Car + Bike + Bus × 2. Optional: kits
 * without a row are "not chosen yet" and can be filled in later.
 */
export function FigureRows({ needs, total, rows, error, onChange }: { needs: SetNeeds; total: number; rows: FigureRow[]; error?: string; onChange: (rows: FigureRow[]) => void }) {
    const used = rows.reduce((sum, r) => sum + (Number(r.qty) || 0), 0);
    const set = (i: number, patch: Partial<FigureRow>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    return (
        <div className="rounded-md border p-2 text-sm">
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <span className="font-medium">Figures ({needs.figures_per_kit} per kit)</span>
                <span className={`text-xs ${used > total ? 'text-brand-coral' : 'text-muted-foreground'}`}>{Math.max(total - used, 0)} of {total} not chosen yet</span>
            </div>
            <div className="space-y-2">
                {rows.map((r, i) => (
                    <div key={i} className="space-y-1.5">
                        <div className="flex items-center gap-2">
                            <NativeSelect value={r.combo_id} onChange={(e) => set(i, { combo_id: e.target.value })}>
                                <option value="">Choose figure set...</option>
                                {needs.figure_combos.map((c) => <option key={c.id} value={c.id}>{c.name} ({comboHint(c)})</option>)}
                                <option value="new">New combination (pick figures)...</option>
                            </NativeSelect>
                            <NumberInput className="w-20" aria-label="Kits" value={r.qty} onChange={(e) => set(i, { qty: e.target.value })} />
                            <Button type="button" variant="ghost" size="icon" aria-label="Remove row" onClick={() => onChange(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
                        </div>
                        {r.combo_id === 'new' && <DesignPicker designs={needs.designs} value={r.designs} onChange={(designs) => set(i, { designs })} target={needs.figures_per_kit} />}
                    </div>
                ))}
            </div>
            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => onChange([...rows, { combo_id: '', designs: {}, qty: String(Math.max(total - used, 1)) }])}>Add figures row</Button>
            {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
        </div>
    );
}

/**
 * Which paint set went into how many kits. Optional, like figures.
 */
export function PaintRows({ needs, total, rows, error, onChange }: { needs: SetNeeds; total: number; rows: PaintRow[]; error?: string; onChange: (rows: PaintRow[]) => void }) {
    const used = rows.reduce((sum, r) => sum + (Number(r.qty) || 0), 0);
    const set = (i: number, patch: Partial<PaintRow>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    return (
        <div className="rounded-md border p-2 text-sm">
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <span className="font-medium">Paint set ({needs.paint_pots_per_kit}-pot)</span>
                <span className={`text-xs ${used > total ? 'text-brand-coral' : 'text-muted-foreground'}`}>{Math.max(total - used, 0)} of {total} not chosen yet</span>
            </div>
            <div className="space-y-2">
                {rows.map((r, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <NativeSelect value={r.component_id} onChange={(e) => set(i, { component_id: e.target.value })}>
                            <option value="">Choose paint set...</option>
                            {needs.paint_sets.map((p) => <option key={p.id} value={p.id}>{p.name} ({paintHint(p)})</option>)}
                        </NativeSelect>
                        <NumberInput className="w-20" aria-label="Kits" value={r.qty} onChange={(e) => set(i, { qty: e.target.value })} />
                        <Button type="button" variant="ghost" size="icon" aria-label="Remove row" onClick={() => onChange(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
                    </div>
                ))}
            </div>
            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => onChange([...rows, { component_id: '', qty: String(Math.max(total - used, 1)) }])}>Add paint row</Button>
            {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
        </div>
    );
}
