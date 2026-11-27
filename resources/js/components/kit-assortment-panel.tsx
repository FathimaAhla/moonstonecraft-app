import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FormModal, NumberInput, Panel, Table, num, today } from '@/components/ops';
import { PaintSetLabel } from '@/components/paint-dots';
import type { FigureCombo, PaintSet, Pickable } from '@/components/paint-dots';
import { FigureRows, PaintRows, setRowsPayload } from '@/components/set-rows';
import type { FigureRow, PaintRow } from '@/components/set-rows';
import { cn } from '@/lib/utils';
import * as categories from '@/routes/categories';
import * as figures from '@/routes/figures';
import * as paintSets from '@/routes/paint-sets';
import * as stock from '@/routes/stock';

type KitNeeds = { id: number; code: string; figures_per_kit: number; paint_pots_per_kit: number };

/**
 * How many figures, paint pots and brushes the kit holds, read from its recipe, and the figure
 * sets and paint sets that fit it. Which ones go in is picked when packing or selling.
 */
export function KitAssortmentPanel({ option, pickable, packedUnrecorded, partsCanMake }: {
    option: KitNeeds & { kit_name: string; picks: boolean; brushes_per_kit: number };
    pickable: Pickable;
    packedUnrecorded: { figures: number; paint: number };
    partsCanMake: number | null;
}) {
    if (!option.picks) {
        return null;
    }

    return (
        <Panel title="Figures and paint (picked when packing or selling)">
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-md border px-3 py-2">
                    <div className="text-xs text-muted-foreground">Figures</div>
                    <div className="text-lg font-semibold tabular-nums">{option.figures_per_kit}</div>
                    <div className="text-xs text-muted-foreground">from {option.kit_name}</div>
                </div>
                <div className="rounded-md border px-3 py-2">
                    <div className="text-xs text-muted-foreground">Paint pots</div>
                    <div className="text-lg font-semibold tabular-nums">{option.paint_pots_per_kit}</div>
                    <div className="text-xs text-muted-foreground">{option.paint_pots_per_kit > 0 ? `a ${option.paint_pots_per_kit}-pot paint set` : 'no paint set'}</div>
                </div>
                <div className="rounded-md border px-3 py-2">
                    <div className="text-xs text-muted-foreground">Brushes</div>
                    <div className="text-lg font-semibold tabular-nums">{option.brushes_per_kit}</div>
                    <div className="text-xs text-muted-foreground">taken from the recipe</div>
                </div>
            </div>
            <p className="mt-2 text-xs text-muted-foreground">Counted from "Parts in this kit". The figure and pot lines there only set the count and the cost estimate: stock leaves when the figure set and paint set are picked.</p>

            {option.figures_per_kit > 0 && pickable.figure_combos.length === 0 && (
                <p className="mt-4 text-sm text-muted-foreground">No {option.figures_per_kit}-figure sets of {option.kit_name} yet. <Link href={figures.index()} className="underline">Save them on the Figures page</Link>.</p>
            )}
            {option.paint_pots_per_kit > 0 && pickable.paint_sets.length === 0 && (
                <p className="mt-4 text-sm text-muted-foreground">No {option.paint_pots_per_kit}-pot paint sets yet. <Link href={paintSets.index()} className="underline">Make one on the Paint sets page</Link>.</p>
            )}
            <PackGrid option={option} pickable={pickable} partsCanMake={partsCanMake} />
            {(packedUnrecorded.figures > 0 || packedUnrecorded.paint > 0) && (
                <p className="mt-3 text-sm text-amber-700 dark:text-amber-400">
                    Packed kits without recorded sets: {[packedUnrecorded.figures > 0 && `${num(packedUnrecorded.figures, 0)} without figures`, packedUnrecorded.paint > 0 && `${num(packedUnrecorded.paint, 0)} without paint`].filter(Boolean).join(', ')}. Pick them when you sell.
                </p>
            )}
            <p className="mt-3 text-xs text-muted-foreground">What is picked per kit is set on <Link href={categories.index()} className="underline">Categories</Link>.</p>
        </Panel>
    );
}

/**
 * Pack kits, and optionally say which figure sets and paint sets went in. Those leave stock now;
 * selling these packed kits later uses them without taking stock again.
 */
