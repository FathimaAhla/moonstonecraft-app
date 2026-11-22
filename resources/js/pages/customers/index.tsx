import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Page, PageHeader, Pagination, Panel, Table, rs } from '@/components/ops';
import type { Paginated } from '@/components/ops';
import * as customers from '@/routes/customers';

type Row = { id: number; name: string; phone: string; city: string | null; type: string; sales_count: number; balance: number | null };

export default function Customers({ customers: page, filters }: { customers: Paginated<Row>; filters: { q: string } }) {
    const [q, setQ] = useState(filters.q);

    return (
        <>
            <Head title="Customers" />
            <Page>
                <PageHeader title="Customers">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(customers.index.url(), q ? { q } : {}, { preserveState: true });
                        }}
                    >
                        <Input className="w-64" placeholder="Search name or phone" value={q} onChange={(e) => setQ(e.target.value)} />
                    </form>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Name</th><th>Phone</th><th>City</th><th>Type</th><th className="text-right">Sales</th><th className="text-right">Balance</th></tr></thead>
                        <tbody>
                            {page.data.map((c) => (
                                <tr key={c.id}>
                                    <td><Link href={customers.show(c.id)} className="hover:underline">{c.name}</Link></td>
                                    <td>{c.phone}</td>
                                    <td>{c.city ?? '-'}</td>
                                    <td className="capitalize">{c.type}</td>
                                    <td className="text-right">{c.sales_count}</td>
                                    <td className="text-right tabular-nums">{Number(c.balance) > 0 ? rs(c.balance) : '-'}</td>
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

Customers.layout = { breadcrumbs: [{ title: 'Customers', href: customers.index() }] };
