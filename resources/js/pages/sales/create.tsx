import { Head, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Pickable } from '@/components/paint-dots';
import { FigureRows, PaintRows, setRowsPayload } from '@/components/set-rows';
import type { FigureRow, PaintRow } from '@/components/set-rows';
import { SearchSelect } from '@/components/search-select';
import { Field, NativeSelect, NumberInput, Page, PageHeader, Panel, Table, Textarea, rs, today } from '@/components/ops';
import * as customers from '@/routes/customers';
import * as sales from '@/routes/sales';

type OptionRef = { id: number; code: string; name: string; product_id: number; category_id: number | null; retail_price: number; wholesale_price: number | null; picks: boolean; figures_per_kit: number; paint_pots_per_kit: number } & Pickable;
type Named = { id: number; name: string };
type Kit = Named & { category_id: number | null };
type Customer = { id: number; name: string; phone: string; address: string | null; city: string | null; type: string };
type Part = { type: 'component' | 'material'; id: string; qty: string };
type DiscountType = 'amount' | 'percent';
type Line = {
    custom: boolean; name: string; parts: Part[]; category_id: string; product_id: string; product_option_id: string; qty: string; unit_price: string;
    discount_type: DiscountType; discount_value: string; offer_name: string; pick_now: boolean; figures: FigureRow[]; paint: PaintRow[];
};

const newLine = (custom = false): Line => ({
    custom, name: '', parts: [{ type: 'component', id: '', qty: '1' }], category_id: '', product_id: '', product_option_id: '', qty: '1', unit_price: '',
    discount_type: 'amount', discount_value: '', offer_name: '', pick_now: false, figures: [], paint: [],
});

/** Rs taken off a price: a percentage of it or a fixed amount, never more than the price. Matches SaleService::discountAmount. */
const discountOf = (type: DiscountType, value: string, price: number) => {
    const amount = type === 'percent' ? (price * (Number(value) || 0)) / 100 : Number(value) || 0;

    return Math.round(Math.min(Math.max(amount, 0), price) * 100) / 100;
};

const offerPrice = (l: Line) => (Number(l.unit_price) || 0) - discountOf(l.discount_type, l.discount_value, Number(l.unit_price) || 0);

type PartRef = { id: number; name: string; code?: string; unit?: string };

