import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, num, rs, today } from '@/components/ops';
import type { Design } from '@/components/paint-dots';
import { DesignPicker } from '@/components/paint-dots';
import type { FigureCombo } from '@/components/paint-dots';
import { StockActionModal } from '@/components/stock-actions';
import type { StockAction } from '@/components/stock-actions';
import { cn } from '@/lib/utils';
import * as figureCombos from '@/routes/figure-combos';
import * as figures from '@/routes/figures';
import * as options from '@/routes/options';
import * as sales from '@/routes/sales';

type DesignRow = { id: number; code: string; name: string; stock: number; reorder_level: number; product_id: number | null; family?: string | null; sold_month: number; sold: number; damaged_month: number; damaged: number };
type ComboRow = FigureCombo & { sold_month: number; sold: number; damaged_month: number; damaged: number };
type Kit = { id: number; code: string; name: string; sizes: number[]; designs: DesignRow[]; combos: ComboRow[] };
type CategoryGroup = { name: string; kits: Kit[] };
type KitChoice = { id: number; label: string; category: string | null };
type Waiting = { sale_item_id: number; sale_id: number; invoice_no: string; sold_on: string; customer: string | null; item: string; qty: number; remaining: number };

export default function Figures({ categories, unlinked, kitChoices, waiting, packedUnrecorded, adjustReasons }: {
    categories: CategoryGroup[];
    unlinked: DesignRow[];
    kitChoices: KitChoice[];
    waiting: Waiting[];
    packedUnrecorded: { id: number; code: string; name: string; qty: number }[];
    adjustReasons: string[];
}) {
    const [modal, setModal] = useState<{ type: 'design' } | { type: 'combo'; kit: Kit } | { type: 'move'; design: DesignRow } | null>(null);
    const [stockAction, setStockAction] = useState<StockAction | null>(null);
    const design = useForm({ product_id: '', name: '' });
    const combo = useForm({ designs: {} as Record<string, number>, name: '' });
    const moveForm = useForm({ product_id: '' });
    const close = (open: boolean) => !open && setModal(null);
    const done = { preserveScroll: true, onSuccess: () => setModal(null) };
    const kits = categories.flatMap((c) => c.kits);
    const allDesigns = [...kits.flatMap((k) => k.designs), ...unlinked];
    const allCombos = kits.flatMap((k) => k.combos);
    const comboErrors = combo.errors as Record<string, string>;

    const addDesign = (kitId: number | null) => {
        design.reset();
        design.clearErrors();
        design.setData({ product_id: kitId ? String(kitId) : '', name: '' });
        setModal({ type: 'design' });
    };

    const designTable = (designs: DesignRow[], empty: string) => (
        <Table>
            <thead><tr><th>Figure</th><th className="text-right">In stock</th><th className="text-right">Sold (month / all)</th><th className="text-right">Damaged (month / all)</th><th /></tr></thead>
            <tbody>
                {designs.length === 0 && <EmptyRow colSpan={5}>{empty}</EmptyRow>}
                {designs.map((d) => (
                    <tr key={d.id}>
                        <td>{d.name} <span className="font-mono text-xs text-muted-foreground">{d.code}</span>{d.family && <div className="text-xs text-muted-foreground">Was family {d.family}</div>}</td>
                        <td className={cn('text-right font-medium tabular-nums', d.stock <= d.reorder_level && 'text-brand-coral')}>{num(d.stock, 0)}</td>
                        <td className="text-right tabular-nums">{num(d.sold_month, 0)} / {num(d.sold, 0)}</td>
                        <td className="text-right tabular-nums">{num(d.damaged_month, 0)} / {num(d.damaged, 0)}</td>
                        <td className="text-right whitespace-nowrap">
                            <Button size="sm" onClick={() => setStockAction({ type: 'make', id: d.id, name: d.name, hasRecipe: false })}>Made</Button>{' '}
                            <Button size="sm" variant="outline" onClick={() => setStockAction({ type: 'adjust', kind: 'components', id: d.id, name: d.name })}>Damaged / correct</Button>{' '}
                            <Button size="icon" variant="ghost" aria-label="Move to another kit" onClick={() => { moveForm.setData('product_id', d.product_id ? String(d.product_id) : ''); moveForm.clearErrors(); setModal({ type: 'move', design: d }); }}><Pencil className="size-4" /></Button>
                        </td>
                    </tr>
                ))}
            </tbody>
        </Table>
    );

    const kitSelect = (value: string, onChange: (value: string) => void) => (
        <NativeSelect value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">Choose a kit...</option>
            {kitChoices.map((k) => <option key={k.id} value={k.id}>{k.category ? `${k.category} · ` : ''}{k.label}</option>)}
        </NativeSelect>
    );

    return (
        <>
            <Head title="Figures" />
            <Page>
                <PageHeader title="Figures" description="Each kit's figures counted on their own, grouped by category and kit, with saved figure sets for packing and selling.">
                    <Button onClick={() => addDesign(null)}><Plus /> Add figure</Button>
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat label="Figures in stock" value={num(allDesigns.reduce((s, d) => s + Math.max(d.stock, 0), 0), 0)} hint={`${allDesigns.length} figures`} />
                    <Stat label="Figure sets in stock" value={num(allCombos.reduce((n, c) => n + Math.max(c.stock, 0), 0), 0)} hint={`${allCombos.length} figure sets`} />
                    <Stat label="Kits waiting for figures" value={num(waiting.reduce((s, w) => s + w.remaining, 0), 0)} tone={waiting.length ? 'bad' : undefined} hint={waiting.length ? 'Choose on the sale' : 'Nothing waiting'} />
                </div>

                {waiting.length > 0 && (
                    <Panel title="Sold, figures not chosen yet" flush>
                        <Table>
                            <thead><tr><th>Sale</th><th>Kit</th><th className="text-right">Not chosen</th><th /></tr></thead>
                            <tbody>
                                {waiting.map((w) => (
                                    <tr key={w.sale_item_id}>
                                        <td className="font-mono">{w.invoice_no}<div className="font-sans text-xs text-muted-foreground">{w.sold_on}{w.customer ? ` · ${w.customer}` : ''}</div></td>
                                        <td>{w.item}</td>
                                        <td className="text-right tabular-nums">{num(w.remaining, 0)} of {num(w.qty, 0)}</td>
                                        <td className="text-right"><Button size="sm" variant="outline" asChild><Link href={sales.show(w.sale_id)}>Choose figures</Link></Button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Panel>
                )}

                {packedUnrecorded.length > 0 && (
                    <Panel title="Packed kits without recorded figures" flush>
                        <Table>
                            <tbody>
                                {packedUnrecorded.map((p) => (
                                    <tr key={p.id}><td><Link href={options.show(p.id)} className="hover:underline">{p.name}</Link> <span className="font-mono text-xs text-muted-foreground">{p.code}</span></td><td className="text-right tabular-nums">{num(p.qty, 0)} kits</td></tr>
                                ))}
                            </tbody>
                        </Table>
                        <p className="px-3 py-2 text-xs text-muted-foreground">Their figures are picked when they are sold.</p>
                    </Panel>
                )}

                {categories.length === 0 && (
                    <Panel><p className="text-sm text-muted-foreground">No kits take figures yet. On Categories, set "Picked per kit" to "Figures and a paint set" for your painting kits.</p></Panel>
                )}

                {categories.map((category) => (
                    <section key={category.name} className="space-y-4">
                        <h2 className="text-lg font-semibold">{category.name}</h2>
                        {category.kits.map((kit) => (
                            <Panel key={kit.id} title={kit.name} flush actions={<Button size="sm" variant="outline" onClick={() => addDesign(kit.id)}><Plus /> Add figure</Button>}>
                                {designTable(kit.designs, 'No figures yet. Add each figure of this kit, for example Car, Bike, Bus.')}

                                <div className="flex items-center justify-between border-t px-3 py-2">
                                    <h3 className="text-sm font-semibold">Figure sets</h3>
                                    <Button size="sm" variant="outline" disabled={kit.designs.length === 0} onClick={() => { combo.reset(); combo.clearErrors(); setModal({ type: 'combo', kit }); }}><Plus /> New figure set</Button>
                                </div>
                                <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {kit.combos.length === 0 && <p className="text-sm text-muted-foreground">No figure sets yet, for example Car + Bike + Bus.</p>}
                                    {kit.combos.map((c) => (
                                        <FigureSetCard key={c.id} combo={c} onDamaged={() => c.set_id && setStockAction({ type: 'adjust', kind: 'components', id: c.set_id, name: c.name })} />
                                    ))}
                                </div>
                            </Panel>
                        ))}
                    </section>
                ))}

                {unlinked.length > 0 && (
                    <Panel title="Figures not linked to a kit" flush>
                        {designTable(unlinked, '')}
                        <p className="px-3 py-2 text-xs text-muted-foreground">Use the pencil to move each figure into its kit. Only linked figures can go into figure sets.</p>
                    </Panel>
                )}
            </Page>

            <FormModal open={modal?.type === 'design'} onOpenChange={close} title="Add figure" description="A figure you make and count on its own, inside its kit." processing={design.processing} onSubmit={() => design.post(figures.store.url(), done)}>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Kit" error={design.errors.product_id}>{kitSelect(design.data.product_id, (v) => design.setData('product_id', v))}</Field>
                    <Field label="Figure" error={design.errors.name}><Input placeholder="Car" value={design.data.name} onChange={(e) => design.setData('name', e.target.value)} /></Field>
                </div>
            </FormModal>

            <FormModal open={modal?.type === 'combo'} onOpenChange={close} wide title={modal?.type === 'combo' ? `New figure set: ${modal.kit.name}` : 'New figure set'} description="Click a figure to add one, click again to add it twice, and use − to take one out. Then make sets on its card." processing={combo.processing} onSubmit={() => combo.post(figureCombos.store.url(), done)}>
                {modal?.type === 'combo' && <DesignPicker designs={modal.kit.designs} value={combo.data.designs} onChange={(v) => combo.setData('designs', v)} />}
                {modal?.type === 'combo' && <SetPreview kit={modal.kit} picked={combo.data.designs} />}
                {comboErrors.designs && <p className="text-sm text-red-600">{comboErrors.designs}</p>}
                <Field label="Name (optional)" error={combo.errors.name}><Input placeholder="Made from the figures if left empty" value={combo.data.name} onChange={(e) => combo.setData('name', e.target.value)} /></Field>
            </FormModal>

            <FormModal open={modal?.type === 'move'} onOpenChange={close} title={modal?.type === 'move' ? `Kit of ${modal.design.name}` : 'Kit'} processing={moveForm.processing} onSubmit={() => modal?.type === 'move' && moveForm.patch(figures.kit.url(modal.design.id), done)}>
                <Field label="Kit" error={moveForm.errors.product_id}>{kitSelect(moveForm.data.product_id, (v) => moveForm.setData('product_id', v))}</Field>
            </FormModal>

            <StockActionModal key={stockAction ? `${stockAction.type}-${stockAction.id}` : 'none'} action={stockAction} reasons={adjustReasons} onClose={() => setStockAction(null)} />
        </>
    );
}

Figures.layout = { breadcrumbs: [{ title: 'Figures', href: figures.index() }] };

/**
 * One figure set: sets already made, how many more the single figures allow, and a quantity to
 * make now. Making takes the single figures, so every other set using them recounts on reload.
 */
function FigureSetCard({ combo, onDamaged }: { combo: ComboRow; onDamaged: () => void }) {
    const form = useForm({ qty: '', made_on: today(), note: '' });
    const qty = Number(form.data.qty) || 0;
    const tooMany = qty > combo.from_figures;

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-3">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="font-medium">{combo.name}</div>
                    <div className="text-xs text-muted-foreground">{combo.size} figures · {rs(combo.unit_cost)}</div>
                </div>
                <DeleteButton url={figureCombos.destroy.url(combo.id)} message={`Remove ${combo.name}?`} label="Remove" />
            </div>

            <div className="grid grid-cols-2 gap-2 text-center">
                <div className="rounded-md bg-muted/50 px-2 py-1.5">
                    <div className={cn('text-xl font-semibold tabular-nums', combo.stock <= combo.reorder_level && 'text-brand-coral')}>{num(combo.stock, 0)}</div>
                    <div className="text-xs text-muted-foreground">Sets made</div>
                </div>
                <div className="rounded-md bg-muted/50 px-2 py-1.5">
                    <div className={cn('text-xl font-semibold tabular-nums', combo.from_figures <= 0 && 'text-brand-coral')}>{num(combo.from_figures, 0)}</div>
                    <div className="text-xs text-muted-foreground">Can make more</div>
                </div>
            </div>

            <div className="text-xs text-muted-foreground">
                {combo.items.map((item) => `${item.qty > 1 ? `${item.qty} × ` : ''}${item.name} (${num(item.stock, 0)})`).join(' · ')}
            </div>

            <form
                className="flex items-start gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(figureCombos.make.url(combo.id), { preserveScroll: true, onSuccess: () => form.reset('qty') });
                }}
            >
                <div className="flex-1">
                    <NumberInput aria-label={`Sets of ${combo.name} to make`} placeholder={`Up to ${num(combo.from_figures, 0)}`} min={1} step={1} value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} aria-invalid={tooMany || !!form.errors.qty} />
                    {(form.errors.qty || tooMany) && <p className="mt-1 text-xs text-brand-coral">{form.errors.qty ?? `Only ${num(combo.from_figures, 0)} can be made from the figures in stock.`}</p>}
                </div>
                <Button type="submit" size="sm" className="mt-0.5" disabled={form.processing || qty <= 0 || tooMany}>Make</Button>
                {combo.set_id && <Button type="button" size="sm" variant="outline" className="mt-0.5" onClick={onDamaged}>Damaged</Button>}
            </form>

            <div className="flex justify-between border-t pt-2 text-xs text-muted-foreground tabular-nums">
                <span>Sold {num(combo.sold_month, 0)} this month / {num(combo.sold, 0)} all</span>
                <span>Damaged {num(combo.damaged_month, 0)} / {num(combo.damaged, 0)}</span>
            </div>
        </div>
    );
}

