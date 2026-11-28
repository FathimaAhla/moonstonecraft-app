import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { DeleteButton, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, StatusBadge, Table, num, rs, today } from '@/components/ops';
import { AssignSection } from '@/components/paint-dots';
import type { ItemPanel, Pickable } from '@/components/paint-dots';
import * as customers from '@/routes/customers';
import * as salePayments from '@/routes/sale-payments';
import * as sales from '@/routes/sales';

type Content = { kind: string; name: string; qty: number; unit: string };
type Item = { id: number; product_option_id: number | null; code: string; name: string; qty: number; unit_price: number; list_price: number; discount_type: 'amount' | 'percent' | null; discount_value: number; offer_name: string | null; is_custom?: boolean; contents?: { packed_qty: number; unpacked_qty: number; lines: Content[] } | null };
type Payment = { id: number; paid_on: string; amount: number; cod_fee: number; method: string; reference: string | null };
type Sale = {
    id: number; invoice_no: string; sold_on: string; sale_type: string; payment_method: string; delivery_method: string; delivery_address: string | null;
    subtotal: number; discount: number; discount_type: 'amount' | 'percent'; discount_value: number; offer_name: string | null; courier_charge: number; courier_charge_payer: string; total: number; paid_amount: number; balance: number; payment_status: string;
    due_date: string | null; notes: string | null; print_count: number;
    customer: { id: number; name: string; phone: string; address: string | null } | null; items: Item[]; payments: Payment[];
};

function offerLabel(type: 'amount' | 'percent' | null, value: number, name: string | null) {
    const parts = [type === 'percent' ? `${num(value, 2)}% off` : `Rs ${num(value, 2)} off`, name].filter(Boolean);

    return <span className="text-muted-foreground">({parts.join(' · ')})</span>;
}

function openWindow(url: string, small = false) {
    window.open(url, '_blank', small ? 'width=420,height=700' : undefined);
}

