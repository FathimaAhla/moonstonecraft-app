import { Head, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { PackSizeSelect } from '@/components/pack-size-select';
import type { PackSizeRef } from '@/components/pack-size-select';
import { Field, NativeSelect, NumberInput, Page, PageHeader, Panel, Table, Textarea, rs } from '@/components/ops';
import * as purchases from '@/routes/purchases';

type MaterialRef = { id: number; name: string; unit: string; unit_cost: number; purchase_unit: string | null; purchase_conversion: number; pack_sizes: PackSizeRef[] };
type Line = { material_id: string; material_pack_size_id: string; qty: string; purchase_unit: string; conversion_factor: string; line_cost: string };
type Item = { material_id: number; material_pack_size_id: number | null; qty: number; purchase_unit: string | null; conversion_factor: number; line_cost: number; material: { name: string; unit: string } };
type Purchase = {
    id: number; purchased_on: string; supplier_id: number | null; transport_cost: number; refreshment_cost: number; other_cost: number;
    refreshment_as_stock_cost: boolean; notes: string | null; items: Item[];
};

const newLine = (): Line => ({ material_id: '', material_pack_size_id: '', qty: '', purchase_unit: '', conversion_factor: '', line_cost: '' });

export default function PurchaseEdit({ purchase: p, materials, suppliers }: { purchase: Purchase; materials: MaterialRef[]; suppliers: { id: number; name: string }[] }) {
    const form = useForm({
        purchased_on: p.purchased_on.slice(0, 10),
        supplier_id: p.supplier_id ? String(p.supplier_id) : '',
        supplier_name: '',
        transport_cost: String(p.transport_cost),
        refreshment_cost: String(p.refreshment_cost),
        other_cost: String(p.other_cost),
        refreshment_as_stock_cost: p.refreshment_as_stock_cost,
        notes: p.notes ?? '',
        receipt: null as File | null,
        lines: p.items.map((i): Line => ({
            material_id: String(i.material_id),
            material_pack_size_id: i.material_pack_size_id ? String(i.material_pack_size_id) : '',
            qty: String(i.qty),
            purchase_unit: i.purchase_unit ?? '',
            conversion_factor: String(i.conversion_factor),
            line_cost: String(i.line_cost),
        })),
    });
    const d = form.data;
    const itemsTotal = d.lines.reduce((sum, l) => sum + (Number(l.line_cost) || 0), 0);
    const transport = Number(d.transport_cost) || 0;
    const refreshment = Number(d.refreshment_cost) || 0;
    const other = Number(d.other_cost) || 0;
    const extra = transport + other + (d.refreshment_as_stock_cost ? refreshment : 0);
    const errors = form.errors as Record<string, string>;

    const setLine = (i: number, key: keyof Line, value: string) => form.setData('lines', d.lines.map((l, j) => (j === i ? { ...l, [key]: value } : l)));

    const pickPackSize = (i: number, packSize: PackSizeRef | null) => {
        form.setData('lines', d.lines.map((l, j) => (j === i ? { ...l, material_pack_size_id: packSize ? String(packSize.id) : '', purchase_unit: packSize?.name ?? '', conversion_factor: packSize ? String(packSize.size) : '' } : l)));
    };

    const pickMaterial = (i: number, id: string) => {
        const m = materials.find((x) => String(x.id) === id);

        form.setData('lines', d.lines.map((l, j) => (j === i ? { ...l, material_id: id, material_pack_size_id: '', purchase_unit: m?.purchase_unit ?? '', conversion_factor: m?.purchase_unit ? String(m.purchase_conversion) : '' } : l)));
    };

    return (
        <>
            <Head title={`Edit purchase`} />
            <Page>
                <PageHeader title="Edit purchase" description="Changing lines reverses the old stock effect and re-applies the new one. Weighted-average costs of tracked materials are not rolled back to their pre-purchase value." />
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({ ...data, _method: 'put' }));
                        form.post(purchases.update.url(p.id));
                    }}
                >
                    <Panel>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="Date" error={errors.purchased_on}><Input type="date" value={d.purchased_on} onChange={(e) => form.setData('purchased_on', e.target.value)} /></Field>
                            <Field label="Supplier" error={errors.supplier_id}>
                                <NativeSelect value={d.supplier_id} onChange={(e) => form.setData('supplier_id', e.target.value)}>
                                    <option value="">New or none...</option>
                                    {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </NativeSelect>
                            </Field>
                            {!d.supplier_id && <Field label="New supplier name (optional)"><Input value={d.supplier_name} onChange={(e) => form.setData('supplier_name', e.target.value)} /></Field>}
                        </div>
                    </Panel>

                    <Panel title="Lines">
                        {errors.lines && <p className="mb-2 text-sm text-red-600">{errors.lines}</p>}
                        <Table>
                            <thead><tr><th>Material</th><th className="w-28 text-right">Qty bought</th><th className="w-48">Bought in</th><th className="w-28 text-right">Units each</th><th className="w-36 text-right">Line cost (Rs.)</th><th className="text-right">Extra</th><th className="text-right">Landed unit</th><th /></tr></thead>
                            <tbody>
                                {d.lines.map((l, i) => {
                                    const cost = Number(l.line_cost) || 0;
                                    const alloc = itemsTotal > 0 ? (extra * cost) / itemsTotal : 0;
                                    const factor = Number(l.conversion_factor) || 1;
                                    const qty = (Number(l.qty) || 0) * factor;
                                    const material = materials.find((m) => String(m.id) === l.material_id);
                                    const unit = material?.unit ?? '';
                                    const packSizes = material?.pack_sizes ?? [];

                                    return (
                                        <tr key={i}>
                                            <td>
                                                <NativeSelect value={l.material_id} onChange={(e) => pickMaterial(i, e.target.value)} aria-invalid={!!errors[`lines.${i}.material_id`]}>
                                                    <option value="">Choose material...</option>
                                                    {materials.map((m) => <option key={m.id} value={m.id}>{m.name} ({m.unit})</option>)}
                                                </NativeSelect>
                                            </td>
                                            <td><NumberInput value={l.qty} onChange={(e) => setLine(i, 'qty', e.target.value)} aria-invalid={!!errors[`lines.${i}.qty`]} /></td>
                                            <td className="space-y-1">
                                                {packSizes.length > 0 && <PackSizeSelect packSizes={packSizes} unit={unit} value={l.material_pack_size_id} onPick={(p) => pickPackSize(i, p)} />}
                                                {!l.material_pack_size_id && <Input value={l.purchase_unit} placeholder={unit} onChange={(e) => setLine(i, 'purchase_unit', e.target.value)} />}
                                            </td>
                                            <td><NumberInput value={l.conversion_factor} readOnly={!!l.material_pack_size_id} placeholder="1" onChange={(e) => setLine(i, 'conversion_factor', e.target.value)} aria-invalid={!!errors[`lines.${i}.conversion_factor`]} /></td>
                                            <td><NumberInput value={l.line_cost} onChange={(e) => setLine(i, 'line_cost', e.target.value)} aria-invalid={!!errors[`lines.${i}.line_cost`]} /></td>
                                            <td className="text-right tabular-nums">{rs(alloc)}</td>
                                            <td className="text-right tabular-nums">{qty > 0 ? `${rs((cost + alloc) / qty, 4)}/${unit} · ${qty.toLocaleString('en-LK', { maximumFractionDigits: 3 })} ${unit}` : '-'}</td>
                                            <td className="text-right">
                                                {d.lines.length > 1 && (
                                                    <Button type="button" variant="ghost" size="icon" onClick={() => form.setData('lines', d.lines.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                        <Button type="button" variant="outline" size="sm" className="mt-3" onClick={() => form.setData('lines', [...d.lines, newLine()])}>Add line</Button>
                    </Panel>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title="Trip costs">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Field label="Transport" error={errors.transport_cost}><NumberInput value={d.transport_cost} onChange={(e) => form.setData('transport_cost', e.target.value)} /></Field>
                                <Field label="Refreshment" error={errors.refreshment_cost}><NumberInput value={d.refreshment_cost} onChange={(e) => form.setData('refreshment_cost', e.target.value)} /></Field>
                                <Field label="Other" error={errors.other_cost}><NumberInput value={d.other_cost} onChange={(e) => form.setData('other_cost', e.target.value)} /></Field>
                            </div>
                            <label className="mt-3 flex items-center gap-2 text-sm">
                                <Checkbox checked={d.refreshment_as_stock_cost} onCheckedChange={(v) => form.setData('refreshment_as_stock_cost', v === true)} />
                                Fold refreshment into material cost (otherwise it is saved as an expense)
                            </label>
                            <Field label="Notes" className="mt-3"><Textarea value={d.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                            <Field label="Replace receipt / bill (photo or PDF)" className="mt-3" error={errors.receipt}>
                                <Input type="file" accept="image/*,application/pdf" onChange={(e) => form.setData('receipt', e.target.files?.[0] ?? null)} />
                            </Field>
                        </Panel>
                        <Panel title="Totals">
                            <Table>
                                <tbody>
                                    <tr><td>Items total</td><td className="text-right tabular-nums">{rs(itemsTotal)}</td></tr>
                                    <tr><td>Transport</td><td className="text-right tabular-nums">{rs(transport)}</td></tr>
                                    <tr><td>Refreshment {d.refreshment_as_stock_cost ? '(in stock cost)' : '(expense)'}</td><td className="text-right tabular-nums">{rs(refreshment)}</td></tr>
                                    <tr><td>Other</td><td className="text-right tabular-nums">{rs(other)}</td></tr>
                                    <tr className="text-lg font-semibold"><td>Grand total</td><td className="text-right tabular-nums">{rs(itemsTotal + transport + refreshment + other)}</td></tr>
                                </tbody>
                            </Table>
                            <Button className="mt-4 w-full" disabled={form.processing}>Save changes</Button>
                        </Panel>
                    </div>
                </form>
            </Page>
        </>
    );
}

PurchaseEdit.layout = { breadcrumbs: [{ title: 'Purchases', href: purchases.index() }] };
