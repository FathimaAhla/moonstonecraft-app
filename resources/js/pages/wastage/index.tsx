import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, num, rs, today } from '@/components/ops';
import * as wastage from '@/routes/wastage';

type Log = {
    id: number; logged_on: string; scope: string; qty: number | null; cost: number; reason: string | null; notes: string | null;
    material: { name: string; unit: string } | null; component: { code: string; name: string } | null;
};

export default function Wastage({ logs, month, totals, materials, components }: {
    logs: Log[];
    month: string;
    totals: { material: number; product: number; business: number };
    materials: { id: number; name: string; unit: string }[];
    components: { id: number; code: string; name: string }[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ scope: 'material', logged_on: today(), material_id: '', component_id: '', qty: '', amount: '', reason: '', notes: '' });
    const d = form.data;

    return (
        <>
            <Head title="Wastage" />
            <Page>
                <PageHeader title="Wastage" description="Unplanned losses. They never change kit costs; they reduce profit on the dashboard.">
                    <Input type="month" className="w-44" value={month} onChange={(e) => e.target.value && router.get(wastage.index.url(), { month: e.target.value })} />
                    <Button onClick={() => { form.reset('qty', 'amount', 'reason', 'notes'); form.clearErrors(); setOpen(true); }}><Plus /> Log wastage</Button>
                </PageHeader>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat label="Material wastage" value={rs(totals.material)} />
                    <Stat label="Component wastage" value={rs(totals.product)} />
                    <Stat label="Business wastage" value={rs(totals.business)} tone={totals.business > 0 ? 'bad' : undefined} />
                </div>

                <Panel title="Entries this month" flush>
                    <Table>
                        <thead><tr><th>Date</th><th>Type</th><th>Item</th><th className="text-right">Qty</th><th className="text-right">Cost</th><th>Reason</th><th /></tr></thead>
                        <tbody>
                            {logs.length === 0 && <EmptyRow colSpan={7}>No wastage this month.</EmptyRow>}
                            {logs.map((l) => (
                                <tr key={l.id}>
                                    <td>{l.logged_on.slice(0, 10)}</td>
                                    <td className="capitalize">{l.scope}</td>
                                    <td>{l.material?.name ?? (l.component ? `${l.component.code} ${l.component.name}` : '-')}</td>
                                    <td className="text-right">{l.qty === null ? '-' : `${num(l.qty, 3)} ${l.material?.unit ?? ''}`}</td>
                                    <td className="text-right tabular-nums">{rs(l.cost)}</td>
                                    <td>{l.reason ?? '-'}</td>
                                    <td className="text-right"><DeleteButton url={wastage.destroy.url(l.id)} message="Remove this entry? Any stock it removed will be put back." /></td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title="Log wastage" submitLabel="Log" processing={form.processing} onSubmit={() => form.post(wastage.store.url(), { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Type">
                        <NativeSelect value={d.scope} onChange={(e) => form.setData('scope', e.target.value)}>
                            <option value="material">Material (spill, expiry)</option>
                            <option value="product">Component (broken figure, set...)</option>
                            <option value="business">Business (theft, damage)</option>
                        </NativeSelect>
                    </Field>
                    <Field label="Date" error={form.errors.logged_on}><Input type="date" value={d.logged_on} onChange={(e) => form.setData('logged_on', e.target.value)} /></Field>
                </div>
                {d.scope === 'material' && (
                    <Field label="Material" error={form.errors.material_id}>
                        <NativeSelect value={d.material_id} onChange={(e) => form.setData('material_id', e.target.value)}>
                            <option value="">Choose...</option>
                            {materials.map((m) => <option key={m.id} value={m.id}>{m.name} ({m.unit})</option>)}
                        </NativeSelect>
                    </Field>
                )}
                {d.scope === 'product' && (
                    <Field label="Component" error={form.errors.component_id}>
                        <NativeSelect value={d.component_id} onChange={(e) => form.setData('component_id', e.target.value)}>
                            <option value="">Choose...</option>
                            {components.map((p) => <option key={p.id} value={p.id}>{p.code} {p.name}</option>)}
                        </NativeSelect>
                    </Field>
                )}
                {d.scope === 'business' ? (
                    <Field label="Amount lost (Rs.)" error={form.errors.amount}><NumberInput value={d.amount} onChange={(e) => form.setData('amount', e.target.value)} /></Field>
                ) : (
                    <Field label="Quantity" error={form.errors.qty}><NumberInput value={d.qty} onChange={(e) => form.setData('qty', e.target.value)} /></Field>
                )}
                <Field label="Reason"><Input value={d.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                <p className="text-xs text-muted-foreground">
                    {d.scope === 'business' ? 'Business wastage changes no stock.' : d.scope === 'product' ? 'Takes the raw materials this component uses out of stock.' : 'Takes the quantity out of stock at current cost.'}
                </p>
            </FormModal>
        </>
    );
}

Wastage.layout = { breadcrumbs: [{ title: 'Wastage', href: wastage.index() }] };
