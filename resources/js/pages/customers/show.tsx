import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyRow, Field, FormModal, NativeSelect, Page, PageHeader, Panel, Stat, StatusBadge, Table, Textarea, rs } from '@/components/ops';
import * as customers from '@/routes/customers';
import * as sales from '@/routes/sales';

type Customer = { id: number; name: string; phone: string; address: string | null; city: string | null; type: string; notes: string | null };
type SaleRow = { id: number; invoice_no: string; sold_on: string; total: number; balance: number; payment_status: string };

export default function CustomerShow({ customer, sales: rows }: { customer: Customer; sales: SaleRow[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: customer.name, phone: customer.phone, address: customer.address ?? '', city: customer.city ?? '', type: customer.type, notes: customer.notes ?? '' });
    const balance = rows.reduce((s, r) => s + Number(r.balance), 0);
    const spent = rows.reduce((s, r) => s + Number(r.total), 0);

    return (
        <>
            <Head title={customer.name} />
            <Page>
                <PageHeader title={customer.name} description={[customer.phone, customer.address, customer.city].filter(Boolean).join(' · ')}>
                    <Button variant="outline" onClick={() => { form.clearErrors(); setOpen(true); }}><Pencil /> Edit</Button>
                </PageHeader>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat label="Sales" value={String(rows.length)} hint={customer.type} />
                    <Stat label="Total bought" value={rs(spent)} />
                    <Stat label="Outstanding" value={rs(balance)} tone={balance > 0 ? 'bad' : undefined} />
                </div>
                {customer.notes && <p className="text-sm text-muted-foreground">{customer.notes}</p>}
                <Panel title="Sales history" flush>
                    <Table>
                        <thead><tr><th>Invoice</th><th>Date</th><th className="text-right">Total</th><th className="text-right">Balance</th><th>Status</th></tr></thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={5}>No sales yet.</EmptyRow>}
                            {rows.map((s) => (
                                <tr key={s.id}>
                                    <td><Link href={sales.show(s.id)} className="font-mono hover:underline">{s.invoice_no}</Link></td>
                                    <td>{s.sold_on.slice(0, 10)}</td>
                                    <td className="text-right tabular-nums">{rs(s.total)}</td>
                                    <td className="text-right tabular-nums">{s.balance > 0 ? rs(s.balance) : '-'}</td>
                                    <td><StatusBadge status={s.payment_status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title="Edit customer" processing={form.processing} onSubmit={() => form.put(customers.update.url(customer.id), { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                    <Field label="Phone" error={form.errors.phone}><Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></Field>
                    <Field label="Address"><Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} /></Field>
                    <Field label="City"><Input value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} /></Field>
                    <Field label="Type">
                        <NativeSelect value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            <option value="retail">Retail</option>
                            <option value="wholesale">Wholesale</option>
                        </NativeSelect>
                    </Field>
                </div>
                <Field label="Notes"><Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
            </FormModal>
        </>
    );
}

CustomerShow.layout = { breadcrumbs: [{ title: 'Customers', href: customers.index() }] };
