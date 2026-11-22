import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EditButton, EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Table, num, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import * as materials from '@/routes/materials';

export type Material = { id: number; name: string; unit: string; type: 'material' | 'overhead'; stock: number; unit_cost: number; reorder_level: number };

const empty = { name: '', unit: 'g', type: 'material', stock: '', unit_cost: '', reorder_level: '' };

export default function Materials({ materials: rows }: { materials: Material[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Material | null>(null);
    const form = useForm<Record<string, string | number>>(empty);

    const openModal = (m: Material | null) => {
        setEditing(m);
        form.clearErrors();
        form.setData(m ? { name: m.name, unit: m.unit, type: m.type, unit_cost: m.unit_cost, reorder_level: m.reorder_level } : empty);
        setOpen(true);
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(materials.update.url(editing.id), options);
        } else {
            form.post(materials.store.url(), options);
        }
    };

    return (
        <>
            <Head title="Materials" />
            <Page>
                <PageHeader title="Materials" description="Unit cost is the weighted-average landed cost, updated by purchases. Overheads have a fixed cost and no stock.">
                    <Button onClick={() => openModal(null)}><Plus /> New material</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Name</th><th>Type</th><th className="text-right">Stock</th><th className="text-right">Unit cost</th><th className="text-right">Value</th><th className="text-right">Reorder at</th><th /></tr></thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={7}>No materials yet.</EmptyRow>}
                            {rows.map((m) => (
                                <tr key={m.id}>
                                    <td><Link href={materials.show(m.id)} className="font-medium hover:underline">{m.name}</Link></td>
                                    <td className="text-muted-foreground capitalize">{m.type}</td>
                                    <td className={cn('text-right tabular-nums', m.type === 'material' && m.stock <= m.reorder_level && 'font-medium text-brand-coral')}>{m.type === 'material' ? `${num(m.stock, 3)} ${m.unit}` : '-'}</td>
                                    <td className="text-right tabular-nums">{rs(m.unit_cost, 4)} / {m.unit}</td>
                                    <td className="text-right tabular-nums">{m.type === 'material' ? rs(m.stock * m.unit_cost) : '-'}</td>
                                    <td className="text-right tabular-nums">{m.type === 'material' ? num(m.reorder_level) : '-'}</td>
                                    <td className="space-x-3 text-right whitespace-nowrap">
                                        <EditButton onClick={() => openModal(m)} />
                                        <DeleteButton url={materials.destroy.url(m.id)} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title={editing ? `Edit ${editing.name}` : 'New material or overhead'} onSubmit={submit} processing={form.processing}>
                <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Type" error={form.errors.type}>
                        <NativeSelect value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            <option value="material">Material (stocked)</option>
                            <option value="overhead">Overhead (no stock)</option>
                        </NativeSelect>
                    </Field>
                    <Field label="Unit" error={form.errors.unit}><Input value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} placeholder="g, ml, pc, hr" /></Field>
                    <Field label="Unit cost (Rs.)" error={form.errors.unit_cost}><NumberInput value={form.data.unit_cost} onChange={(e) => form.setData('unit_cost', e.target.value)} /></Field>
                    {form.data.type === 'material' && (
                        <Field label="Reorder level" error={form.errors.reorder_level}><NumberInput value={form.data.reorder_level} onChange={(e) => form.setData('reorder_level', e.target.value)} /></Field>
                    )}
                    {!editing && form.data.type === 'material' && (
                        <Field label="Opening stock" error={form.errors.stock}><NumberInput value={form.data.stock} onChange={(e) => form.setData('stock', e.target.value)} /></Field>
                    )}
                </div>
                {editing && <p className="text-xs text-muted-foreground">To change stock, open the material and use Adjust stock, so the change is written to the ledger.</p>}
            </FormModal>
        </>
    );
}

Materials.layout = { breadcrumbs: [{ title: 'Materials', href: materials.index() }] };
