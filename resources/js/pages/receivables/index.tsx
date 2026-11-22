import { Head, Link } from '@inertiajs/react';
import { Page, PageHeader, Panel, StatusBadge, Table, rs } from '@/components/ops';
import * as customers from '@/routes/customers';
import * as receivables from '@/routes/receivables';
import * as reports from '@/routes/reports';
import * as sales from '@/routes/sales';

type SaleRow = { id: number; invoice_no: string; sold_on: string; due_date: string | null; days_overdue: number; total: number; paid_amount: number; balance: number; payment_status: string };
type Group = { customer: { id: number; name: string; phone: string; address: string | null } | null; balance: number; sales: SaleRow[] };

export default function Receivables({ groups, total }: { groups: Group[]; total: number }) {
    return (
        <>
            <Head title="Receivables" />
            <Page>
                <PageHeader title="Receivables" description={`Outstanding: ${rs(total)}`}>
                    <a href={reports.exportMethod.url('receivables')} className="rounded-md border px-3 py-1.5 text-sm hover:bg-accent">Export CSV</a>
                </PageHeader>
                {groups.length === 0 && <p className="text-sm text-muted-foreground">Nothing outstanding.</p>}
                {groups.map((g, idx) => (
                    <Panel
                        key={g.customer?.id ?? `walkin-${idx}`}
                        title={g.customer ? `${g.customer.name} · ${g.customer.phone}` : 'Walk-in'}
                        actions={<span className="font-semibold tabular-nums">{rs(g.balance)}</span>}
                    >
                        {g.customer?.address && <p className="mb-2 text-sm text-muted-foreground">{g.customer.address}</p>}
                        <Table>
                            <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th className="text-right">Overdue</th><th className="text-right">Total</th><th className="text-right">Paid</th><th className="text-right">Balance</th><th>Status</th></tr></thead>
                            <tbody>
                                {g.sales.map((s) => (
                                    <tr key={s.id}>
                                        <td><Link href={sales.show(s.id)} className="font-mono hover:underline">{s.invoice_no}</Link></td>
                                        <td>{s.sold_on}</td>
                                        <td>{s.due_date ?? '-'}</td>
                                        <td className={`text-right ${s.days_overdue > 0 ? 'text-red-600' : ''}`}>{s.days_overdue > 0 ? `${s.days_overdue} days` : '-'}</td>
                                        <td className="text-right tabular-nums">{rs(s.total)}</td>
                                        <td className="text-right tabular-nums">{rs(s.paid_amount)}</td>
                                        <td className="text-right font-medium tabular-nums">{rs(s.balance)}</td>
                                        <td><StatusBadge status={s.payment_status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        {g.customer && <Link href={customers.show(g.customer.id)} className="mt-2 inline-block text-sm hover:underline">Customer history</Link>}
                    </Panel>
                ))}
            </Page>
        </>
    );
}

Receivables.layout = { breadcrumbs: [{ title: 'Receivables', href: receivables.index() }] };
