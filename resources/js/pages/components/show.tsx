import { Head, router, useForm } from '@inertiajs/react';
import { KitGroupSelect } from '@/components/kit-group-select';
import { Copy, Pencil, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { DeleteButton, EditButton, EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, Textarea, num, rs } from '@/components/ops';
import { MovementsTable, StockActionModal } from '@/components/stock-actions';
import type { Movement, StockAction } from '@/components/stock-actions';
import { MaterialFields, emptyMaterial } from '@/pages/materials/index';
import * as components from '@/routes/components';
import * as materialRoutes from '@/routes/materials';
import * as stageLines from '@/routes/stage-lines';
import * as stages from '@/routes/stages';

type Line = { id: number; material_id: number; material: string; unit: string; qty: number; wastage_pct: number; unit_cost: number; cost_source: string; tracks_stock: boolean; cost: number };
type Stage = { id: number; name: string; sort_order: number; lines: Line[] };
type Component = { id: number; code: string; name: string; category_id: number | null; category: string | null; yield_qty: number; wastage_pct: number; keeps_stock: boolean; stock: number; prededucted_stock: number; reorder_level: number; notes: string | null; active: boolean; kit_group: string | null };
type Breakdown = { stages: { id: number; name: string; cost: number; running: number }[]; batch: number; wastage: number; unit: number };
type MaterialRef = { id: number; name: string; unit: string; unit_cost: number; cost_source: string; is_purchasable: boolean; tracks_stock: boolean; track_by_container: boolean };

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
    const [modal, setModal] = useState<'details' | 'stage' | 'line' | 'material' | 'copy' | null>(null);
    const [editLine, setEditLine] = useState<Line | null>(null);
    const [lineStage, setLineStage] = useState<Stage | null>(null);
    const [stockAction, setStockAction] = useState<StockAction | null>(null);
    const hasRecipe = stageRows.some((s) => s.lines.length > 0);
    const close = (open: boolean) => !open && setModal(null);
    const done = { preserveScroll: true, onSuccess: () => setModal(null) };

    const details = useForm({ ...component, category_id: component.category_id ? String(component.category_id) : '', notes: component.notes ?? '', kit_group: component.kit_group ?? '' });
    const stageForm = useForm({ name: '' });
    const lineForm = useForm({ material_id: '', qty: '', wastage_pct: '' });
    const selected = materials.find((m) => String(m.id) === lineForm.data.material_id);
    const copyForm = useForm({ code: '', name: '' });
    const materialForm = useForm(emptyMaterial);

    useEffect(() => {
        return router.on('flash', (event) => {
            const created = (event as CustomEvent).detail?.flash?.created_material as { id: number } | undefined;

            if (created) {
                lineForm.setData('material_id', String(created.id));
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const openLine = (stage: Stage, line: Line | null) => {
        setLineStage(stage);
        setEditLine(line);
        lineForm.clearErrors();
        lineForm.setData(line ? { material_id: String(line.material_id), qty: String(line.qty), wastage_pct: String(line.wastage_pct || '') } : { material_id: '', qty: '', wastage_pct: '' });
        setModal('line');
    };

    const saveLine = () => {
        if (editLine) {
            lineForm.put(stageLines.update.url(editLine.id), done);
        } else if (lineStage) {
            lineForm.post(stageLines.store.url(lineStage.id), done);
        }
    };

    // Opens over the line form without closing it, so unsaved line and stage input is kept.
    const saveMaterial = () =>
        materialForm.post(materialRoutes.store.url(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                materialForm.reset();
                setModal('line');
            },
        });

    return (
        <>
            <Head title={component.name} />
            <Page>
                <PageHeader title={component.name} description={`${component.code}${component.category ? ` · ${component.category}` : ''} · cost estimate`}>
                    {!component.active && <Badge variant="outline">Inactive</Badge>}
                    <Button variant="outline" onClick={() => { details.clearErrors(); setModal('details'); }}><Pencil /> Edit</Button>
                    <Button variant="outline" onClick={() => { copyForm.reset(); copyForm.clearErrors(); setModal('copy'); }}><Copy /> Copy</Button>
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
                            <Stat label="In stock" value={num(component.stock, 0)} tone={component.stock <= component.reorder_level ? 'bad' : undefined} hint={component.prededucted_stock > 0 ? `${num(component.prededucted_stock, 0)} made earlier, materials already deducted` : `Alert at ${num(component.reorder_level, 0)}`} />
                            <Stat label="Stock value" value={rs(Math.max(component.stock, 0) * breakdown.unit)} />
                            <Stat label="Materials can make" value={hasRecipe && fromMaterials.can_make !== null ? num(fromMaterials.can_make, 0) : '-'} hint={fromMaterials.limit ? `Limited by ${fromMaterials.limit}` : 'No recipe materials'} />
                        </div>
                        <MovementsTable movements={movements} />
                    </Panel>
                )}

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Stat label="Batch cost" value={rs(breakdown.batch)} />
                            <Stat label="Whole-batch wastage" value={`${num(component.wastage_pct)}%`} hint={`${rs(breakdown.wastage)} · per-line wastage is set on each line`} />
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
                                                <Button size="sm" variant="ghost" onClick={() => openLine(stage, null)}><Plus /> Line</Button>
                                                <DeleteButton url={stages.destroy.url(stage.id)} message="Delete this stage and its lines?" label="Remove" />
                                            </div>
                                        </div>
                                        <Table>
                                            <thead><tr><th>Material</th><th className="text-right">Qty</th><th className="text-right">Wastage</th><th className="text-right">Unit cost</th><th className="text-right">Cost</th><th /></tr></thead>
                                            <tbody>
                                                {stage.lines.length === 0 && <EmptyRow colSpan={6}>No lines yet.</EmptyRow>}
                                                {stage.lines.map((l) => (
                                                    <tr key={l.id}>
                                                        <td>{l.material}{!l.tracks_stock && <span className="ml-2 text-xs text-muted-foreground">cost only / not deducted</span>}</td>
                                                        <td className="text-right tabular-nums">{num(l.qty, 3)} {l.unit}</td>
                                                        <td className="text-right tabular-nums">{l.wastage_pct > 0 ? `${num(l.wastage_pct)}%` : '-'}</td>
                                                        <td className="text-right tabular-nums">{rs(l.unit_cost, 4)}</td>
                                                        <td className="text-right tabular-nums">{rs(l.cost)}</td>
                                                        <td className="space-x-2 text-right whitespace-nowrap"><EditButton onClick={() => openLine(stage, l)} label="Edit line" /><DeleteButton url={stageLines.destroy.url(l.id)} label="Remove" /></td>
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
                    <Field label="Kit builder section" error={details.errors.kit_group}><KitGroupSelect value={details.data.kit_group} onChange={(v) => details.setData('kit_group', v)} /></Field>
                    <Field label="Yield per batch" error={details.errors.yield_qty}><NumberInput value={details.data.yield_qty} onChange={(e) => details.setData('yield_qty', Number(e.target.value))} /></Field>
                    <Field label="Whole-batch wastage % (applies to every line)" error={details.errors.wastage_pct}><NumberInput value={details.data.wastage_pct} onChange={(e) => details.setData('wastage_pct', Number(e.target.value))} /></Field>
                    <Field label="Low stock alert at" error={details.errors.reorder_level}><NumberInput value={details.data.reorder_level} onChange={(e) => details.setData('reorder_level', Number(e.target.value))} /></Field>
                    <Field label="Notes" className="sm:col-span-2"><Textarea value={details.data.notes} onChange={(e) => details.setData('notes', e.target.value)} /></Field>
                </div>
                <label className="flex items-center gap-2 text-sm"><Checkbox checked={details.data.active} onCheckedChange={(v) => details.setData('active', v === true)} /> Active</label>
                <label className="flex items-center gap-2 text-sm"><Checkbox checked={details.data.keeps_stock} onCheckedChange={(v) => details.setData('keeps_stock', v === true)} /> Keeps own stock (made in advance). Making it only adds stock; materials are deducted when it is packed or sold.</label>
            </FormModal>

            <FormModal open={modal === 'stage'} onOpenChange={close} title="Add stage" submitLabel="Add" processing={stageForm.processing} onSubmit={() => stageForm.post(stages.store.url(component.id), done)}>
                <Field label="Stage name" error={stageForm.errors.name}><Input value={stageForm.data.name} placeholder="Mixing, Pouring, Drying..." onChange={(e) => stageForm.setData('name', e.target.value)} autoFocus /></Field>
            </FormModal>

            <FormModal
                open={modal === 'line'}
                onOpenChange={close}
                title={editLine ? `Edit line in ${lineStage?.name ?? 'stage'}` : `Add line to ${lineStage?.name ?? 'stage'}`}
                description="Quantity is per batch. Any purchasable or cost-only item can be used. Wastage here raises only this line."
                submitLabel={editLine ? 'Save' : 'Add'}
                processing={lineForm.processing}
                onSubmit={saveLine}
            >
                <Field label="Material or cost item" error={lineForm.errors.material_id}>
                    <div className="flex gap-2">
                        <NativeSelect value={lineForm.data.material_id} onChange={(e) => lineForm.setData('material_id', e.target.value)}>
                            <option value="">Choose...</option>
                            {materials.map((m) => <option key={m.id} value={m.id}>{m.name} ({rs(m.unit_cost, 4)}/{m.unit}{m.cost_source === 'manual' ? ', manual' : ''})</option>)}
                        </NativeSelect>
                        <Button type="button" variant="outline" className="shrink-0" onClick={() => { materialForm.clearErrors(); setModal('material'); }}><Plus /> Add material</Button>
                    </div>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={`Quantity per batch${selected ? ` (${selected.unit})` : ''}`} error={lineForm.errors.qty}><NumberInput value={lineForm.data.qty} onChange={(e) => lineForm.setData('qty', e.target.value)} /></Field>
                    <Field label="Line wastage %" error={lineForm.errors.wastage_pct}><NumberInput value={lineForm.data.wastage_pct} placeholder="0" onChange={(e) => lineForm.setData('wastage_pct', e.target.value)} /></Field>
                </div>
                {selected && Number(lineForm.data.qty) > 0 && <p className="text-sm text-muted-foreground">Line cost: <span className="font-medium text-foreground">{rs(selected.unit_cost * Number(lineForm.data.qty) * (1 + (Number(lineForm.data.wastage_pct) || 0) / 100))}</span>{selected.tracks_stock && !selected.track_by_container ? ' · deducted from stock when packed or sold' : ' · not deducted from stock'}</p>}
            </FormModal>

            <FormModal open={modal === 'material'} onOpenChange={(o) => !o && setModal('line')} title="Add material or cost item" description="Saved straight away and selected on the line you were editing." submitLabel="Create" processing={materialForm.processing} onSubmit={saveMaterial}>
                <MaterialFields data={materialForm.data} setData={materialForm.setData} errors={materialForm.errors} editing={false} />
            </FormModal>

            <FormModal open={modal === 'copy'} onOpenChange={close} title={`Copy ${component.code}`} description="Copies settings, stages and recipe lines. Stock, stock movements and history are not copied, and no materials are deducted." submitLabel="Copy" processing={copyForm.processing} onSubmit={() => copyForm.post(components.copy.url(component.id))}>
                <Field label="New code (must be unique)" error={copyForm.errors.code}><Input value={copyForm.data.code} onChange={(e) => copyForm.setData('code', e.target.value.toUpperCase())} autoFocus /></Field>
                <Field label="New name (optional)" error={copyForm.errors.name}><Input value={copyForm.data.name} placeholder={`${component.name} (copy)`} onChange={(e) => copyForm.setData('name', e.target.value)} /></Field>
            </FormModal>

            <StockActionModal key={stockAction ? stockAction.type : 'none'} action={stockAction} reasons={adjustReasons} onClose={() => setStockAction(null)} />
        </>
    );
}

ComponentShow.layout = { breadcrumbs: [{ title: 'Components', href: components.index() }] };
