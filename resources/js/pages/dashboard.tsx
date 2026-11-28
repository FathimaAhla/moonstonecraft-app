import { Head, Link, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Page, PageHeader, Panel, Stat, Table, num, rs } from '@/components/ops';
import { dashboard } from '@/routes';
import * as materials from '@/routes/materials';
import * as reports from '@/routes/reports';

type Row = { code?: string; name: string; qty?: number; revenue?: number; profit: number; cost?: number };

type Summary = {
    sales_count: number;
    income: number;
    cost: number;
    gross_profit: number;
    wastage: { material: number; product: number; business: number; total: number };
    expenses: number;
    net_profit: number;
    cash_received: number;
    receivables: number;
    purchases_total: number;
    low_stock: { id: number; name: string; unit: string; stock: number; reorder_level: number }[];
    top_options: Row[];
    by_category: Row[];
    wastage_by_product: Row[];
};

export default function Dashboard({ month, summary }: { month: string; summary: Summary }) {
    return (
        <>
            <Head title="Dashboard" />
            <Page>
                <PageHeader title="Dashboard" description="Money, receivables and stock for the month">
                    <Input type="month" value={month} className="w-44" onChange={(e) => e.target.value && router.get(dashboard().url, { month: e.target.value }, { preserveState: true })} />
                    {(['sales', 'purchases', 'receivables'] as const).map((type) => (
                        <a key={type} href={reports.exportMethod.url(type, { query: { month } })} className="rounded-md border px-3 py-1.5 text-sm capitalize hover:bg-accent">
                            {type} CSV
                        </a>
                    ))}
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Income" value={rs(summary.income)} hint={`${summary.sales_count} sales`} />
                    <Stat label="Cost of goods" value={rs(summary.cost)} hint="Materials, parts and courier" />
                    <Stat label="Wastage + expenses" value={rs(summary.wastage.total + summary.expenses)} />
                    <Stat label="Net profit" value={rs(summary.net_profit)} tone={summary.net_profit >= 0 ? 'good' : 'bad'} />
                    <Stat label="Cash received" value={rs(summary.cash_received)} hint="Payments dated this month" />
                    <Stat label="Outstanding receivables" value={rs(summary.receivables)} hint="All unpaid balances" />
                    <Stat label="Gross profit" value={rs(summary.gross_profit)} />
                    <Stat label="Purchases (info only)" value={rs(summary.purchases_total)} hint="Not subtracted from profit" />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="Profit and loss">
                        <Table>
                            <tbody>
                                <tr><td>Income</td><td className="text-right tabular-nums">{rs(summary.income)}</td></tr>
                                <tr><td>Cost of goods sold</td><td className="text-right tabular-nums">-{rs(summary.cost)}</td></tr>
                                <tr><td>Material wastage</td><td className="text-right tabular-nums">-{rs(summary.wastage.material)}</td></tr>
                                <tr><td>Product wastage</td><td className="text-right tabular-nums">-{rs(summary.wastage.product)}</td></tr>
                                <tr><td>Business wastage</td><td className="text-right tabular-nums">-{rs(summary.wastage.business)}</td></tr>
                                <tr><td>Expenses (incl. refreshment, COD fees)</td><td className="text-right tabular-nums">-{rs(summary.expenses)}</td></tr>
                                <tr className="font-semibold"><td>Net profit</td><td className="text-right tabular-nums">{rs(summary.net_profit)}</td></tr>
                            </tbody>
                        </Table>
                    </Panel>

                    <Panel title="Low stock">
                        {summary.low_stock.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Nothing below its reorder level.</p>
                        ) : (
                            <Table>
                                <thead><tr><th>Material</th><th className="text-right">Stock</th><th className="text-right">Reorder at</th></tr></thead>
                                <tbody>
                                    {summary.low_stock.map((m) => (
                                        <tr key={m.id}>
                                            <td><Link href={materials.show(m.id)} className="hover:underline">{m.name}</Link></td>
                                            <td className="text-right text-red-600 tabular-nums">{num(m.stock)} {m.unit}</td>
                                            <td className="text-right tabular-nums">{num(m.reorder_level)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </Panel>

                    <Panel title="Top 5 products by profit">
                        <Table>
                            <thead><tr><th>Code</th><th>Name</th><th className="text-right">Qty</th><th className="text-right">Profit</th></tr></thead>
                            <tbody>
                                {summary.top_options.map((r) => (
                                    <tr key={r.code}><td>{r.code}</td><td>{r.name}</td><td className="text-right">{num(r.qty)}</td><td className="text-right tabular-nums">{rs(r.profit)}</td></tr>
                                ))}
                            </tbody>
                        </Table>
                    </Panel>

                    <Panel title="Profit by category">
                        <Table>
                            <thead><tr><th>Category</th><th className="text-right">Revenue</th><th className="text-right">Profit</th></tr></thead>
                            <tbody>
                                {summary.by_category.map((r) => (
                                    <tr key={r.name}><td>{r.name}</td><td className="text-right tabular-nums">{rs(r.revenue)}</td><td className="text-right tabular-nums">{rs(r.profit)}</td></tr>
                                ))}
                            </tbody>
                        </Table>
                    </Panel>

                    {summary.wastage_by_product.length > 0 && (
                        <Panel title="Product wastage by code">
                            <Table>
                                <thead><tr><th>Code</th><th>Name</th><th className="text-right">Qty</th><th className="text-right">Cost</th></tr></thead>
                                <tbody>
                                    {summary.wastage_by_product.map((r) => (
                                        <tr key={r.code}><td>{r.code}</td><td>{r.name}</td><td className="text-right">{num(r.qty)}</td><td className="text-right tabular-nums">{rs(r.cost)}</td></tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Panel>
                    )}
                </div>
            </Page>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
