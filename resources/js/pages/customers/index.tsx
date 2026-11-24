import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EditButton, EmptyRow, Field, FormModal, NativeSelect, Page, PageHeader, Pagination, Panel, Table, Textarea, rs } from '@/components/ops';
import type { Paginated } from '@/components/ops';
import * as customers from '@/routes/customers';

type Row = { id: number; name: string; phone: string; address: string | null; city: string | null; type: string; notes: string | null; sales_count: number; balance: number | null };

const blank = { name: '', phone: '', address: '', city: '', type: 'retail', notes: '' };

export default function Customers({ customers: page, filters }: { customers: Paginated<Row>; filters: { q: string } }) {
    const [q, setQ] = useState(filters.q);
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Row | null>(null);
    const form = useForm(blank);

    const openModal = (customer: Row | null) => {
        setEditing(customer);
        form.clearErrors();
        form.setData(customer ? { name: customer.name, phone: customer.phone, address: customer.address ?? '', city: customer.city ?? '', type: customer.type, notes: customer.notes ?? '' } : blank);
        setOpen(true);
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(customers.update.url(editing.id), options);
        } else {
            form.post(customers.store.url(), options);
        }
    };

    return (
        <>
            <Head title="Customers" />
            <Page>
                <PageHeader title="Customers">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(customers.index.url(), q ? { q } : {}, { preserveState: true });
                        }}
                    >
                        <Input className="w-64" placeholder="Search name or phone" value={q} onChange={(e) => setQ(e.target.value)} />
                    </form>
                    <Button onClick={() => openModal(null)}><Plus /> New customer</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Name</th><th>Phone</th><th>City</th><th>Type</th><th className="text-right">Sales</th><th className="text-right">Balance</th><th /></tr></thead>
                        <tbody>
                            {page.data.length === 0 && <EmptyRow colSpan={7}>No customers found.</EmptyRow>}
                            {page.data.map((c) => (
                                <tr key={c.id}>
                                    <td><Link href={customers.show(c.id)} className="hover:underline">{c.name}</Link></td>
                                    <td>{c.phone}</td>
                                    <td>{c.city ?? '-'}</td>
                                    <td className="capitalize">{c.type}</td>
                                    <td className="text-right">{c.sales_count}</td>
                                    <td className="text-right tabular-nums">{Number(c.balance) > 0 ? rs(c.balance) : '-'}</td>
                                    <td className="space-x-3 text-right whitespace-nowrap">
                                        <EditButton onClick={() => openModal(c)} />
                                        <DeleteButton url={customers.destroy.url(c.id)} message={`Delete ${c.name}? Their past sales are kept.`} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
                <Pagination links={page.links} />
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title={editing ? `Edit ${editing.name}` : 'New customer'} processing={form.processing} onSubmit={submit}>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
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

Customers.layout = { breadcrumbs: [{ title: 'Customers', href: customers.index() }] };
