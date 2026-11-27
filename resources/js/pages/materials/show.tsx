import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ContainerPanel } from '@/components/container-panel';
import type { Container, Measured } from '@/components/container-panel';
import { PackSizePanel } from '@/components/pack-size-panel';
import type { PricedPackSize } from '@/components/pack-size-panel';
import { Button } from '@/components/ui/button';
import { EmptyRow, Field, FormModal, NumberInput, Page, PageHeader, Panel, Stat, Table, num, rs } from '@/components/ops';
import * as materials from '@/routes/materials';
import * as purchasesRoutes from '@/routes/purchases';
import { CostSourceBadge, behaviourLabel } from './index';
import type { Material } from './index';

type Movement = { id: number; moved_on: string; qty: number; reason: string; ref_type: string | null; ref_id: number | null; unit_cost: number };
type PurchaseLine = { id: number; qty: number; purchase_unit: string | null; conversion_factor: number; converted_qty: number | null; landed_unit_cost: number; purchase: { id: number; purchase_no: string; purchased_on: string } };

export default function MaterialShow({ material, movements, purchases, packSizes, containers, measured }: { material: Material; movements: Movement[]; purchases: PurchaseLine[]; packSizes: PricedPackSize[]; containers: Container[]; measured: Measured | null }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ qty: '' });
    const cost = useForm({ cost_source: material.cost_source as string, manual_unit_cost: material.manual_unit_cost === null ? '' : String(material.manual_unit_cost) });
    const manual = material.cost_source === 'manual';

    return (
        <>
            <Head title={material.name} />
            <Page>
                <PageHeader title={material.name} description={behaviourLabel(material)}>
                    {material.tracks_stock && <Button onClick={() => { form.reset(); setOpen(true); }}>Adjust stock</Button>}
                </PageHeader>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="In stock" value={material.tracks_stock ? `${num(material.stock, 3)} ${material.unit}` : 'Not tracked'} tone={material.tracks_stock && material.stock <= material.reorder_level ? 'bad' : undefined} />
                    <Stat label="Active unit cost" value={`${rs(material.unit_cost, 4)} / ${material.unit}`} hint={manual ? 'Manual cost is active' : 'Purchase-derived cost is active'} />
                    <Stat label="Purchase-derived cost" value={material.is_purchasable ? `${rs(material.purchase_unit_cost, 4)} / ${material.unit}` : 'n/a'} hint={material.tracks_stock ? 'Weighted average landed cost' : 'Latest landed cost'} />
                    <Stat label="Manual cost" value={material.manual_unit_cost === null ? 'Not set' : `${rs(material.manual_unit_cost, 4)} / ${material.unit}`} />
                </div>

                <Panel title="Cost source" actions={<CostSourceBadge material={material} />}>
                    <form
                        className="grid items-end gap-3 sm:grid-cols-[1fr_auto_auto]"
                        onSubmit={(e) => {
                            e.preventDefault();
                            cost.transform((d) => ({ ...d, cost_source: 'manual' }));
                            cost.patch(materials.cost.url(material.id), { preserveScroll: true });
                        }}
                    >
                        <Field label={`Manual unit cost (Rs. per ${material.unit})`} error={cost.errors.manual_unit_cost}>
                            <NumberInput value={cost.data.manual_unit_cost} onChange={(e) => cost.setData('manual_unit_cost', e.target.value)} />
                        </Field>
                        <Button disabled={cost.processing}>{manual ? 'Update manual cost' : 'Use manual cost'}</Button>
                        {material.is_purchasable && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={cost.processing || !manual}
                                onClick={() => {
                                    cost.transform((d) => ({ ...d, cost_source: 'purchase', manual_unit_cost: '' }));
                                    cost.patch(materials.cost.url(material.id), { preserveScroll: true });
                                }}
                            >
                                Use latest purchase cost
                            </Button>
                        )}
                    </form>
                    <p className="mt-3 text-sm text-muted-foreground">
                        {manual ? 'A manual cost stays active across later purchases. Purchases keep updating the purchase-derived cost separately.' : material.is_purchasable ? 'Purchases update the cost automatically. Enter a manual cost to override it without losing the purchase-derived cost.' : 'This item is cost-only: it is never purchased, so its cost is always manual.'}
                    </p>
                    {cost.errors.cost_source && <p className="mt-2 text-sm text-red-600">{cost.errors.cost_source}</p>}
                </Panel>

                {material.is_purchasable && <PackSizePanel materialId={material.id} unit={material.unit} packSizes={packSizes} />}

                {material.track_by_container && <ContainerPanel material={material} packSizes={packSizes} containers={containers} measured={measured} />}

                {material.is_purchasable && (
                    <Panel title="Recent purchases" flush>
                        <Table>
                            <thead><tr><th>Purchase</th><th>Date</th><th className="text-right">Bought</th><th className="text-right">Recipe qty</th><th className="text-right">Landed unit cost</th></tr></thead>
                            <tbody>
                                {purchases.length === 0 && <EmptyRow colSpan={5}>No purchases yet.</EmptyRow>}
                                {purchases.map((p) => (
                                    <tr key={p.id}>
                                        <td><Link href={purchasesRoutes.show(p.purchase.id)} className="font-mono hover:underline">{p.purchase.purchase_no}</Link></td>
                                        <td>{p.purchase.purchased_on.slice(0, 10)}</td>
                                        <td className="text-right tabular-nums">{num(p.qty, 3)} {p.purchase_unit ?? material.unit}</td>
                                        <td className="text-right tabular-nums">{num(p.converted_qty ?? p.qty, 3)} {material.unit}</td>
                                        <td className="text-right tabular-nums">{rs(p.landed_unit_cost, 4)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Panel>
                )}

                {material.tracks_stock && (
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
                )}
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
