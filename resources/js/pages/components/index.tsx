import { Head, Link, useForm } from '@inertiajs/react';
import { KitGroupSelect } from '@/components/kit-group-select';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Table, num, rs } from '@/components/ops';
import * as components from '@/routes/components';

type Row = { id: number; code: string; name: string; active: boolean; stages_count: number; yield_qty: number; wastage_pct: number; category: string | null; unit_cost: number };

export default function Components({ components: rows, categories }: { components: Row[]; categories: { id: number; name: string }[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ category_id: '', code: 'CMP-', name: '', kit_group: '', yield_qty: '1', wastage_pct: '0', keeps_stock: false, active: true });

    return (
        <>
            <Head title="Components" />
            <Page>
                <PageHeader title="Components" description="Cost estimates for the parts you make: figure, painting set, packing. Each one is built from making stages. Kits use them.">
                    <Button onClick={() => { form.reset(); form.clearErrors(); setOpen(true); }}><Plus /> New component</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Code</th><th>Name</th><th>Category</th><th className="text-right">Stages</th><th className="text-right">Yield per batch</th><th className="text-right">Wastage</th><th className="text-right">Unit cost</th></tr></thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={7}>No components yet. Add your figure, painting set and packing.</EmptyRow>}
                            {rows.map((c) => (
                                <tr key={c.id} className={c.active ? '' : 'opacity-50'}>
                                    <td className="font-mono"><Link href={components.show(c.id)} className="hover:underline">{c.code}</Link></td>
                                    <td><Link href={components.show(c.id)} className="font-medium hover:underline">{c.name}</Link></td>
                                    <td>{c.category ?? '-'}</td>
                                    <td className="text-right tabular-nums">{c.stages_count}</td>
                                    <td className="text-right tabular-nums">{num(c.yield_qty)}</td>
                                    <td className="text-right tabular-nums">{num(c.wastage_pct)}%</td>
                                    <td className="text-right font-medium tabular-nums">{rs(c.unit_cost)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title="New component" description="Next you add its making stages and their costs." submitLabel="Create" processing={form.processing} onSubmit={() => form.post(components.store.url())}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Code" error={form.errors.code}><Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
                    <Field label="Name" error={form.errors.name}><Input value={form.data.name} placeholder="Animal figure, Paint pot, Packing..." onChange={(e) => form.setData('name', e.target.value)} /></Field>
                    <Field label="Category (optional)" error={form.errors.category_id}>
                        <NativeSelect value={form.data.category_id} onChange={(e) => form.setData('category_id', e.target.value)}>
                            <option value="">None (shared)</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </NativeSelect>
                    </Field>
                    <Field label="Kit builder section" error={form.errors.kit_group}><KitGroupSelect value={form.data.kit_group} onChange={(v) => form.setData('kit_group', v)} /></Field>
                    <Field label="Yield per batch" error={form.errors.yield_qty}><NumberInput value={form.data.yield_qty} onChange={(e) => form.setData('yield_qty', e.target.value)} /></Field>
                    <Field label="Planned wastage %" error={form.errors.wastage_pct}><NumberInput value={form.data.wastage_pct} onChange={(e) => form.setData('wastage_pct', e.target.value)} /></Field>
                </div>
                <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.keeps_stock} onCheckedChange={(v) => form.setData('keeps_stock', v === true)} /> Keeps own stock (figures, paint pots you make in advance)</label>
            </FormModal>
        </>
    );
}

Components.layout = { breadcrumbs: [{ title: 'Components', href: components.index() }] };