export default function SaleShow({ sale, profit, publicLink, assignments, pickable }: { sale: Sale; profit: { cost: number; profit: number }; publicLink: string; assignments: Record<number, ItemPanel>; pickable: Record<number, Pickable> }) {
    const [copied, copy] = useClipboard();
    const [payOpen, setPayOpen] = useState(false);
    const pay = useForm({ paid_on: today(), amount: String(sale.balance), cod_fee: '', method: sale.payment_method === 'cod' ? 'cod_remit' : 'cash', reference: '' });

    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('print') === '1') {
            openWindow(sales.receipt.url(sale.id), true);
        }
    }, [sale.id]);

    return (
        <>
            <Head title={sale.invoice_no} />
            <Page>
                <PageHeader title={sale.invoice_no} description={`${sale.sold_on.slice(0, 10)} · ${sale.sale_type} · ${sale.payment_method.toUpperCase()} · ${sale.delivery_method}`}>
                    <StatusBadge status={sale.payment_status} />
                    <Button variant="outline" onClick={() => openWindow(sales.receipt.url(sale.id), true)}>Print receipt (80mm)</Button>
                    <Button variant="outline" onClick={() => openWindow(sales.pdf.url(sale.id))}>View A4</Button>
                    <Button variant="outline" asChild><a href={sales.download.url(sale.id)}>Download PDF</a></Button>
                    <Button variant="outline" onClick={() => openWindow(sales.invoice.url(sale.id))}>Print A4</Button>
                    <Button variant="outline" onClick={() => copy(publicLink)}>{copied ? 'Link copied' : 'Copy customer link'}</Button>
                </PageHeader>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel title="Items" className="lg:col-span-2">
                        <Table>
                            <thead><tr><th>Code</th><th>Name</th><th className="text-right">Qty</th><th className="text-right">Price</th><th className="text-right">Total</th></tr></thead>
                            <tbody>
                                {sale.items.map((i) => (
                                    <tr key={i.id}>
                                        <td className="font-mono">{i.code}</td>
                                        <td>
                                            {i.name}
                                            {assignments[i.id] && i.product_option_id && pickable[i.product_option_id] && (
                                                <div className="mt-1 grid gap-2 text-xs sm:grid-cols-2">
                                                    {assignments[i.id].figures && (
                                                        <AssignSection itemId={i.id} type="figures" title={`Figures (${assignments[i.id].figures!.per_kit} per kit)`} rows={assignments[i.id].figures!.rows} remaining={assignments[i.id].figures!.remaining} pickable={pickable[i.product_option_id]} perKit={assignments[i.id].figures!.per_kit} />
                                                    )}
                                                    {assignments[i.id].paint && (
                                                        <AssignSection itemId={i.id} type="paint" title={`Paint set (${assignments[i.id].paint!.pots}-pot)`} rows={assignments[i.id].paint!.rows} remaining={assignments[i.id].paint!.remaining} pickable={pickable[i.product_option_id]} perKit={assignments[i.id].paint!.pots} />
                                                    )}
                                                </div>
                                            )}
                                            {i.contents && (
                                                <details className="text-xs text-muted-foreground">
                                                    <summary className="cursor-pointer">Saved contents{i.is_custom ? ' (custom kit)' : ` (${num(i.contents.packed_qty, 0)} packed, ${num(i.contents.unpacked_qty, 0)} made to order)`}</summary>
                                                    <ul className="mt-1 list-disc pl-4 whitespace-normal">{i.contents.lines.map((c, n) => <li key={n}>{num(c.qty, 3)} {c.unit} {c.name}</li>)}</ul>
                                                </details>
                                            )}
                                        </td>
                                        <td className="text-right">{num(i.qty, 3)}</td><td className="text-right tabular-nums">
                                            {i.list_price > i.unit_price && <div className="text-xs text-muted-foreground"><s>{rs(i.list_price)}</s> {offerLabel(i.discount_type, i.discount_value, i.offer_name)}</div>}
                                            {rs(i.unit_price)}
                                        </td><td className="text-right tabular-nums">{rs(i.qty * i.unit_price)}</td>
                                    </tr>
                                ))}
                                <tr><td colSpan={4}>Subtotal</td><td className="text-right tabular-nums">{rs(sale.subtotal)}</td></tr>
                                {sale.discount > 0 && <tr><td colSpan={4}>Discount {offerLabel(sale.discount_type, sale.discount_value, sale.offer_name)}</td><td className="text-right tabular-nums">-{rs(sale.discount)}</td></tr>}
                                {sale.courier_charge > 0 && (
                                    <tr>
                                        <td colSpan={4}>
                                            Courier{sale.courier_charge_payer === 'business' ? ' (we absorb, not billed to customer)' : sale.courier_charge_payer === 'customer_bank_transfer' ? ' (paid by customer, bank transfer)' : ' (paid by customer on delivery)'}
                                        </td>
                                        <td className="text-right tabular-nums">{sale.courier_charge_payer === 'business' ? '-' : rs(sale.courier_charge)}</td>
                                    </tr>
                                )}
                                <tr className="font-semibold"><td colSpan={4}>Total</td><td className="text-right tabular-nums">{rs(sale.total)}</td></tr>
                            </tbody>
                        </Table>
                        <p className="mt-3 text-xs text-muted-foreground">Internal only: cost {rs(profit.cost)}, profit {rs(profit.profit)}. Never printed on customer documents.</p>
                    </Panel>

                    <Panel title="Customer">
                        {sale.customer ? (
                            <div className="space-y-1 text-sm">
                                <Link href={customers.show(sale.customer.id)} className="font-medium hover:underline">{sale.customer.name}</Link>
                                <div>{sale.customer.phone}</div>
                                {sale.delivery_address && <div className="text-muted-foreground">{sale.delivery_address}</div>}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">Walk-in customer</p>
                        )}
                        {sale.due_date && <p className="mt-3 text-sm">Due: {sale.due_date.slice(0, 10)}</p>}
                        {sale.notes && <p className="mt-3 text-sm text-muted-foreground">{sale.notes}</p>}
                        <div className="mt-4">
                            <DeleteButton url={sales.destroy.url(sale.id)} message="Delete this sale? Stock will be restored." label="Delete sale" withText />
                        </div>
                    </Panel>
                </div>

                <Panel
                    title={`Payments · paid ${rs(sale.paid_amount)} · balance ${rs(sale.balance)}`}
                    flush
                    actions={sale.balance > 0 && <Button size="sm" onClick={() => { pay.setData('amount', String(sale.balance)); pay.clearErrors(); setPayOpen(true); }}><Plus /> Add payment</Button>}
                >
                    <Table>
                        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th className="text-right">Amount</th><th className="text-right">COD fee</th><th /></tr></thead>
                        <tbody>
                            {sale.payments.map((p) => (
                                <tr key={p.id}>
                                    <td>{p.paid_on.slice(0, 10)}</td>
                                    <td className="capitalize">{p.method.replace('_', ' ')}</td>
                                    <td>{p.reference ?? '-'}</td>
                                    <td className="text-right tabular-nums">{rs(p.amount)}</td>
                                    <td className="text-right tabular-nums">{p.cod_fee > 0 ? rs(p.cod_fee) : '-'}</td>
                                    <td className="text-right"><DeleteButton url={salePayments.destroy.url(p.id)} label="Remove" /></td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal
                open={payOpen}
                onOpenChange={setPayOpen}
                title="Add payment"
                description={`Balance due ${rs(sale.balance)}`}
                submitLabel="Add payment"
                processing={pay.processing}
                onSubmit={() => pay.post(salePayments.store.url(sale.id), { preserveScroll: true, onSuccess: () => setPayOpen(false) })}
            >
                <div className="grid grid-cols-2 gap-4">
                            <Field label="Date" error={pay.errors.paid_on}><Input type="date" value={pay.data.paid_on} onChange={(e) => pay.setData('paid_on', e.target.value)} /></Field>
                            <Field label="Method" error={pay.errors.method}>
                                <NativeSelect value={pay.data.method} onChange={(e) => pay.setData('method', e.target.value)}>
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank</option>
                                    <option value="cod_remit">COD remittance</option>
                                </NativeSelect>
                            </Field>
                            <Field label="Amount received" error={pay.errors.amount}><NumberInput value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} /></Field>
                            {pay.data.method === 'cod_remit' && (
                                <Field label="COD fee deducted" error={pay.errors.cod_fee}><NumberInput value={pay.data.cod_fee} onChange={(e) => pay.setData('cod_fee', e.target.value)} /></Field>
                            )}
                            <Field label="Reference"><Input value={pay.data.reference} onChange={(e) => pay.setData('reference', e.target.value)} /></Field>
                </div>
            </FormModal>
        </>
    );
}

SaleShow.layout = { breadcrumbs: [{ title: 'Sales', href: sales.index() }] };
