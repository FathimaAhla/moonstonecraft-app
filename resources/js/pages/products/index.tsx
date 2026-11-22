import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyRow, Field, FormModal, NativeSelect, Page, PageHeader, Panel, Table } from '@/components/ops';
import * as products from '@/routes/products';

type Row = { id: number; code: string; name: string; active: boolean; options_count: number; category: string };
type Category = { id: number; name: string; code_prefix: string };

export default function Products({ products: rows, categories, filters }: { products: Row[]; categories: Category[]; filters: { category: number | null } }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ category_id: String(filters.category ?? categories[0]?.id ?? ''), code: '', name: '', active: true });
    const prefix = categories.find((c) => String(c.id) === form.data.category_id)?.code_prefix ?? '';

    return (
        <>
            <Head title="Kits" />
            <Page>
                <PageHeader title="Kits" description="Your figure sets that you sell. Each kit has options (1 figure 2 paints...) built from your components.">
                    <NativeSelect className="w-52" value={filters.category ?? ''} onChange={(e) => router.get(products.index.url(), e.target.value ? { category: e.target.value } : {})}>
                        <option value="">All categories</option>
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </NativeSelect>
                    <Button onClick={() => { form.clearErrors(); setOpen(true); }}><Plus /> New kit</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Code</th><th>Name</th><th>Category</th><th className="text-right">Options</th></tr></thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={4}>No kits yet.</EmptyRow>}
                            {rows.map((p) => (
                                <tr key={p.id} className={p.active ? '' : 'opacity-50'}>
                                    <td className="font-mono"><Link href={products.show(p.id)} className="hover:underline">{p.code}</Link></td>
                                    <td><Link href={products.show(p.id)} className="font-medium hover:underline">{p.name}</Link></td>
                                    <td>{p.category}</td>
                                    <td className="text-right">{p.options_count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title="New kit" submitLabel="Create" processing={form.processing} onSubmit={() => form.post(products.store.url())}>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Category" error={form.errors.category_id}>
                        <NativeSelect value={form.data.category_id} onChange={(e) => form.setData('category_id', e.target.value)}>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </NativeSelect>
                    </Field>
                    <Field label="Code" error={form.errors.code}><Input value={form.data.code} placeholder={`${prefix}-XXX-001`} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
                    <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                </div>
            </FormModal>
        </>
    );
}

Products.layout = { breadcrumbs: [{ title: 'Kits', href: products.index() }] };
