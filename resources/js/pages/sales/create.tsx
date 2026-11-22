import { Head, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, NativeSelect, NumberInput, Page, PageHeader, Panel, Table, Textarea, rs, today } from '@/components/ops';
import * as customers from '@/routes/customers';
import * as sales from '@/routes/sales';

type OptionRef = { id: number; code: string; name: string; retail_price: number; wholesale_price: number | null };
type Customer = { id: number; name: string; phone: string; address: string | null; city: string | null; type: string };
type Line = { product_option_id: string; qty: string; unit_price: string };

const newLine = (): Line => ({ product_option_id: '', qty: '1', unit_price: '' });

export default function SaleCreate({ options }: { options: OptionRef[] }) {
    const form = useForm({
        sold_on: today(),
        sale_type: 'retail',
        payment_method: 'cash',
        delivery_method: 'pickup',
        delivery_address: '',
        customer: { phone: '', name: '', address: '', city: '' },
        discount: '0',
        courier_charge: '0',
        amount_paid: '',
        due_date: '',
        notes: '',
        items: [newLine()],
    });
    const d = form.data;
    const errors = form.errors as Record<string, string>;
    const [matches, setMatches] = useState<Customer[]>([]);
    const [query, setQuery] = useState('');

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

    const setLine = (i: number, patch: Partial<Line>) => form.setData('items', d.items.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const setCustomer = (patch: Partial<typeof d.customer>) => form.setData('customer', { ...d.customer, ...patch });

    const switchType = (type: string) => {
        form.setData((prev) => ({
            ...prev,
            sale_type: type,
            items: prev.items.map((l) => ({ ...l, unit_price: l.product_option_id ? priceFor(l.product_option_id, type) : l.unit_price })),
        }));
    };

    const subtotal = d.items.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.unit_price) || 0), 0);
    const courier = d.delivery_method === 'courier' ? Number(d.courier_charge) || 0 : 0;
    const total = subtotal - (Number(d.discount) || 0) + courier;

    return (
        <>
            <Head title="New sale" />
            <Page>
                <PageHeader title="New sale" />
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(sales.store.url());
                    }}
                >
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title="Customer">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Phone" error={errors['customer.phone']} className="relative">
                                    <Input
                                        value={d.customer.phone}
                                        autoComplete="off"
                                        onChange={(e) => {
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
                                                    onClick={() => {
                                                        setCustomer({ phone: c.phone, name: c.name, address: c.address ?? '', city: c.city ?? '' });
                                                        form.setData('delivery_address', [c.address, c.city].filter(Boolean).join(', '));
                                                        setMatches([]);
                                                        setQuery('');
                                                    }}
                                                >
                                                    {c.name} · {c.phone} <span className="text-muted-foreground">{c.city}</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </Field>
                                <Field label="Name" error={errors['customer.name']}>
                                    <Input value={d.customer.name} onChange={(e) => { setCustomer({ name: e.target.value }); setQuery(e.target.value); }} />
                                </Field>
                                <Field label="Address"><Input value={d.customer.address} onChange={(e) => setCustomer({ address: e.target.value })} /></Field>
                                <Field label="City"><Input value={d.customer.city} onChange={(e) => setCustomer({ city: e.target.value })} /></Field>
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">Type a phone or name to find a saved customer. A new phone creates a customer when the sale is saved.</p>
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
                        <Table>
                            <thead><tr><th>Product option</th><th className="w-28">Qty</th><th className="w-36">Unit price</th><th className="text-right">Line total</th><th /></tr></thead>
                            <tbody>
                                {d.items.map((l, i) => (
                                    <tr key={i}>
                                        <td>
                                            <NativeSelect
                                                value={l.product_option_id}
                                                aria-invalid={!!errors[`items.${i}.product_option_id`]}
                                                onChange={(e) => setLine(i, { product_option_id: e.target.value, unit_price: priceFor(e.target.value, d.sale_type) })}
                                            >
                                                <option value="">Choose by code or name...</option>
                                                {options.map((o) => <option key={o.id} value={o.id}>{o.code} · {o.name}</option>)}
                                            </NativeSelect>
                                        </td>
                                        <td><NumberInput value={l.qty} onChange={(e) => setLine(i, { qty: e.target.value })} aria-invalid={!!errors[`items.${i}.qty`]} /></td>
                                        <td><NumberInput value={l.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} aria-invalid={!!errors[`items.${i}.unit_price`]} /></td>
                                        <td className="text-right tabular-nums">{rs((Number(l.qty) || 0) * (Number(l.unit_price) || 0))}</td>
                                        <td className="text-right">
                                            {d.items.length > 1 && (
                                                <Button type="button" variant="ghost" size="icon" onClick={() => form.setData('items', d.items.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Button type="button" variant="outline" size="sm" className="mt-3" onClick={() => form.setData('items', [...d.items, newLine()])}>Add item</Button>
                    </Panel>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title="Payment">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Discount" error={errors.discount}><NumberInput value={d.discount} onChange={(e) => form.setData('discount', e.target.value)} /></Field>
                                {d.delivery_method === 'courier' && (
                                    <Field label="Courier charge to customer" error={errors.courier_charge}><NumberInput value={d.courier_charge} onChange={(e) => form.setData('courier_charge', e.target.value)} /></Field>
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
                                    <tr><td>Discount</td><td className="text-right tabular-nums">-{rs(Number(d.discount) || 0)}</td></tr>
                                    {d.delivery_method === 'courier' && <tr><td>Courier</td><td className="text-right tabular-nums">{rs(courier)}</td></tr>}
                                    <tr className="text-lg font-semibold"><td>Total</td><td className="text-right tabular-nums">{rs(total)}</td></tr>
                                </tbody>
                            </Table>
                            {d.payment_method === 'cod' && <p className="mt-2 text-sm text-sky-700 dark:text-sky-300">Cash on delivery: no payment now. Add a COD remittance when the courier pays you.</p>}
                            <Button className="mt-4 w-full" disabled={form.processing}>Save sale</Button>
                        </Panel>
                    </div>
                </form>
            </Page>
        </>
    );
}

SaleCreate.layout = { breadcrumbs: [{ title: 'Sales', href: sales.index() }, { title: 'New', href: sales.create() }] };
