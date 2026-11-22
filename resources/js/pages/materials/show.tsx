import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { EmptyRow, Field, FormModal, NumberInput, Page, PageHeader, Panel, Stat, Table, num, rs } from '@/components/ops';
import * as materials from '@/routes/materials';
import type { Material } from './index';

type Movement = { id: number; moved_on: string; qty: number; reason: string; ref_type: string | null; ref_id: number | null; unit_cost: number };

export default function MaterialShow({ material, movements }: { material: Material; movements: Movement[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ qty: '' });

    return (
        <>
            <Head title={material.name} />
            <Page>
                <PageHeader title={material.name} description={material.type === 'overhead' ? 'Overhead: fixed cost, no stock' : 'Stocked material'}>
                    {material.type === 'material' && <Button onClick={() => { form.reset(); setOpen(true); }}>Adjust stock</Button>}
                </PageHeader>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat label="In stock" value={`${num(material.stock, 3)} ${material.unit}`} tone={material.type === 'material' && material.stock <= material.reorder_level ? 'bad' : undefined} />
                    <Stat label="Unit cost" value={`${rs(material.unit_cost, 4)} / ${material.unit}`} />
                    <Stat label="Stock value" value={rs(material.stock * material.unit_cost)} />
                </div>
                <Panel title="Stock ledger (latest 200)" flush>
                    <Table>
                        <thead><tr><th>Date</th><th>Reason</th><th>Reference</th><th className="text-right">Qty</th><th className="text-right">Unit cost</th></tr></thead>
                        <tbody>
                            {movements.length === 0 && <EmptyRow colSpan={5}>No stock movements yet.</EmptyRow>}
                            {movements.map((m) => (
                                <tr key={m.id}>
                                    <td>{m.moved_on.slice(0, 10)}</td>
                                    <td className="capitalize">{m.reason}</td>
                                    <td className="text-muted-foreground">{m.ref_type ? `${m.ref_type.split('\\').pop()} #${m.ref_id}` : '-'}</td>
                                    <td className={`text-right font-medium tabular-nums ${m.qty < 0 ? 'text-brand-coral' : 'text-brand-leaf'}`}>{m.qty > 0 ? '+' : ''}{num(m.qty, 3)}</td>
                                    <td className="text-right tabular-nums">{rs(m.unit_cost, 4)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal
                open={open}
                onOpenChange={setOpen}
                title="Adjust stock"
                description="Use for stock counts and corrections. Positive adds, negative removes."
                submitLabel="Adjust"
                processing={form.processing}
                onSubmit={() => form.post(materials.adjust.url(material.id), { preserveScroll: true, onSuccess: () => setOpen(false) })}
            >
                <Field label={`Quantity in ${material.unit}`} error={form.errors.qty}><NumberInput value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} autoFocus /></Field>
            </FormModal>
        </>
    );
}

MaterialShow.layout = { breadcrumbs: [{ title: 'Materials', href: materials.index() }] };
