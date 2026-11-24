import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EditButton, EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Table, num, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import * as materials from '@/routes/materials';

export type Material = {
    id: number;
    name: string;
    unit: string;
    purchase_unit: string | null;
    purchase_conversion: number;
    is_purchasable: boolean;
    tracks_stock: boolean;
    stock: number;
    unit_cost: number;
    purchase_unit_cost: number;
    manual_unit_cost: number | null;
    cost_source: 'purchase' | 'manual';
    reorder_level: number;
};

export function CostSourceBadge({ material }: { material: Pick<Material, 'cost_source'> }) {
    return (
        <Badge variant="outline" className={cn('border-transparent', material.cost_source === 'manual' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300')}>
            {material.cost_source === 'manual' ? 'Manual cost' : 'Purchase cost'}
        </Badge>
    );
}

export function behaviourLabel(m: Pick<Material, 'is_purchasable' | 'tracks_stock'>): string {
    if (!m.is_purchasable) {
        return 'Cost only';
    }

    return m.tracks_stock ? 'Purchased, stock tracked' : 'Purchased, stock manual';
}

type FormData = {
    name: string;
    unit: string;
    is_purchasable: boolean;
    tracks_stock: boolean;
    purchase_unit: string;
    purchase_conversion: string;
    cost_source: string;
    manual_unit_cost: string;
    opening_unit_cost: string;
    reorder_level: string;
    stock: string;
};

export const emptyMaterial: FormData = {
    name: '', unit: 'g', is_purchasable: true, tracks_stock: true, purchase_unit: '', purchase_conversion: '1',
    cost_source: 'purchase', manual_unit_cost: '', opening_unit_cost: '', reorder_level: '', stock: '',
};

/**
 * Create / edit fields for a material. Shared with the recipe screen's "Add material".
 */
export function MaterialFields({ data, setData, errors, editing }: { data: FormData; setData: <K extends keyof FormData>(key: K, value: FormData[K]) => void; errors: Partial<Record<string, string>>; editing: boolean }) {
    const behaviour = !data.is_purchasable ? 'cost' : data.tracks_stock ? 'tracked' : 'manual';

    const setBehaviour = (value: string) => {
        setData('is_purchasable', value !== 'cost');
        setData('tracks_stock', value === 'tracked');

        if (value === 'cost') {
            setData('cost_source', 'manual');
        }
    };

    return (
        <>
            <Field label="Name" error={errors.name}><Input value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus /></Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Behaviour" error={errors.tracks_stock ?? errors.is_purchasable} className="sm:col-span-2">
                    <NativeSelect value={behaviour} onChange={(e) => setBehaviour(e.target.value)}>
                        <option value="tracked">Purchasable, stock tracked and deducted automatically</option>
                        <option value="manual">Purchasable, stock managed by hand (no automatic deduction)</option>
                        <option value="cost">Cost only: not purchased, no stock (labour, electricity...)</option>
                    </NativeSelect>
                </Field>
                <Field label="Unit used in recipes and stock" error={errors.unit}><Input value={data.unit} onChange={(e) => setData('unit', e.target.value)} placeholder="g, ml, pc, hr" /></Field>
                {data.is_purchasable && (
                    <Field label="Cost source" error={errors.cost_source}>
                        <NativeSelect value={data.cost_source} onChange={(e) => setData('cost_source', e.target.value)}>
                            <option value="purchase">Latest purchase-derived cost</option>
                            <option value="manual">Manual cost</option>
                        </NativeSelect>
                    </Field>
                )}
                {data.is_purchasable && (
                    <>
                        <Field label="Purchase unit (optional)" error={errors.purchase_unit}><Input value={data.purchase_unit} onChange={(e) => setData('purchase_unit', e.target.value)} placeholder="pack, box, kg" /></Field>
                        {data.purchase_unit && (
                            <Field label={`${data.unit || 'Units'} in one ${data.purchase_unit}`} error={errors.purchase_conversion}><NumberInput value={data.purchase_conversion} onChange={(e) => setData('purchase_conversion', e.target.value)} /></Field>
                        )}
                    </>
                )}
                {(data.cost_source === 'manual' || !data.is_purchasable) && (
                    <Field label={`Manual unit cost (Rs. per ${data.unit || 'unit'})`} error={errors.manual_unit_cost}><NumberInput value={data.manual_unit_cost} onChange={(e) => setData('manual_unit_cost', e.target.value)} /></Field>
                )}
                {!editing && data.is_purchasable && data.cost_source === 'purchase' && (
                    <Field label={`Starting unit cost (Rs. per ${data.unit || 'unit'})`} error={errors.opening_unit_cost}><NumberInput value={data.opening_unit_cost} onChange={(e) => setData('opening_unit_cost', e.target.value)} /></Field>
                )}
                {data.tracks_stock && <Field label="Reorder level" error={errors.reorder_level}><NumberInput value={data.reorder_level} onChange={(e) => setData('reorder_level', e.target.value)} /></Field>}
                {!editing && data.tracks_stock && <Field label="Opening stock" error={errors.stock}><NumberInput value={data.stock} onChange={(e) => setData('stock', e.target.value)} /></Field>}
            </div>
        </>
    );
}

export default function Materials({ materials: rows }: { materials: Material[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Material | null>(null);
    const form = useForm<FormData>(emptyMaterial);

    const openModal = (m: Material | null) => {
        setEditing(m);
        form.clearErrors();
        form.setData(
            m
                ? {
                      ...emptyMaterial,
                      name: m.name, unit: m.unit, is_purchasable: m.is_purchasable, tracks_stock: m.tracks_stock,
                      purchase_unit: m.purchase_unit ?? '', purchase_conversion: String(m.purchase_conversion),
                      cost_source: m.cost_source, manual_unit_cost: m.manual_unit_cost === null ? '' : String(m.manual_unit_cost), reorder_level: String(m.reorder_level),
                  }
                : emptyMaterial,
        );
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
                <PageHeader title="Materials and costs" description="Each item is purchasable or cost-only, stock-tracked or managed by hand, and costed from purchases or a manual price. The active cost source is shown on every row.">
                    <Button onClick={() => openModal(null)}><Plus /> New item</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Name</th><th>Behaviour</th><th className="text-right">Stock</th><th>Cost source</th><th className="text-right">Active unit cost</th><th className="text-right">Value</th><th /></tr></thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={7}>No materials yet.</EmptyRow>}
                            {rows.map((m) => (
                                <tr key={m.id}>
                                    <td><Link href={materials.show(m.id)} className="font-medium hover:underline">{m.name}</Link></td>
                                    <td className="text-muted-foreground">{behaviourLabel(m)}</td>
                                    <td className={cn('text-right tabular-nums', m.tracks_stock && m.stock <= m.reorder_level && 'font-medium text-brand-coral')}>{m.tracks_stock ? `${num(m.stock, 3)} ${m.unit}` : '-'}</td>
                                    <td><CostSourceBadge material={m} /></td>
                                    <td className="text-right tabular-nums">{rs(m.unit_cost, 4)} / {m.unit}</td>
                                    <td className="text-right tabular-nums">{m.tracks_stock ? rs(m.stock * m.unit_cost) : '-'}</td>
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

            <FormModal open={open} onOpenChange={setOpen} title={editing ? `Edit ${editing.name}` : 'New material or cost item'} onSubmit={submit} processing={form.processing}>
                <MaterialFields data={form.data} setData={form.setData} errors={form.errors} editing={!!editing} />
                {editing && <p className="text-xs text-muted-foreground">To change stock, open the item and use Adjust stock, so the change is written to the ledger.</p>}
            </FormModal>
        </>
    );
}

Materials.layout = { breadcrumbs: [{ title: 'Materials', href: materials.index() }] };
