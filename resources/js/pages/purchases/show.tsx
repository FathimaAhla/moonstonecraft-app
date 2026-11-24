import { Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { DeleteButton, Page, PageHeader, Panel, Table, num, rs } from '@/components/ops';
import * as purchases from '@/routes/purchases';

type Item = { id: number; qty: number; purchase_unit: string | null; conversion_factor: number; converted_qty: number | null; line_cost: number; extra_allocated: number; landed_unit_cost: number; material: { name: string; unit: string } };
type Purchase = {
    id: number; purchase_no: string; purchased_on: string; supplier: { name: string } | null; items_total: number; transport_cost: number;
    refreshment_cost: number; other_cost: number; grand_total: number; refreshment_as_stock_cost: boolean; notes: string | null; receipt_path: string | null; items: Item[];
};

export default function PurchaseShow({ purchase: p }: { purchase: Purchase }) {
    return (
        <>
            <Head title={p.purchase_no} />
            <Page>
                <PageHeader title={p.purchase_no} description={`${p.purchased_on.slice(0, 10)} · ${p.supplier?.name ?? 'No supplier'}`}>
                    {p.receipt_path && <Button variant="outline" asChild><a href={purchases.receipt.url(p.id)} target="_blank" rel="noreferrer">View receipt</a></Button>}
                    <DeleteButton url={purchases.destroy.url(p.id)} message="Delete this purchase? Tracked stock is reversed. Weighted-average costs of tracked items are not rolled back." />
                </PageHeader>
                <Panel title="Allocation">
                    <Table>
                        <thead><tr><th>Material</th><th className="text-right">Bought</th><th className="text-right">Recipe qty</th><th className="text-right">Line cost</th><th className="text-right">Share</th><th className="text-right">Extra</th><th className="text-right">Landed cost</th><th className="text-right">Landed unit</th></tr></thead>
                        <tbody>
                            {p.items.map((i) => (
                                <tr key={i.id}>
                                    <td>{i.material.name}</td>
                                    <td className="text-right tabular-nums">{i.purchase_unit ? `${num(i.qty, 3)} ${i.purchase_unit}` : `${num(i.qty, 3)} ${i.material.unit}`}</td>
                                    <td className="text-right tabular-nums">{num(i.converted_qty ?? i.qty, 3)} {i.material.unit}{i.purchase_unit && i.conversion_factor !== 1 ? ` (×${num(i.conversion_factor, 4)})` : ''}</td>
                                    <td className="text-right tabular-nums">{rs(i.line_cost)}</td>
                                    <td className="text-right tabular-nums">{p.items_total ? ((i.line_cost / p.items_total) * 100).toFixed(2) : 0}%</td>
                                    <td className="text-right tabular-nums">{rs(i.extra_allocated)}</td>
                                    <td className="text-right tabular-nums">{rs(i.line_cost + i.extra_allocated)}</td>
                                    <td className="text-right tabular-nums">{rs(i.landed_unit_cost, 4)} / {i.material.unit}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
                <Panel title="Totals" className="max-w-md">
                    <Table>
                        <tbody>
                            <tr><td>Items</td><td className="text-right tabular-nums">{rs(p.items_total)}</td></tr>
                            <tr><td>Transport</td><td className="text-right tabular-nums">{rs(p.transport_cost)}</td></tr>
                            <tr><td>Refreshment {p.refreshment_as_stock_cost ? '(in stock cost)' : '(expense)'}</td><td className="text-right tabular-nums">{rs(p.refreshment_cost)}</td></tr>
                            <tr><td>Other</td><td className="text-right tabular-nums">{rs(p.other_cost)}</td></tr>
                            <tr className="font-semibold"><td>Grand total</td><td className="text-right tabular-nums">{rs(p.grand_total)}</td></tr>
                        </tbody>
                    </Table>
                    {p.notes && <p className="mt-3 text-sm text-muted-foreground">{p.notes}</p>}
                </Panel>
            </Page>
        </>
    );
}

PurchaseShow.layout = { breadcrumbs: [{ title: 'Purchases', href: purchases.index() }] };