/**
 * While picking a new set: how many figures are in it, the set sizes this kit's products use,
 * and how many of this set the single figures in stock could make.
 */
function SetPreview({ kit, picked }: { kit: { sizes: number[]; designs: Design[] }; picked: Record<string, number> }) {
    const chosen = Object.entries(picked).filter(([, n]) => n > 0);
    const total = chosen.reduce((sum, [, n]) => sum + n, 0);
    const canMake = chosen.length === 0 ? 0 : Math.min(...chosen.map(([id, n]) => Math.floor(Math.max(kit.designs.find((d) => String(d.id) === id)?.stock ?? 0, 0) / n)));

    return (
        <div className="space-y-2 rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{total} {total === 1 ? 'figure' : 'figures'} picked</span>
                {total > 0 && <span className="text-muted-foreground">· the figures in stock can make {num(canMake, 0)} of this set</span>}
            </div>
            {kit.sizes.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="text-muted-foreground">This kit's products use sets of</span>
                    {kit.sizes.map((size) => (
                        <span key={size} className={cn('rounded-full border px-2 py-0.5 tabular-nums', size === total ? 'border-brand-teal bg-brand-teal text-white' : 'text-muted-foreground')}>{size}</span>
                    ))}
                    {total > 0 && !kit.sizes.includes(total) && <span className="text-brand-coral">No product uses a {total}-figure set.</span>}
                </div>
            )}
        </div>
    );
}

