import { Head, Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { KitPicker } from '@/components/kit-picker';
import type { KitItem, PartRow } from '@/components/kit-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { EmptyRow, Field, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, num, pct, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import * as options from '@/routes/options';
import * as products from '@/routes/products';

type Component = { id: number; code: string; name: string; group: string; unit_cost: number; stages: { name: string; cost: number }[] };
type MaterialRef = { id: number; name: string; unit: string; group: string; unit_cost: number; measured_qty: number | null };
type Option = {
    id: number;
    code: string;
    name: string;
    retail_price: number;
    wholesale_price: number | null;
    courier_packing_component_id: number | null;
    courier_fee_estimate: number;
    active: boolean;
    parts: { kind: 'component' | 'material'; id: number; qty: number }[];
};

const MARGINS = [0.2, 0.3, 0.4, 0.5, 0.6];

/** The same item listed twice becomes one line with the quantities added. */
function mergeParts(parts: Option['parts']): PartRow[] {
    const merged = new Map<string, PartRow>();

    for (const p of parts) {
        const key = `${p.kind}:${p.id}`;
        const qty = Number(merged.get(key)?.qty ?? 0) + Number(p.qty);
        merged.set(key, { kind: p.kind, id: p.id, qty: String(qty) });
    }

    return [...merged.values()];
}

function analyse(price: number, cost: number) {
    const profit = price - cost;

    return { profit, margin: price ? profit / price : 0, markup: cost ? profit / cost : 0 };
}

export default function OptionForm({ product, option, copyFrom, components, materials, minMargin, priceStep }: {
    product: { id: number; code: string; name: string };
    option: Option | null;
    copyFrom: Option | null;
    components: Component[];
    materials: MaterialRef[];
    minMargin: number;
    priceStep: number;
}) {
    // A copied kit starts with everything filled in except its code.
    const source = option ?? copyFrom;
    const form = useForm({
        code: option?.code ?? `${product.code}-`,
        name: source?.name ?? '',
        retail_price: source ? String(source.retail_price) : '0',
        wholesale_price: source?.wholesale_price == null ? '' : String(source.wholesale_price),
        courier_packing_component_id: source?.courier_packing_component_id ? String(source.courier_packing_component_id) : '',
        courier_fee_estimate: source ? String(source.courier_fee_estimate) : '0',
        active: option?.active ?? true,
        parts: mergeParts(source?.parts ?? []),
    });

    const items: KitItem[] = [
        ...components.map((c) => ({ kind: 'component' as const, id: c.id, group: c.group, label: `${c.code} ${c.name}`, unit: 'pc', unit_cost: c.unit_cost })),
        ...materials.map((m) => ({ kind: 'material' as const, id: m.id, group: m.group, label: m.name, unit: m.unit, unit_cost: m.unit_cost, measured_qty: m.measured_qty })),
    ];
    const itemFor = (p: PartRow) => items.find((item) => item.kind === p.kind && item.id === p.id);

    const errors = form.errors as Record<string, string | undefined>;

    const unitCost = (p: PartRow): number => itemFor(p)?.unit_cost ?? 0;

    const kitCost = form.data.parts.reduce((sum, p) => sum + unitCost(p) * (Number(p.qty) || 0), 0);
    const packing = components.find((c) => String(c.id) === form.data.courier_packing_component_id)?.unit_cost ?? 0;
    const courierExtra = packing + (Number(form.data.courier_fee_estimate) || 0);
    const costCourier = kitCost + courierExtra;
    const retail = Number(form.data.retail_price) || 0;
    const wholesale = form.data.wholesale_price === '' ? null : Number(form.data.wholesale_price) || 0;
    const r = analyse(retail, kitCost);
    const priceFor = (cost: number, margin: number) => Math.ceil(Math.round((cost / (1 - margin)) * 1e6) / 1e6 / priceStep) * priceStep;

    const submit = () => {
        if (option) {
            form.put(options.update.url(option.id));
        } else {
            form.post(options.store.url(product.id));
        }
    };

    const priceRows = [
        ['Retail', retail],
        ...(wholesale !== null ? [['Wholesale', wholesale] as const] : []),
    ] as const;

    return (
        <>
            <Head title={option ? `Edit ${option.code}` : 'New product'} />
            <Page>
                <PageHeader title={option ? `Edit ${option.code} ${option.name}` : 'New product'} description={`${product.code} ${product.name} · pick what goes in the kit, then set your price`}>
                    <Button variant="outline" asChild><Link href={option ? options.show(option.id) : products.show(product.id)}>Cancel</Link></Button>
                    <Button onClick={submit} disabled={form.processing}>{option ? 'Save changes' : 'Create product'}</Button>
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat label="Kit cost" value={rs(kitCost)} hint={`With courier ${rs(costCourier)}`} />
                    <Stat label="Selling price" value={rs(retail)} hint={wholesale !== null ? `Wholesale ${rs(wholesale)}` : 'Retail'} />
                    <Stat label="Profit" value={rs(r.profit)} tone={r.profit < 0 ? 'bad' : 'good'} hint={`With courier ${rs(retail - costCourier)}`} />
                    <Stat label="Profit %" value={pct(r.margin)} tone={retail > 0 && r.margin < minMargin ? 'bad' : 'good'} hint={`${pct(r.markup)} on cost`} />
                </div>

                <Panel title="Product details">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Product code" error={form.errors.code}><Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
                        <Field label="Name" error={form.errors.name} className="sm:col-span-2"><Input value={form.data.name} placeholder="1 figure, 2 paints, 1 brush" onChange={(e) => form.setData('name', e.target.value)} /></Field>
                    </div>
                    <label className="mt-4 flex items-center gap-2 text-sm"><Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} /> Active (shown on the sale form)</label>
                </Panel>

                <Panel title="What goes in this kit" flush>
                    <KitPicker items={items} parts={form.data.parts} onChange={(parts) => form.setData('parts', parts)} errors={errors} />
                </Panel>

                <Panel title={`Kit contains (${form.data.parts.length})`} flush>
                    <Table>
                        <thead><tr><th>Item</th><th className="text-right">Qty</th><th className="text-right">Unit cost</th><th className="text-right">Cost</th><th /></tr></thead>
                        <tbody>
                            {form.data.parts.length === 0 && <EmptyRow colSpan={5}>Nothing selected yet. Click items above to add them.</EmptyRow>}
                            {form.data.parts.map((p, i) => {
                                const item = itemFor(p);
                                const comp = p.kind === 'component' ? components.find((c) => c.id === p.id) : undefined;

                                return (
                                    <tr key={`${p.kind}:${p.id}:${i}`}>
                                        <td className="whitespace-normal!">
                                            <span className="font-medium">{item?.label}</span>
                                            {comp && comp.stages.length > 0 && <p className="text-xs text-muted-foreground">{comp.stages.map((s) => `${s.name} ${rs(s.cost)}`).join(' · ')}</p>}
                                        </td>
                                        <td className="text-right tabular-nums">{num(p.qty, 3)} {item?.unit}</td>
                                        <td className="text-right tabular-nums">{rs(unitCost(p))}</td>
                                        <td className="text-right font-medium tabular-nums">{rs(unitCost(p) * (Number(p.qty) || 0))}</td>
                                        <td><Button size="icon" variant="ghost" aria-label={`Remove ${item?.label}`} onClick={() => form.setData('parts', form.data.parts.filter((_, j) => j !== i))}><X /></Button></td>
                                    </tr>
                                );
                            })}
                            <tr className="bg-brand-teal-tint font-semibold text-brand-teal"><td>Kit cost</td><td /><td /><td className="text-right tabular-nums">{rs(kitCost)}</td><td /></tr>
                        </tbody>
                    </Table>
                </Panel>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Panel title="Courier cost (only when sent by courier)">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Courier packing" error={form.errors.courier_packing_component_id}>
                                <NativeSelect value={form.data.courier_packing_component_id} onChange={(e) => form.setData('courier_packing_component_id', e.target.value)}>
                                    <option value="">None</option>
                                    {components.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name} ({rs(c.unit_cost)})</option>)}
                                </NativeSelect>
                            </Field>
                            <Field label="Courier fee estimate" error={form.errors.courier_fee_estimate}><NumberInput value={form.data.courier_fee_estimate} onChange={(e) => form.setData('courier_fee_estimate', e.target.value)} /></Field>
                        </div>
                        <p className="mt-3 text-sm text-muted-foreground">Kit cost {rs(kitCost)} + courier {rs(courierExtra)} = <span className="font-semibold text-foreground">{rs(costCourier)}</span></p>
                    </Panel>

                    <Panel title="Price for a profit %" flush>
                        <Table>
                            <thead><tr><th>Profit %</th><th className="text-right">Price</th><th className="text-right">Profit</th><th className="text-right">With courier</th></tr></thead>
                            <tbody>
                                {MARGINS.map((m) => {
                                    const price = priceFor(kitCost, m);
                                    const priceC = priceFor(costCourier, m);

                                    return (
                                        <tr key={m}>
                                            <td>{pct(m)}</td>
                                            <td className="text-right tabular-nums"><button type="button" className="font-medium text-primary hover:underline" onClick={() => form.setData('retail_price', String(price))}>{rs(price, 0)}</button></td>
                                            <td className="text-right tabular-nums">{rs(price - kitCost)}</td>
                                            <td className="text-right tabular-nums"><button type="button" className="text-primary hover:underline" onClick={() => form.setData('retail_price', String(priceC))}>{rs(priceC, 0)}</button></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                        <p className="px-3 py-2 text-xs text-muted-foreground">Profit % is profit out of the selling price. Click a price to use it.</p>
                    </Panel>
                </div>

                <Panel title="My price and profit">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Retail price (Rs.)" error={form.errors.retail_price}><NumberInput value={form.data.retail_price} onChange={(e) => form.setData('retail_price', e.target.value)} /></Field>
                        <Field label="Wholesale price (optional)" error={form.errors.wholesale_price}><NumberInput value={form.data.wholesale_price} onChange={(e) => form.setData('wholesale_price', e.target.value)} /></Field>
                    </div>
                    <div className="mt-4 overflow-hidden rounded-md border">
                        <Table>
                            <thead><tr><th>Price</th><th>Cost used</th><th className="text-right">Cost</th><th className="text-right">Profit</th><th className="text-right">Profit %</th><th className="text-right">On cost</th></tr></thead>
                            <tbody>
                                {priceRows.flatMap(([label, price]) =>
                                    [['Without courier', kitCost], ['With courier', costCourier]].map(([name, cost]) => {
                                        const a = analyse(price, cost as number);
                                        const low = price > 0 && a.margin < minMargin;

                                        return (
                                            <tr key={`${label}${name}`} className={cn(low && 'text-red-600 dark:text-red-400')}>
                                                <td>{label} {rs(price)}</td>
                                                <td>{name as string}</td>
                                                <td className="text-right tabular-nums">{rs(cost as number)}</td>
                                                <td className="text-right font-medium tabular-nums">{rs(a.profit)}</td>
                                                <td className="text-right tabular-nums">{pct(a.margin)}{low && ' (low)'}</td>
                                                <td className="text-right tabular-nums">{pct(a.markup)}</td>
                                            </tr>
                                        );
                                    }),
                                )}
                            </tbody>
                        </Table>
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">Minimum profit set in Business settings: {num(minMargin * 100)}%. Rows below it show in red.</p>
                    <div className="mt-4 flex justify-end">
                        <Button onClick={submit} disabled={form.processing}>{option ? 'Save changes' : 'Create product'}</Button>
                    </div>
                </Panel>
            </Page>
        </>
    );
}

OptionForm.layout = { breadcrumbs: [{ title: 'Products', href: options.index() }] };
