import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { DeleteButton, EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, Textarea, num, rs } from '@/components/ops';
import { MovementsTable, StockActionModal } from '@/components/stock-actions';
import type { Movement, StockAction } from '@/components/stock-actions';
import * as components from '@/routes/components';
import * as stageLines from '@/routes/stage-lines';
import * as stages from '@/routes/stages';

type Line = { id: number; material: string; unit: string; qty: number; unit_cost: number; cost: number };
type Stage = { id: number; name: string; sort_order: number; lines: Line[] };
type Component = { id: number; code: string; name: string; category_id: number | null; category: string | null; yield_qty: number; wastage_pct: number; keeps_stock: boolean; stock: number; reorder_level: number; notes: string | null; active: boolean };
type Breakdown = { stages: { id: number; name: string; cost: number; running: number }[]; batch: number; wastage: number; unit: number };
type MaterialRef = { id: number; name: string; unit: string; unit_cost: number };

export default function ComponentShow({ component, stages: stageRows, breakdown, materials, categories, fromMaterials, movements, adjustReasons }: {
    component: Component;
    fromMaterials: { can_make: number | null; limit: string | null };
    movements: Movement[];
    adjustReasons: string[];
    stages: Stage[];
    breakdown: Breakdown;
    materials: MaterialRef[];
    categories: { id: number; name: string }[];
}) {
    const [modal, setModal] = useState<'details' | 'stage' | 'line' | null>(null);
    const [lineStage, setLineStage] = useState<Stage | null>(null);
    const [stockAction, setStockAction] = useState<StockAction | null>(null);
    const hasRecipe = stageRows.some((s) => s.lines.length > 0);
    const close = (open: boolean) => !open && setModal(null);
    const done = { preserveScroll: true, onSuccess: () => setModal(null) };

    const details = useForm({ ...component, category_id: component.category_id ? String(component.category_id) : '', notes: component.notes ?? '' });
    const stageForm = useForm({ name: '' });
    const lineForm = useForm({ material_id: '', qty: '' });
    const selected = materials.find((m) => String(m.id) === lineForm.data.material_id);

    return (
        <>
            <Head title={component.name} />
            <Page>
                <PageHeader title={component.name} description={`${component.code}${component.category ? ` · ${component.category}` : ''} · cost estimate`}>
                    {!component.active && <Badge variant="outline">Inactive</Badge>}
                    <Button variant="outline" onClick={() => { details.clearErrors(); setModal('details'); }}><Pencil /> Edit</Button>
                    <DeleteButton url={components.destroy.url(component.id)} message="Delete this component? Kits using it will stop you." withText />
                </PageHeader>

                {component.notes && <p className="text-sm text-muted-foreground">{component.notes}</p>}

                {component.keeps_stock && (
                    <Panel
                        title="Stock"
                        actions={
                            <div className="flex gap-2">
                                <Button size="sm" onClick={() => setStockAction({ type: 'make', id: component.id, name: component.name, hasRecipe })}>+ Made</Button>
                                <Button size="sm" variant="outline" onClick={() => setStockAction({ type: 'adjust', kind: 'components', id: component.id, name: component.name })}>Correct</Button>
                            </div>
                        }
                        flush
                    >
                        <div className="grid gap-4 p-4 sm:grid-cols-3">
                            <Stat label="In stock" value={num(component.stock, 0)} tone={component.stock <= component.reorder_level ? 'bad' : undefined} hint={`Alert at ${num(component.reorder_level, 0)}`} />
                            <Stat label="Stock value" value={rs(Math.max(component.stock, 0) * breakdown.unit)} />
                            <Stat label="Materials can make" value={hasRecipe && fromMaterials.can_make !== null ? num(fromMaterials.can_make, 0) : '-'} hint={fromMaterials.limit ? `Limited by ${fromMaterials.limit}` : 'No recipe materials'} />
                        </div>
                        <MovementsTable movements={movements} />
                    </Panel>
                )}

                        <div className="grid gap-4 sm:grid-cols-4">
                            <Stat label="Batch cost" value={rs(breakdown.batch)} />
                            <Stat label="Planned wastage" value={`${num(component.wastage_pct)}%`} hint={rs(breakdown.wastage)} />
                            <Stat label="Yield per batch" value={num(component.yield_qty)} />
                            <Stat label="Unit cost" value={rs(breakdown.unit, 4)} tone="good" />
                        </div>

                        <Panel title="Making stages (per batch)" actions={<Button size="sm" onClick={() => { stageForm.reset(); setModal('stage'); }}><Plus /> Add stage</Button>}>
                            <div className="space-y-4">
                                {stageRows.length === 0 && <p className="text-sm text-muted-foreground">Add your first stage, for example Mixing.</p>}
                                {stageRows.map((stage, index) => (
                                    <div key={stage.id} className="overflow-hidden rounded-md border">
                                        <div className="flex items-center justify-between bg-muted/60 px-3 py-2">
                                            <h3 className="text-sm font-semibold"><span className="mr-2 text-brand-amber">{index + 1}.</span>{stage.name}</h3>
                                            <div className="flex items-center gap-3">
                                                <Button size="sm" variant="ghost" onClick={() => { setLineStage(stage); lineForm.reset(); lineForm.clearErrors(); setModal('line'); }}><Plus /> Line</Button>
                                                <DeleteButton url={stages.destroy.url(stage.id)} message="Delete this stage and its lines?" label="Remove" />
                                            </div>
                                        </div>
                                        <Table>
                                            <thead><tr><th>Material</th><th className="text-right">Qty</th><th className="text-right">Unit cost</th><th className="text-right">Cost</th><th /></tr></thead>
                                            <tbody>
                                                {stage.lines.length === 0 && <EmptyRow colSpan={5}>No lines yet.</EmptyRow>}
                                                {stage.lines.map((l) => (
                                                    <tr key={l.id}>
                                                        <td>{l.material}</td>
                                                        <td className="text-right tabular-nums">{num(l.qty, 3)} {l.unit}</td>
                                                        <td className="text-right tabular-nums">{rs(l.unit_cost, 4)}</td>
                                                        <td className="text-right tabular-nums">{rs(l.cost)}</td>
                                                        <td className="text-right"><DeleteButton url={stageLines.destroy.url(l.id)} label="Remove" /></td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </Table>
                                    </div>
                                ))}
                            </div>
                        </Panel>

                        <Panel title="Cost build-up" flush>
                            <Table>
                                <thead><tr><th>Stage</th><th className="text-right">Stage cost</th><th className="text-right">Running total</th></tr></thead>
                                <tbody>
                                    {breakdown.stages.map((s) => (
                                        <tr key={s.id}><td>{s.name}</td><td className="text-right tabular-nums">{rs(s.cost)}</td><td className="text-right tabular-nums">{rs(s.running)}</td></tr>
                                    ))}
                                    <tr><td>Wastage {num(component.wastage_pct)}%</td><td className="text-right tabular-nums">{rs(breakdown.wastage)}</td><td className="text-right tabular-nums">{rs(breakdown.batch + breakdown.wastage)}</td></tr>
                                    <tr className="bg-brand-teal-tint font-semibold text-brand-teal"><td>Unit cost (÷ {num(component.yield_qty)} per batch)</td><td /><td className="text-right tabular-nums">{rs(breakdown.unit, 4)}</td></tr>
                                </tbody>
                            </Table>
                        </Panel>
            </Page>

            <FormModal open={modal === 'details'} onOpenChange={close} title="Edit component" wide processing={details.processing} onSubmit={() => details.put(components.update.url(component.id), done)}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Category" error={details.errors.category_id}>
                        <NativeSelect value={details.data.category_id} onChange={(e) => details.setData('category_id', e.target.value)}>
                            <option value="">None</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </NativeSelect>
                    </Field>
                    <Field label="Code" error={details.errors.code}><Input value={details.data.code} onChange={(e) => details.setData('code', e.target.value.toUpperCase())} /></Field>
                    <Field label="Name" error={details.errors.name}><Input value={details.data.name} onChange={(e) => details.setData('name', e.target.value)} /></Field>
                    <Field label="Yield per batch" error={details.errors.yield_qty}><NumberInput value={details.data.yield_qty} onChange={(e) => details.setData('yield_qty', Number(e.target.value))} /></Field>
                    <Field label="Planned wastage %" error={details.errors.wastage_pct}><NumberInput value={details.data.wastage_pct} onChange={(e) => details.setData('wastage_pct', Number(e.target.value))} /></Field>
                    <Field label="Low stock alert at" error={details.errors.reorder_level}><NumberInput value={details.data.reorder_level} onChange={(e) => details.setData('reorder_level', Number(e.target.value))} /></Field>
                    <Field label="Notes" className="sm:col-span-2"><Textarea value={details.data.notes} onChange={(e) => details.setData('notes', e.target.value)} /></Field>
                </div>
                <label className="flex items-center gap-2 text-sm"><Checkbox checked={details.data.active} onCheckedChange={(v) => details.setData('active', v === true)} /> Active</label>
                <label className="flex items-center gap-2 text-sm"><Checkbox checked={details.data.keeps_stock} onCheckedChange={(v) => details.setData('keeps_stock', v === true)} /> Keeps own stock (made in advance, e.g. figures and paint pots). Kits then use this stock instead of raw materials.</label>
            </FormModal>

            <FormModal open={modal === 'stage'} onOpenChange={close} title="Add stage" submitLabel="Add" processing={stageForm.processing} onSubmit={() => stageForm.post(stages.store.url(component.id), done)}>
                <Field label="Stage name" error={stageForm.errors.name}><Input value={stageForm.data.name} placeholder="Mixing, Pouring, Drying..." onChange={(e) => stageForm.setData('name', e.target.value)} autoFocus /></Field>
            </FormModal>

            <FormModal
                open={modal === 'line'}
                onOpenChange={close}
                title={`Add line to ${lineStage?.name ?? 'stage'}`}
                description="Quantity is per batch. Overheads such as labour or electricity can be added too."
                submitLabel="Add"
                processing={lineForm.processing}
                onSubmit={() => lineStage && lineForm.post(stageLines.store.url(lineStage.id), done)}
            >
                <Field label="Material or overhead" error={lineForm.errors.material_id}>
                    <NativeSelect value={lineForm.data.material_id} onChange={(e) => lineForm.setData('material_id', e.target.value)}>
                        <option value="">Choose...</option>
                        {materials.map((m) => <option key={m.id} value={m.id}>{m.name} ({rs(m.unit_cost, 4)}/{m.unit})</option>)}
                    </NativeSelect>
                </Field>
                <Field label={`Quantity per batch${selected ? ` (${selected.unit})` : ''}`} error={lineForm.errors.qty}><NumberInput value={lineForm.data.qty} onChange={(e) => lineForm.setData('qty', e.target.value)} /></Field>
                {selected && Number(lineForm.data.qty) > 0 && <p className="text-sm text-muted-foreground">Line cost: <span className="font-medium text-foreground">{rs(selected.unit_cost * Number(lineForm.data.qty))}</span></p>}
            </FormModal>

            <StockActionModal key={stockAction ? stockAction.type : 'none'} action={stockAction} reasons={adjustReasons} onClose={() => setStockAction(null)} />
        </>
    );
}

ComponentShow.layout = { breadcrumbs: [{ title: 'Components', href: components.index() }] };