export function PackWithSetsModal({ option, pickable, onClose }: { option: KitNeeds; pickable: Pickable; onClose: () => void }) {
    const form = useForm({ qty: '', packed_on: today(), note: '', figures: [] as FigureRow[], paint: [] as PaintRow[] });
    const errors = form.errors as Record<string, string>;
    const needs = { ...option, ...pickable };
    const total = Number(form.data.qty) || 0;

    return (
        <FormModal
            open
            wide
            onOpenChange={(open) => !open && onClose()}
            title={`Pack ${option.code}`}
            description="Parts go out of stock and packed kits go in. Saying which figure and paint sets went in is optional."
            submitLabel="Pack"
            processing={form.processing}
            onSubmit={() => {
                form.transform((d) => ({ qty: d.qty, packed_on: d.packed_on, note: d.note, assign: setRowsPayload(d.figures, d.paint) }));
                form.post(stock.pack.url(option.id), { preserveScroll: true, onSuccess: onClose });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Kits packed" error={errors.qty}><NumberInput autoFocus value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} /></Field>
                <Field label="Date" error={errors.packed_on}><Input type="date" value={form.data.packed_on} onChange={(e) => form.setData('packed_on', e.target.value)} /></Field>
                <Field label="Note (optional)"><Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></Field>
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
                {option.figures_per_kit > 0 && <FigureRows needs={needs} total={total} rows={form.data.figures} error={errors.assign} onChange={(figures) => form.setData('figures', figures)} />}
                {option.paint_pots_per_kit > 0 && <PaintRows needs={needs} total={total} rows={form.data.paint} onChange={(paint) => form.setData('paint', paint)} />}
            </div>
        </FormModal>
    );
}

type PackPair = { key: string; combo: FigureCombo | null; paint: PaintSet | null; canPack: number };

/**
 * Every way to pack this kit from what is in stock: each matching figure set with each matching
 * paint set, and how many kits that pair can make. A figure set counts the sets already made plus
 * those its single figures can still make (packing uses made sets first). Pairs share stock, so
 * after packing the page reloads and every pair recounts.
 */
function PackGrid({ option, pickable, partsCanMake }: { option: KitNeeds & { kit_name: string }; pickable: Pickable; partsCanMake: number | null }) {
    const needFigures = option.figures_per_kit > 0;
    const needPaint = option.paint_pots_per_kit > 0;
    const combos: (FigureCombo | null)[] = needFigures ? pickable.figure_combos : [null];
    const paints: (PaintSet | null)[] = needPaint ? pickable.paint_sets : [null];
    const limit = (n: number) => Math.max(0, Math.floor(n));

    const pairs: PackPair[] = combos.flatMap((combo) => paints.map((paint) => ({
        key: `${combo?.id ?? 0}-${paint?.id ?? 0}`,
        combo,
        paint,
        canPack: Math.min(
            combo ? limit(combo.can_make) : Infinity,
            paint ? limit(paint.stock) : Infinity,
            partsCanMake ?? Infinity,
        ),
    }))).filter((pair) => pair.combo !== null || pair.paint !== null).sort((a, b) => b.canPack - a.canPack);

    if (pairs.length === 0) {
        return null;
    }

    return (
        <div className="mt-4">
            <h3 className="mb-1 text-sm font-semibold">Pack by {[needFigures && 'figure set', needPaint && 'paint set'].filter(Boolean).join(' and ')}</h3>
            <div className="overflow-hidden rounded-md border">
                <Table>
                    <thead>
                        <tr>
                            {needFigures && <th>Figure set</th>}
                            {needPaint && <th>Paint set</th>}
                            <th className="text-right">Can pack</th>
                            <th className="w-48" />
                        </tr>
                    </thead>
                    <tbody>
                        {pairs.map((pair) => <PackPairRow key={pair.key} optionId={option.id} pair={pair} showFigures={needFigures} showPaint={needPaint} />)}
                    </tbody>
                </Table>
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
                Can pack is the smallest of: figure sets made plus what the single figures can still make, paint sets in stock{partsCanMake !== null ? ', and the other parts of this kit' : ''}. Pairs share stock, so they all recount after you pack.
            </p>
        </div>
    );
}

function PackPairRow({ optionId, pair, showFigures, showPaint }: { optionId: number; pair: PackPair; showFigures: boolean; showPaint: boolean }) {
    const form = useForm({ qty: '' });
    const qty = Number(form.data.qty) || 0;
    const tooMany = qty > pair.canPack;
    const errors = form.errors as Record<string, string>;

    const pack = () => {
        form.transform((d) => ({
            qty: d.qty,
            packed_on: today(),
            assign: {
                figures: pair.combo ? [{ combo_id: pair.combo.id, qty: d.qty }] : [],
                paint: pair.paint ? [{ component_id: pair.paint.id, qty: d.qty }] : [],
            },
        }));
        form.post(stock.pack.url(optionId), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <tr>
            {showFigures && (
                <td>
                    {pair.combo?.name}
                    {pair.combo && <div className="text-xs text-muted-foreground tabular-nums">{num(pair.combo.stock, 0)} made + {num(pair.combo.from_figures, 0)} from figures</div>}
                </td>
            )}
            {showPaint && (
                <td>
                    {pair.paint && <PaintSetLabel set={pair.paint} />}
                    {pair.paint && <div className="text-xs text-muted-foreground tabular-nums">{num(pair.paint.stock, 0)} in stock</div>}
                </td>
            )}
            <td className={cn('text-right text-lg font-semibold tabular-nums', pair.canPack <= 0 && 'text-brand-coral')}>{num(pair.canPack, 0)}</td>
            <td>
                <form className="flex items-start justify-end gap-2" onSubmit={(e) => { e.preventDefault(); pack(); }}>
                    <div>
                        <NumberInput className="w-24" aria-label="Kits to pack" placeholder={`Up to ${num(pair.canPack, 0)}`} min={1} step={1} value={form.data.qty} disabled={pair.canPack <= 0} onChange={(e) => form.setData('qty', e.target.value)} aria-invalid={tooMany || !!errors.qty || !!errors.assign} />
                        {(tooMany || errors.qty || errors.assign) && <p className="mt-1 max-w-48 text-xs text-brand-coral">{errors.qty ?? errors.assign ?? `Only ${num(pair.canPack, 0)} can be packed.`}</p>}
                    </div>
                    <Button type="submit" size="sm" className="mt-0.5" disabled={form.processing || qty <= 0 || tooMany}>Pack</Button>
                </form>
            </td>
        </tr>
    );
}
