import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Field, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, num, pct, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import * as options from '@/routes/options';
import * as products from '@/routes/products';

type Component = { id: number; code: string; name: string; unit_cost: number; stages: { name: string; cost: number }[] };
type MaterialRef = { id: number; name: string; unit: string; unit_cost: number };
type PartRow = { kind: 'component' | 'material'; id: number | ''; qty: string };
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

function analyse(price: number, cost: number) {
    const profit = price - cost;

    return { profit, margin: price ? profit / price : 0, markup: cost ? profit / cost : 0 };
}

export default function OptionForm({ product, option, components, materials, minMargin, priceStep }: {
    product: { id: number; code: string; name: string };
    option: Option | null;
    components: Component[];
    materials: MaterialRef[];
    minMargin: number;
    priceStep: number;
}) {
    const form = useForm({
        code: option?.code ?? `${product.code}-`,
        name: option?.name ?? '',
        retail_price: option ? String(option.retail_price) : '0',
        wholesale_price: option?.wholesale_price == null ? '' : String(option.wholesale_price),
        courier_packing_component_id: option?.courier_packing_component_id ? String(option.courier_packing_component_id) : '',
        courier_fee_estimate: option ? String(option.courier_fee_estimate) : '0',
        active: option?.active ?? true,
        parts: (option?.parts.map((p) => ({ kind: p.kind, id: p.id, qty: String(p.qty) })) ?? [{ kind: 'component', id: '', qty: '1' }]) as PartRow[],
    });

    const errors = form.errors as Record<string, string | undefined>;

    const unitCost = (p: PartRow): number => {
        if (p.id === '') {
            return 0;
        }

        return p.kind === 'component' ? (components.find((c) => c.id === p.id)?.unit_cost ?? 0) : (materials.find((m) => m.id === p.id)?.unit_cost ?? 0);
    };

    const kitCost = form.data.parts.reduce((sum, p) => sum + unitCost(p) * (Number(p.qty) || 0), 0);
    const packing = components.find((c) => String(c.id) === form.data.courier_packing_component_id)?.unit_cost ?? 0;
    const courierExtra = packing + (Number(form.data.courier_fee_estimate) || 0);
    const costCourier = kitCost + courierExtra;
    const retail = Number(form.data.retail_price) || 0;
    const wholesale = form.data.wholesale_price === '' ? null : Number(form.data.wholesale_price) || 0;
    const r = analyse(retail, kitCost);
    const priceFor = (cost: number, margin: number) => Math.ceil(Math.round((cost / (1 - margin)) * 1e6) / 1e6 / priceStep) * priceStep;

    const setPart = (index: number, patch: Partial<PartRow>) => form.setData('parts', form.data.parts.map((p, i) => (i === index ? { ...p, ...patch } : p)));

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
            <Head title={option ? `Edit ${option.code}` : 'New kit option'} />
            <Page>
                <PageHeader title={option ? `Edit ${option.code} ${option.name}` : 'New kit option'} description={`${product.code} ${product.name} · pick what goes in the kit, then set your price`}>
                    <Button variant="outline" asChild><Link href={option ? options.show(option.id) : products.show(product.id)}>Cancel</Link></Button>
                    <Button onClick={submit} disabled={form.processing}>{option ? 'Save changes' : 'Create option'}</Button>
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat label="Kit cost" value={rs(kitCost)} hint={`With courier ${rs(costCourier)}`} />
                    <Stat label="Selling price" value={rs(retail)} hint={wholesale !== null ? `Wholesale ${rs(wholesale)}` : 'Retail'} />
                    <Stat label="Profit" value={rs(r.profit)} tone={r.profit < 0 ? 'bad' : 'good'} hint={`With courier ${rs(retail - costCourier)}`} />
                    <Stat label="Profit %" value={pct(r.margin)} tone={retail > 0 && r.margin < minMargin ? 'bad' : 'good'} hint={`${pct(r.markup)} on cost`} />
                </div>

                <Panel title="Option details">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Option code" error={form.errors.code}><Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
                        <Field label="Name" error={form.errors.name} className="sm:col-span-2"><Input value={form.data.name} placeholder="1 figure, 2 paints, 1 brush" onChange={(e) => form.setData('name', e.target.value)} /></Field>
                    </div>
                    <label className="mt-4 flex items-center gap-2 text-sm"><Checkbox checked={form.data.active} onCheckedChange={(v) => form.setData('active', v === true)} /> Active (shown on the sale form)</label>
                </Panel>

                <Panel
                    title="What goes in this kit"
                    flush
                    actions={<Button size="sm" variant="outline" onClick={() => form.setData('parts', [...form.data.parts, { kind: 'component', id: '', qty: '1' }])}><Plus /> Add item</Button>}
                >
                    <Table>
                        <thead><tr><th className="w-1/2">Item (cost estimate or material)</th><th className="text-right">Qty</th><th className="text-right">Unit cost</th><th className="text-right">Cost</th><th /></tr></thead>
                        <tbody>
                            {form.data.parts.map((p, i) => {
                                const comp = p.kind === 'component' ? components.find((c) => c.id === p.id) : undefined;

                                return (
                                    <tr key={i} className="align-top">
                                        <td className="whitespace-normal!">
                                            <NativeSelect
                                                value={p.id === '' ? '' : `${p.kind}:${p.id}`}
                                                onChange={(e) => {
                                                    const [kind, id] = e.target.value.split(':');
                                                    setPart(i, { kind: (kind || 'component') as PartRow['kind'], id: id ? Number(id) : '' });
                                                }}
                                            >
                                                <option value="">Choose...</option>
                                                <optgroup label="Cost estimates (figure, painting set, packing...)">
                                                    {components.map((c) => <option key={c.id} value={`component:${c.id}`}>{c.code} {c.name} ({rs(c.unit_cost)})</option>)}
                                                </optgroup>
                                                <optgroup label="Single materials">
                                                    {materials.map((m) => <option key={m.id} value={`material:${m.id}`}>{m.name} ({rs(m.unit_cost, 2)}/{m.unit})</option>)}
                                                </optgroup>
                                            </NativeSelect>
                                            {errors[`parts.${i}.id`] && <p className="mt-1 text-xs text-red-600">{errors[`parts.${i}.id`]}</p>}
                                            {comp && comp.stages.length > 0 && (
                                                <p className="mt-1 text-xs text-muted-foreground">{comp.stages.map((s) => `${s.name} ${rs(s.cost)}`).join(' · ')}</p>
                                            )}
                                        </td>
                                        <td className="w-28"><NumberInput className="text-right" value={p.qty} onChange={(e) => setPart(i, { qty: e.target.value })} />{errors[`parts.${i}.qty`] && <p className="mt-1 text-xs text-red-600">Qty</p>}</td>
                                        <td className="pt-4! text-right tabular-nums">{rs(unitCost(p))}</td>
                                        <td className="pt-4! text-right font-medium tabular-nums">{rs(unitCost(p) * (Number(p.qty) || 0))}</td>
                                        <td className="pt-3!"><Button size="icon" variant="ghost" onClick={() => form.setData('parts', form.data.parts.filter((_, j) => j !== i))}><X /></Button></td>
                                    </tr>
                                );
                            })}
                            <tr className="bg-brand-teal-tint font-semibold text-brand-teal"><td>Kit cost</td><td /><td /><td className="text-right tabular-nums">{rs(kitCost)}</td><td /></tr>
                        </tbody>
                    </Table>
                    <p className="px-3 py-2 text-xs text-muted-foreground">Cost estimates come from your components (each with its own making stages). Add new ones under Components.</p>
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
                        <Button onClick={submit} disabled={form.processing}>{option ? 'Save changes' : 'Create option'}</Button>
                    </div>
                </Panel>
            </Page>
        </>
    );
}

OptionForm.layout = { breadcrumbs: [{ title: 'Kits', href: products.index() }] };
