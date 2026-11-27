import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EditButton, EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Table } from '@/components/ops';
import * as categories from '@/routes/categories';
import * as products from '@/routes/products';

type Category = { id: number; name: string; code_prefix: string; kit_picks: string; sort: number; products_count: number };

export default function Categories({ categories: rows, pickOptions }: { categories: Category[]; pickOptions: Record<string, string> }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Category | null>(null);
    const form = useForm({ name: '', code_prefix: '', kit_picks: 'none', sort: '0' });

    const openModal = (category: Category | null) => {
        setEditing(category);
        form.clearErrors();
        form.setData(category ? { name: category.name, code_prefix: category.code_prefix, kit_picks: category.kit_picks, sort: String(category.sort) } : { name: '', code_prefix: '', kit_picks: 'none', sort: String(rows.length) });
        setOpen(true);
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(categories.update.url(editing.id), options);
        } else {
            form.post(categories.store.url(), options);
        }
    };

    return (
        <>
            <Head title="Categories" />
            <Page>
                <PageHeader title="Categories" description="Your crafts. The prefix starts every product code in the category.">
                    <Button onClick={() => openModal(null)}><Plus /> New category</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Name</th><th>Prefix</th><th>Picked per kit</th><th className="text-right">Products</th><th /></tr></thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={5}>No categories yet.</EmptyRow>}
                            {rows.map((c) => (
                                <tr key={c.id}>
                                    <td><Link href={products.index({ query: { category: c.id } })} className="font-medium hover:underline">{c.name}</Link></td>
                                    <td className="font-mono">{c.code_prefix}</td>
                                    <td className="text-muted-foreground">{pickOptions[c.kit_picks] ?? c.kit_picks}</td>
                                    <td className="text-right">{c.products_count}</td>
                                    <td className="space-x-3 text-right whitespace-nowrap">
                                        <EditButton onClick={() => openModal(c)} />
                                        <DeleteButton url={categories.destroy.url(c.id)} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title={editing ? `Edit ${editing.name}` : 'New category'} onSubmit={submit} processing={form.processing}>
                <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Code prefix" error={form.errors.code_prefix}><Input value={form.data.code_prefix} placeholder="PNT" onChange={(e) => form.setData('code_prefix', e.target.value.toUpperCase())} /></Field>
                    <Field label="Sort order" error={form.errors.sort}><NumberInput value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)} /></Field>
                </div>
                <Field label="Picked per kit" error={form.errors.kit_picks}>
                    <NativeSelect value={form.data.kit_picks} onChange={(e) => form.setData('kit_picks', e.target.value)}>
                        {Object.entries(pickOptions).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </NativeSelect>
                </Field>
            </FormModal>
        </>
    );
}

Categories.layout = { breadcrumbs: [{ title: 'Categories', href: categories.index() }] };
