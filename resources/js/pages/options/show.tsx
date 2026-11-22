import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { DeleteButton, Stat, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Table, num, pct, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import { MovementsTable, StockActionModal } from '@/components/stock-actions';
import type { Movement, StockAction } from '@/components/stock-actions';
import * as optionParts from '@/routes/option-parts';
import * as options from '@/routes/options';
import * as products from '@/routes/products';

type Option = { id: number; code: string; name: string; retail_price: number; wholesale_price: number | null; courier_packing_component_id: number | null; courier_fee_estimate: number; stock: number; reorder_level: number; active: boolean };
type Part = { id: number; type: string; name: string; unit: string; qty: number; unit_cost: number; cost: number };
type SuggestRow = { target: number; price: number; profit: number; markup: number; price_courier: number; profit_courier: number; markup_courier: number };
type Pricing = { cost: number; cost_courier: number; min_margin: number; rows: SuggestRow[] };

function analyse(price: number, cost: number) {
    const profit = price - cost;

    return { profit, margin: price ? profit / price : 0, markup: cost ? profit / cost : 0 };
}

function PriceCheck({ label, price, pricing }: { label: string; price: number; pricing: Pricing }) {
    const a = analyse(price, pricing.cost);
    const b = analyse(price, pricing.cost_courier);
    const low = (m: number) => price > 0 && m < pricing.min_margin;

    return (
        <Table>
            <thead><tr><th>{label} {rs(price)}</th><th className="text-right">Profit</th><th className="text-right">Margin</th><th className="text-right">Markup</th></tr></thead>
            <tbody>
                {[['Without courier', a], ['With courier', b]].map(([name, r]) => {
                    const row = r as ReturnType<typeof analyse>;

                    return (
                        <tr key={name as string} className={cn(low(row.margin) && 'text-red-600 dark:text-red-400')}>
                            <td>{name as string}</td>
                            <td className="text-right tabular-nums">{rs(row.profit)}</td>
                            <td className="text-right tabular-nums">{pct(row.margin)}{low(row.margin) && ' (below minimum)'}</td>
                            <td className="text-right tabular-nums">{pct(row.markup)}</td>
                        </tr>
                    );
                })}
            </tbody>
        </Table>
    );
}

export default function OptionShow({ option, product, parts, pricing, courierExtra, materials, components, canMake, movements, adjustReasons }: {
    option: Option;
    canMake: { can_make: number | null; limit: string | null };
    movements: Movement[];
    adjustReasons: string[];
    product: { id: number; code: string; name: string };
    parts: Part[];
    pricing: Pricing;
    courierExtra: number;
    materials: { id: number; name: string; unit: string; unit_cost: number }[];
    components: { id: number; code: string; name: string }[];
}) {
    const form = useForm({
        code: option.code,
        name: option.name,
        retail_price: String(option.retail_price),
        wholesale_price: option.wholesale_price === null ? '' : String(option.wholesale_price),
        courier_packing_component_id: option.courier_packing_component_id ? String(option.courier_packing_component_id) : '',
        courier_fee_estimate: String(option.courier_fee_estimate),
        active: option.active,
    });
    const part = useForm({ kind: 'material', material_id: '', component_id: '', qty: '1' });
    const [modal, setModal] = useState<'part' | 'edit' | null>(null);
    const [stockAction, setStockAction] = useState<StockAction | null>(null);
    const close = (open: boolean) => !open && setModal(null);
    const done = { preserveScroll: true, onSuccess: () => setModal(null) };

    return (
        <>
            <Head title={option.code} />
            <Page>
                <PageHeader title={`${option.code} ${option.name}`} description={`Option of ${product.code} ${product.name}`}>
                    <Button variant="outline" asChild><Link href={products.show(product.id)}>Back to product</Link></Button>
                    <Button asChild><Link href={options.edit(option.id)}><Pencil /> Edit kit</Link></Button>
                    <DeleteButton url={options.destroy.url(option.id)} message="Delete this option?" label="Delete kit" withText />
                </PageHeader>

                <Panel
                    title="Ready kit stock"
                    actions={
                        <div className="flex gap-2">
                            <Button size="sm" onClick={() => setStockAction({ type: 'pack', id: option.id, name: option.code })}>Pack</Button>
                            <Button size="sm" variant="outline" disabled={option.stock <= 0} onClick={() => setStockAction({ type: 'unpack', id: option.id, name: option.code })}>Unpack</Button>
                            <Button size="sm" variant="outline" onClick={() => setStockAction({ type: 'adjust', kind: 'options', id: option.id, name: option.code })}>Correct</Button>
                        </div>
                    }
                    flush
                >
                    <div className="grid gap-4 p-4 sm:grid-cols-3">
                        <Stat label="Packed, ready to sell" value={num(option.stock, 0)} />
                        <Stat label="Can make from parts" value={canMake.can_make === null ? '-' : num(canMake.can_make, 0)} hint={canMake.limit ? `Limited by ${canMake.limit}` : undefined} />
                        <Stat label="Can sell now" value={num(Math.max(option.stock, 0) + (canMake.can_make ?? 0), 0)} hint="Packed + can make" />
                    </div>
                    <MovementsTable movements={movements} />
                </Panel>

                {(() => {
                    const a = analyse(option.retail_price, pricing.cost);

                    return (
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <Stat label="Kit cost" value={rs(pricing.cost)} hint={`With courier ${rs(pricing.cost_courier)}`} />
                            <Stat label="Selling price" value={rs(option.retail_price)} hint={option.wholesale_price !== null ? `Wholesale ${rs(option.wholesale_price)}` : 'Retail'} />
                            <Stat label="Profit" value={rs(a.profit)} tone={a.profit < 0 ? 'bad' : 'good'} hint={`With courier ${rs(option.retail_price - pricing.cost_courier)}`} />
                            <Stat label="Profit %" value={pct(a.margin)} tone={option.retail_price > 0 && a.margin < pricing.min_margin ? 'bad' : 'good'} hint={`${pct(a.markup)} on cost`} />
                        </div>
                    );
                })()}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="Parts in this kit" flush actions={<Button size="sm" onClick={() => { part.reset(); part.clearErrors(); setModal('part'); }}><Plus /> Add part</Button>}>
                        <Table>
                            <thead><tr><th>Part</th><th className="text-right">Qty</th><th className="text-right">Unit cost</th><th className="text-right">Cost</th><th /></tr></thead>
                            <tbody>
                                {parts.map((p) => (
                                    <tr key={p.id}>
                                        <td>{p.name} <span className="text-xs text-muted-foreground">{p.type}</span></td>
                                        <td className="text-right tabular-nums">{num(p.qty, 3)} {p.unit}</td>
                                        <td className="text-right tabular-nums">{rs(p.unit_cost, 4)}</td>
                                        <td className="text-right tabular-nums">{rs(p.cost)}</td>
                                        <td className="text-right"><DeleteButton url={optionParts.destroy.url(p.id)} label="Remove" /></td>
                                    </tr>
                                ))}
                                <tr className="font-semibold"><td>Kit cost</td><td /><td /><td className="text-right tabular-nums">{rs(pricing.cost)}</td><td /></tr>
                                <tr><td>Courier packing + fee estimate</td><td /><td /><td className="text-right tabular-nums">{rs(courierExtra)}</td><td /></tr>
                                <tr className="font-semibold"><td>Cost with courier</td><td /><td /><td className="text-right tabular-nums">{rs(pricing.cost_courier)}</td><td /></tr>
                            </tbody>
                        </Table>
                    </Panel>

                    <Panel title="Suggested prices" flush>
                        <Table>
                            <thead>
                                <tr><th>Target margin</th><th className="text-right">Price</th><th className="text-right">Profit</th><th className="text-right">Price w/ courier</th><th className="text-right">Profit</th></tr>
                            </thead>
                            <tbody>
                                {pricing.rows.map((r) => (
                                    <tr key={r.target}>
                                        <td>{pct(r.target)}</td>
                                        <td className="text-right tabular-nums"><button type="button" className="hover:underline" onClick={() => form.setData('retail_price', String(r.price))}>{rs(r.price, 0)}</button></td>
                                        <td className="text-right tabular-nums">{rs(r.profit)} <span className="text-xs text-muted-foreground">({pct(r.markup)} markup)</span></td>
                                        <td className="text-right tabular-nums"><button type="button" className="hover:underline" onClick={() => form.setData('retail_price', String(r.price_courier))}>{rs(r.price_courier, 0)}</button></td>
                                        <td className="text-right tabular-nums">{rs(r.profit_courier)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <p className="px-3 py-2 text-xs text-muted-foreground">Click a price to use it. Courier is never added into the selling price; it only changes the cost.</p>
                    </Panel>
                </div>

                <Panel title="My price">
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(options.update.url(option.id), { preserveScroll: true });
                        }}
                    >
                        <div className="grid gap-3 sm:grid-cols-3 sm:items-end">
                            <Field label="Retail price (Rs.)" error={form.errors.retail_price}><NumberInput value={form.data.retail_price} onChange={(e) => form.setData('retail_price', e.target.value)} /></Field>
                            <Field label="Wholesale price (optional)" error={form.errors.wholesale_price}><NumberInput value={form.data.wholesale_price} onChange={(e) => form.setData('wholesale_price', e.target.value)} /></Field>
                            <Button disabled={form.processing}>Save price</Button>
                        </div>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <PriceCheck label="Retail" price={Number(form.data.retail_price) || 0} pricing={pricing} />
                            {form.data.wholesale_price !== '' && <PriceCheck label="Wholesale" price={Number(form.data.wholesale_price) || 0} pricing={pricing} />}
                        </div>
                    </form>
                </Panel>
            </Page>

            <FormModal
                open={modal === 'part'}
                onOpenChange={close}
                title="Add part to this kit"
                description="A material from stock, or a component you make (its stage cost is used)."
                submitLabel="Add"
                processing={part.processing}
                onSubmit={() => {
                    part.transform((d) => (d.kind === 'material' ? { material_id: d.material_id, qty: d.qty } : { component_id: d.component_id, qty: d.qty }));
                    part.post(optionParts.store.url(option.id), done);
                }}
            >
                <Field label="Part type">
                    <NativeSelect value={part.data.kind} onChange={(e) => part.setData('kind', e.target.value)}>
                        <option value="material">Material</option>
                        <option value="component">Component</option>
                    </NativeSelect>
                </Field>
                {part.data.kind === 'material' ? (
                    <Field label="Material" error={part.errors.material_id}>
                        <NativeSelect value={part.data.material_id} onChange={(e) => part.setData('material_id', e.target.value)}>
                            <option value="">Choose material...</option>
                            {materials.map((m) => <option key={m.id} value={m.id}>{m.name} ({rs(m.unit_cost, 4)}/{m.unit})</option>)}
                        </NativeSelect>
                    </Field>
                ) : (
                    <Field label="Component" error={part.errors.component_id}>
                        <NativeSelect value={part.data.component_id} onChange={(e) => part.setData('component_id', e.target.value)}>
                            <option value="">Choose component...</option>
                            {components.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}
                        </NativeSelect>
                    </Field>
                )}
                <Field label="Quantity per kit" error={part.errors.qty}><NumberInput value={part.data.qty} onChange={(e) => part.setData('qty', e.target.value)} /></Field>
            </FormModal>

            <FormModal open={modal === 'edit'} onOpenChange={close} title="Edit option" processing={form.processing} onSubmit={() => form.put(options.update.url(option.id), done)}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Code" error={form.errors.code}><Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
                    <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                    <Field label="Courier packing" error={form.errors.courier_packing_component_id}>
                        <NativeSelect value={form.data.courier_packing_component_id} onChange={(e) => form.setData('courier_packing_component_id', e.target.value)}>
                            <option value="">None</option>
                            {components.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}
                        </NativeSelect>
                    </Field>
                    <Field label="Courier fee estimate" error={form.errors.courier_fee_estimate}><NumberInput value={form.data.courier_fee_estimate} onChange={(e) => form.setData('courier_fee_estimate', e.target.value)} /></Field>
                </div>
                <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} /> Active (shown on the sale form)</label>
            </FormModal>
            <StockActionModal key={stockAction ? stockAction.type : 'none'} action={stockAction} reasons={adjustReasons} onClose={() => setStockAction(null)} />
        </>
    );
}

OptionShow.layout = { breadcrumbs: [{ title: 'Kits', href: products.index() }] };