export default function SaleCreate({ customers: savedCustomers, categories, kits, options, components, materials }: { customers: Customer[]; categories: Named[]; kits: Kit[]; options: OptionRef[]; components: PartRef[]; materials: PartRef[] }) {
    const form = useForm({
        sold_on: today(),
        sale_type: 'retail',
        payment_method: 'cash',
        delivery_method: 'pickup',
        delivery_address: '',
        customer: { phone: '', name: '', address: '', city: '' },
        discount_type: 'amount' as DiscountType,
        discount: '0',
        offer_name: '',
        courier_charge: '0',
        courier_charge_payer: 'customer_on_delivery',
        amount_paid: '',
        due_date: '',
        notes: '',
        items: [newLine()],
    });
    const d = form.data;
    const errors = form.errors as Record<string, string>;
    const [matches, setMatches] = useState<Customer[]>([]);
    const [query, setQuery] = useState('');
    const [savedId, setSavedId] = useState('');

    useEffect(() => {
        if (query.trim().length < 2) {
            setMatches([]);

            return;
        }

        const timer = setTimeout(() => {
            fetch(customers.search.url({ query: { q: query } }), { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then(setMatches)
                .catch(() => setMatches([]));
        }, 250);

        return () => clearTimeout(timer);
    }, [query]);

    const priceFor = (optionId: string, type: string) => {
        const o = options.find((x) => String(x.id) === optionId);

        if (!o) {
            return '';
        }

        return String(type === 'wholesale' && o.wholesale_price !== null ? o.wholesale_price : o.retail_price);
    };

    const pickCustomer = (id: string) => {
        setSavedId(id);
        const c = savedCustomers.find((x) => String(x.id) === id);

        if (!c) {
            return;
        }

        setCustomer({ phone: c.phone, name: c.name, address: c.address ?? '', city: c.city ?? '' });
        form.setData('delivery_address', [c.address, c.city].filter(Boolean).join(', '));
        setMatches([]);
        setQuery('');
    };

    const kitsFor = (categoryId: string) => kits.filter((k) => !categoryId || String(k.category_id) === categoryId);
    const optionsFor = (productId: string, categoryId: string) =>
        options.filter((o) => (productId ? String(o.product_id) === productId : !categoryId || String(o.category_id) === categoryId));

    const lineFromOption = (optionId: string): Partial<Line> => {
        const o = options.find((x) => String(x.id) === optionId);

        return o
            ? { category_id: String(o.category_id ?? ''), product_id: String(o.product_id), product_option_id: optionId, unit_price: priceFor(optionId, d.sale_type), pick_now: false, figures: [], paint: [] }
            : { product_option_id: '', unit_price: '', pick_now: false, figures: [], paint: [] };
    };

    const pickCategory = (i: number, categoryId: string) => {
        const kitList = kitsFor(categoryId);
        const productId = kitList.length === 1 ? String(kitList[0].id) : '';
        const optionList = productId ? optionsFor(productId, categoryId) : [];

        setLine(i, { category_id: categoryId, product_id: productId, ...(optionList.length === 1 ? lineFromOption(String(optionList[0].id)) : { product_option_id: '', unit_price: '' }) });
    };

    const pickKit = (i: number, productId: string) => {
        const kit = kits.find((k) => String(k.id) === productId);
        const optionList = optionsFor(productId, '');

        setLine(i, { product_id: productId, category_id: kit ? String(kit.category_id ?? '') : d.items[i].category_id, ...(optionList.length === 1 ? lineFromOption(String(optionList[0].id)) : { product_option_id: '', unit_price: '' }) });
    };

    const setLine = (i: number, patch: Partial<Line>) => form.setData('items', d.items.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const setCustomer = (patch: Partial<typeof d.customer>) => form.setData('customer', { ...d.customer, ...patch });

    const switchType = (type: string) => {
        form.setData((prev) => ({
            ...prev,
            sale_type: type,
            items: prev.items.map((l) => ({ ...l, unit_price: l.product_option_id ? priceFor(l.product_option_id, type) : l.unit_price })),
        }));
    };

    const subtotal = d.items.reduce((s, l) => s + (Number(l.qty) || 0) * offerPrice(l), 0);
    const courier = d.delivery_method === 'courier' ? Number(d.courier_charge) || 0 : 0;
    const courierChargeToCustomer = d.courier_charge_payer === 'business' ? 0 : courier;
    const billDiscount = discountOf(d.discount_type, d.discount, subtotal);
    const total = subtotal - billDiscount + courierChargeToCustomer;

    return (
        <>
            <Head title="New sale" />
            <Page>
                <PageHeader title="New sale" />
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            items: data.items.map((l) => (l.custom ? l : {
                                ...l,
                                parts: [],
                                assign: l.pick_now ? setRowsPayload(l.figures, l.paint) : { figures: [], paint: [] },
                            })),
                        }));
                        form.post(sales.store.url());
                    }}
                >
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title="Customer">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Saved customer" className="sm:col-span-2">
                                    <SearchSelect
                                        clearable
                                        value={savedId}
                                        placeholder="Search saved customers by name or phone..."
                                        options={savedCustomers.map((c) => ({ value: String(c.id), label: c.name, hint: c.phone }))}
                                        onChange={(id) => {
                                            if (id) {
                                                pickCustomer(id);
                                            } else {
                                                setSavedId('');
                                                setCustomer({ phone: '', name: '', address: '', city: '' });
                                            }
                                        }}
                                    />
                                </Field>
                                <Field label="Phone" error={errors['customer.phone']} className="relative">
                                    <Input
                                        value={d.customer.phone}
                                        autoComplete="off"
                                        onChange={(e) => {
                                            setSavedId('');
                                            setCustomer({ phone: e.target.value });
                                            setQuery(e.target.value);
                                        }}
                                    />
                                    {matches.length > 0 && (
                                        <div className="absolute top-full z-10 mt-1 w-full rounded-md border bg-popover shadow">
                                            {matches.map((c) => (
                                                <button
                                                    key={c.id}
                                                    type="button"
                                                    className="block w-full px-3 py-2 text-left text-sm hover:bg-accent"
                                                    onClick={() => pickCustomer(String(c.id))}
                                                >
                                                    {c.name} · {c.phone} <span className="text-muted-foreground">{c.city}</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </Field>
                                <Field label="Name" error={errors['customer.name']}>
                                    <Input value={d.customer.name} onChange={(e) => { setSavedId(''); setCustomer({ name: e.target.value }); setQuery(e.target.value); }} />
                                </Field>
                                <Field label="Address"><Input value={d.customer.address} onChange={(e) => setCustomer({ address: e.target.value })} /></Field>
                                <Field label="City"><Input value={d.customer.city} onChange={(e) => setCustomer({ city: e.target.value })} /></Field>
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">Pick a saved customer, or type a new phone and name: a new customer is created automatically when the sale is saved.</p>
                        </Panel>

                        <Panel title="Sale">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Date" error={errors.sold_on}><Input type="date" value={d.sold_on} onChange={(e) => form.setData('sold_on', e.target.value)} /></Field>
                                <Field label="Type">
                                    <NativeSelect value={d.sale_type} onChange={(e) => switchType(e.target.value)}>
                                        <option value="retail">Retail</option>
                                        <option value="wholesale">Wholesale</option>
                                    </NativeSelect>
                                </Field>
                                <Field label="Payment">
                                    <NativeSelect value={d.payment_method} onChange={(e) => form.setData('payment_method', e.target.value)}>
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank transfer</option>
                                        <option value="cod">Cash on delivery</option>
                                    </NativeSelect>
                                </Field>
                                <Field label="Delivery">
                                    <NativeSelect value={d.delivery_method} onChange={(e) => form.setData('delivery_method', e.target.value)}>
                                        <option value="pickup">Pickup</option>
                                        <option value="courier">Courier</option>
                                    </NativeSelect>
                                </Field>
                                {d.delivery_method === 'courier' && (
                                    <Field label="Delivery address" className="sm:col-span-2"><Input value={d.delivery_address} onChange={(e) => form.setData('delivery_address', e.target.value)} /></Field>
                                )}
                            </div>
                        </Panel>
                    </div>

                    <Panel title="Items">
                        {errors.items && <p className="mb-2 text-sm text-red-600">{errors.items}</p>}
                        <div className="space-y-3">
                            {d.items.map((l, i) => (
                                <div key={i} className="rounded-lg border p-3">
                                    <div className="mb-2 flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">Item {i + 1}{l.custom ? ' · Custom kit' : ''}</span>
                                        {(d.items.length > 1 || l.custom) && (
                                            <Button type="button" variant="ghost" size="icon" onClick={() => form.setData('items', d.items.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
                                        )}
                                    </div>

                                    {l.custom ? (
                                        <CustomKit line={l} index={i} components={components} materials={materials} onChange={(patch) => setLine(i, patch)} errors={errors} />
                                    ) : (
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            <Field label="Code">
                                                <SearchSelect
                                                    value={l.product_option_id}
                                                    placeholder="Code..."
                                                    invalid={!!errors[`items.${i}.product_option_id`]}
                                                    options={options.map((o) => ({ value: String(o.id), label: o.code, hint: o.name }))}
                                                    onChange={(id) => setLine(i, lineFromOption(id))}
                                                />
                                            </Field>
                                            <Field label="Category">
                                                <SearchSelect
                                                    clearable
                                                    value={l.category_id}
                                                    placeholder="Category..."
                                                    options={categories.map((c) => ({ value: String(c.id), label: c.name }))}
                                                    onChange={(id) => pickCategory(i, id)}
                                                />
                                            </Field>
                                            <Field label="Kit">
                                                <SearchSelect
                                                    clearable
                                                    value={l.product_id}
                                                    placeholder="Kit..."
                                                    options={kitsFor(l.category_id).map((k) => ({ value: String(k.id), label: k.name }))}
                                                    onChange={(id) => pickKit(i, id)}
                                                />
                                            </Field>
                                            <Field label="Product">
                                                <SearchSelect
                                                    value={l.product_option_id}
                                                    placeholder="Product..."
                                                    invalid={!!errors[`items.${i}.product_option_id`]}
                                                    options={optionsFor(l.product_id, l.category_id).map((o) => ({ value: String(o.id), label: o.name, hint: o.code }))}
                                                    onChange={(id) => setLine(i, lineFromOption(id))}
                                                />
                                            </Field>
                                        </div>
                                    )}

                                    {!l.custom && (() => {
                                        const o = options.find((x) => String(x.id) === l.product_option_id);

                                        if (!o || !o.picks || (o.figures_per_kit <= 0 && o.paint_pots_per_kit <= 0)) {
                                            return null;
                                        }

                                        const needs = [o.figures_per_kit > 0 && `${o.figures_per_kit} ${o.figures_per_kit === 1 ? 'figure' : 'figures'}`, o.paint_pots_per_kit > 0 && `a ${o.paint_pots_per_kit}-pot paint set`].filter(Boolean).join(' and ');

                                        return l.pick_now ? (
                                            <div className="mt-3 space-y-2">
                                                <div className="flex items-center justify-between gap-2 text-sm">
                                                    <span className="text-muted-foreground">Each kit takes {needs}. Pick for some or all kits; the rest can be picked later on the sale.</span>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => setLine(i, { pick_now: false, figures: [], paint: [] })}>Pick later</Button>
                                                </div>
                                                <div className="grid gap-3 lg:grid-cols-2">
                                                    {o.figures_per_kit > 0 && <FigureRows needs={o} total={Number(l.qty) || 0} rows={l.figures} error={errors[`items.${i}.assign.figures`]} onChange={(figures) => setLine(i, { figures })} />}
                                                    {o.paint_pots_per_kit > 0 && <PaintRows needs={o} total={Number(l.qty) || 0} rows={l.paint} error={errors[`items.${i}.assign.paint`]} onChange={(paint) => setLine(i, { paint })} />}
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2 text-sm">
                                                <span><span className="font-medium">Each kit takes {needs}.</span> <span className="text-muted-foreground">Not picked yet: you can pick them later on the sale.</span></span>
                                                <Button type="button" variant="outline" size="sm" onClick={() => setLine(i, { pick_now: true })}>Pick figures and paint now</Button>
                                            </div>
                                        );
                                    })()}

                                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <Field label="Qty">
                                            <NumberInput value={l.qty} onChange={(e) => setLine(i, { qty: e.target.value })} aria-invalid={!!errors[`items.${i}.qty`]} />
                                        </Field>
                                        <Field label="Normal price">
                                            <NumberInput value={l.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} aria-invalid={!!errors[`items.${i}.unit_price`]} />
                                        </Field>
                                        <Field label="Offer discount" className="sm:col-span-2 lg:col-span-1">
                                            <LineDiscount line={l} error={errors[`items.${i}.discount_value`]} onChange={(patch) => setLine(i, patch)} />
                                        </Field>
                                        <div className="flex flex-col items-end justify-end text-right">
                                            <span className="text-xs text-muted-foreground">Line total</span>
                                            <span className="tabular-nums"><LineTotal line={l} /></span>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={() => form.setData('items', [...d.items, newLine()])}>Add item</Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => form.setData('items', [...d.items, newLine(true)])}>Add custom kit</Button>
                        </div>
                    </Panel>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title="Payment">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Bill discount" error={errors.discount}>
                                    <div className="flex gap-2">
                                        <NativeSelect className="w-20 shrink-0" aria-label="Bill discount type" value={d.discount_type} onChange={(e) => form.setData('discount_type', e.target.value as DiscountType)}>
                                            <option value="amount">Rs</option>
                                            <option value="percent">%</option>
                                        </NativeSelect>
                                        <NumberInput value={d.discount} onChange={(e) => form.setData('discount', e.target.value)} />
                                    </div>
                                </Field>
                                <Field label="Bill offer name" error={errors.offer_name}><Input value={d.offer_name} placeholder="e.g. Christmas offer" onChange={(e) => form.setData('offer_name', e.target.value)} /></Field>
                                {d.delivery_method === 'courier' && (
                                    <>
                                        <Field label="Courier charge" error={errors.courier_charge}><NumberInput value={d.courier_charge} onChange={(e) => form.setData('courier_charge', e.target.value)} /></Field>
                                        <Field label="Who pays the courier charge" error={errors.courier_charge_payer}>
                                            <NativeSelect value={d.courier_charge_payer} onChange={(e) => form.setData('courier_charge_payer', e.target.value)}>
                                                <option value="customer_on_delivery">Customer, on delivery (COD)</option>
                                                <option value="customer_bank_transfer">Customer, by bank transfer now</option>
                                                <option value="business">We absorb it (deduct from courier's remittance)</option>
                                            </NativeSelect>
                                        </Field>
                                    </>
                                )}
                                {d.payment_method !== 'cod' && (
                                    <Field label={d.sale_type === 'retail' ? 'Amount paid now (blank = full)' : 'Deposit paid now'} error={errors.amount_paid}>
                                        <NumberInput value={d.amount_paid} placeholder={d.sale_type === 'retail' ? String(total) : '0'} onChange={(e) => form.setData('amount_paid', e.target.value)} />
                                    </Field>
                                )}
                                {d.sale_type === 'wholesale' && (
                                    <Field label="Due date" error={errors.due_date}><Input type="date" value={d.due_date} onChange={(e) => form.setData('due_date', e.target.value)} /></Field>
                                )}
                                <Field label="Notes" className="sm:col-span-2"><Textarea value={d.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                            </div>
                        </Panel>
                        <Panel title="Totals">
                            <Table>
                                <tbody>
                                    <tr><td>Subtotal</td><td className="text-right tabular-nums">{rs(subtotal)}</td></tr>
                                    <tr><td>Discount{d.discount_type === 'percent' && Number(d.discount) > 0 ? ` (${d.discount}%)` : ''}{d.offer_name ? ` · ${d.offer_name}` : ''}</td><td className="text-right tabular-nums">-{rs(billDiscount)}</td></tr>
                                    {d.delivery_method === 'courier' && (
                                        <tr>
                                            <td>Courier{d.courier_charge_payer === 'business' ? ' (we absorb, not billed)' : ''}</td>
                                            <td className="text-right tabular-nums">{rs(courierChargeToCustomer)}</td>
                                        </tr>
                                    )}
                                    <tr className="text-lg font-semibold"><td>Total</td><td className="text-right tabular-nums">{rs(total)}</td></tr>
                                </tbody>
                            </Table>
                            {d.payment_method === 'cod' && <p className="mt-2 text-sm text-sky-700 dark:text-sky-300">Cash on delivery: no payment now. Add a COD remittance when the courier pays you.</p>}
                            {d.delivery_method === 'courier' && d.courier_charge_payer === 'customer_on_delivery' && <p className="mt-2 text-sm text-sky-700 dark:text-sky-300">Courier charge is collected by the courier from the customer on delivery, along with the rest if COD.</p>}
                            {d.delivery_method === 'courier' && d.courier_charge_payer === 'business' && <p className="mt-2 text-sm text-amber-700 dark:text-amber-300">Courier charge isn't billed to the customer. Record it as the courier's deduction (COD fee) when adding the remittance payment.</p>}
                            <Button className="mt-4 w-full" disabled={form.processing}>Save sale</Button>
                        </Panel>
                    </div>
                </form>
            </Page>
        </>
    );
}

function LineDiscount({ line, error, onChange }: { line: Line; error?: string; onChange: (patch: Partial<Line>) => void }) {
    return (
        <div className="min-w-48 space-y-1">
            <div className="flex gap-1">
                <NativeSelect className="w-16 shrink-0" aria-label="Discount type" value={line.discount_type} onChange={(e) => onChange({ discount_type: e.target.value as DiscountType })}>
                    <option value="amount">Rs</option>
                    <option value="percent">%</option>
                </NativeSelect>
                <NumberInput value={line.discount_value} placeholder="0" aria-label="Discount per unit" aria-invalid={!!error} onChange={(e) => onChange({ discount_value: e.target.value })} />
            </div>
            <Input value={line.offer_name} placeholder="Offer name (optional)" aria-label="Offer name" onChange={(e) => onChange({ offer_name: e.target.value })} />
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}

function LineTotal({ line }: { line: Line }) {
    const price = Number(line.unit_price) || 0;
    const offer = offerPrice(line);

    return (
        <>
            {rs((Number(line.qty) || 0) * offer)}
            {offer < price && <div className="text-xs text-muted-foreground"><s>{rs(price)}</s> → {rs(offer)} each</div>}
        </>
    );
}

function CustomKit({ line, index, components, materials, onChange, errors }: { line: Line; index: number; components: PartRef[]; materials: PartRef[]; onChange: (patch: Partial<Line>) => void; errors: Record<string, string> }) {
    const setPart = (n: number, patch: Partial<Part>) => onChange({ parts: line.parts.map((p, j) => (j === n ? { ...p, ...patch } : p)) });

    return (
        <div className="min-w-72 space-y-2 py-1">
            <Input value={line.name} placeholder="Custom kit name (optional)" onChange={(e) => onChange({ name: e.target.value })} />
            {errors[`items.${index}.parts`] && <p className="text-sm text-red-600">{errors[`items.${index}.parts`]}</p>}
            {line.parts.map((p, n) => (
                <div key={n} className="grid grid-cols-[7rem_1fr_5rem_auto] items-center gap-2">
                    <NativeSelect value={p.type} onChange={(e) => setPart(n, { type: e.target.value as Part['type'], id: '' })}>
                        <option value="component">Component</option>
                        <option value="material">Material</option>
                    </NativeSelect>
                    <SearchSelect
                        value={p.id}
                        placeholder={p.type === 'component' ? 'Search components...' : 'Search materials...'}
                        invalid={!!errors[`items.${index}.parts.${n}.id`]}
                        options={(p.type === 'component' ? components : materials).map((x) => ({ value: String(x.id), label: x.name, hint: x.code ?? x.unit }))}
                        onChange={(id) => setPart(n, { id })}
                    />
                    <NumberInput value={p.qty} aria-label="Quantity" onChange={(e) => setPart(n, { qty: e.target.value })} />
                    <Button type="button" variant="ghost" size="icon" disabled={line.parts.length === 1} onClick={() => onChange({ parts: line.parts.filter((_, j) => j !== n) })}><Trash2 className="size-4" /></Button>
                </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={() => onChange({ parts: [...line.parts, { type: 'component', id: '', qty: '1' }] })}>Add part</Button>
            <p className="text-xs text-muted-foreground">Quantities are per kit. The chosen contents are saved with this sale and unaffected by later recipe changes.</p>
        </div>
    );
}

SaleCreate.layout = { breadcrumbs: [{ title: 'Sales', href: sales.index() }, { title: 'New', href: sales.create() }] };
