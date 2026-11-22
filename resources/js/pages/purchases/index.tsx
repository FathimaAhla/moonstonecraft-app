import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Page, PageHeader, Pagination, Panel, Table, rs } from '@/components/ops';
import type { Paginated } from '@/components/ops';
import * as purchases from '@/routes/purchases';

type Row = { id: number; purchase_no: string; purchased_on: string; supplier: { name: string } | null; items_count: number; items_total: number; transport_cost: number; refreshment_cost: number; other_cost: number; grand_total: number };

export default function Purchases({ purchases: page }: { purchases: Paginated<Row> }) {
    return (
        <>
            <Head title="Purchases" />
            <Page>
                <PageHeader title="Purchases" description="Every shopping trip or supplier invoice.">
                    <Button asChild><Link href={purchases.create()}>New purchase</Link></Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>No.</th><th>Date</th><th>Supplier</th><th className="text-right">Lines</th><th className="text-right">Items</th><th className="text-right">Extras</th><th className="text-right">Grand total</th></tr></thead>
                        <tbody>
                            {page.data.map((p) => (
                                <tr key={p.id}>
                                    <td><Link href={purchases.show(p.id)} className="font-mono hover:underline">{p.purchase_no}</Link></td>
                                    <td>{p.purchased_on.slice(0, 10)}</td>
                                    <td>{p.supplier?.name ?? '-'}</td>
                                    <td className="text-right">{p.items_count}</td>
                                    <td className="text-right tabular-nums">{rs(p.items_total)}</td>
                                    <td className="text-right tabular-nums">{rs(p.transport_cost + p.refreshment_cost + p.other_cost)}</td>
                                    <td className="text-right font-medium tabular-nums">{rs(p.grand_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
                <Pagination links={page.links} />
            </Page>
        </>
    );
}

Purchases.layout = { breadcrumbs: [{ title: 'Purchases', href: purchases.index() }] };
