import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { NativeSelect, Page, PageHeader, Pagination, Panel, StatusBadge, Table, rs } from '@/components/ops';
import type { Paginated } from '@/components/ops';
import * as sales from '@/routes/sales';

type Row = { id: number; invoice_no: string; sold_on: string; sale_type: string; payment_method: string; total: number; balance: number; payment_status: string; customer: { name: string; phone: string } | null };

export default function Sales({ sales: page, filters }: { sales: Paginated<Row>; filters: { status: string | null } }) {
    return (
        <>
            <Head title="Sales" />
            <Page>
                <PageHeader title="Sales">
                    <NativeSelect className="w-44" value={filters.status ?? ''} onChange={(e) => router.get(sales.index.url(), e.target.value ? { status: e.target.value } : {})}>
                        <option value="">All statuses</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="cod_pending">COD pending</option>
                    </NativeSelect>
                    <Button asChild><Link href={sales.create()}>New sale</Link></Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Type</th><th>Payment</th><th className="text-right">Total</th><th className="text-right">Balance</th><th>Status</th></tr></thead>
                        <tbody>
                            {page.data.map((s) => (
                                <tr key={s.id}>
                                    <td><Link href={sales.show(s.id)} className="font-mono hover:underline">{s.invoice_no}</Link></td>
                                    <td>{s.sold_on.slice(0, 10)}</td>
                                    <td>{s.customer ? `${s.customer.name} · ${s.customer.phone}` : 'Walk-in'}</td>
                                    <td className="capitalize">{s.sale_type}</td>
                                    <td className="uppercase">{s.payment_method}</td>
                                    <td className="text-right tabular-nums">{rs(s.total)}</td>
                                    <td className="text-right tabular-nums">{s.balance > 0 ? rs(s.balance) : '-'}</td>
                                    <td><StatusBadge status={s.payment_status} /></td>
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

Sales.layout = { breadcrumbs: [{ title: 'Sales', href: sales.index() }] };
