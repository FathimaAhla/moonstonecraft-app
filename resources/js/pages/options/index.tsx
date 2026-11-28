import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyRow, Field, NativeSelect, Page, PageHeader, Panel, Table, num, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import * as options from '@/routes/options';
import * as products from '@/routes/products';

type Row = {
    id: number; code: string; name: string; kit_id: number; kit: string | null; category: string | null;
    retail_price: number; wholesale_price: number | null; stock: number; reorder_level: number; active: boolean; low: boolean;
    figures: number; pots: number; brushes: number; can_make: { can_make: number | null; limit: string | null };
};
type Filters = { category: number | null; kit: number | null; status: 'active' | 'inactive' | 'all'; low: boolean };
type Named = { id: number; name: string };

export default function ProductList({ products: rows, categories, kits, filters }: {
    products: Row[];
    categories: Named[];
    kits: (Named & { category_id: number | null })[];
    filters: Filters;
}) {
    const apply = (patch: Partial<Filters>) => {
        const next = { ...filters, ...patch };
        const query = Object.fromEntries(
            Object.entries(next).filter(([key, value]) => value !== null && value !== false && !(key === 'status' && value === 'active')).map(([key, value]) => [key, String(value === true ? 1 : value)]),
        );

        router.get(options.index.url(), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const number = (value: string) => (value === '' ? null : Number(value));
    const kitList = kits.filter((k) => !filters.category || k.category_id === filters.category);
    const filtered = filters.category !== null || filters.kit !== null || filters.status !== 'active' || filters.low;

    return (
        <>
            <Head title="Products" />
            <Page>
                <PageHeader title="Products" description="Everything you sell, with its kit, what is inside, prices and stock." />

                <Panel>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="Category">
                            <NativeSelect value={filters.category ?? ''} onChange={(e) => apply({ category: number(e.target.value), kit: null })}>
                                <option value="">All categories</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </NativeSelect>
                        </Field>
                        <Field label="Kit">
                            <NativeSelect value={filters.kit ?? ''} onChange={(e) => apply({ kit: number(e.target.value) })}>
                                <option value="">All kits</option>
                                {kitList.map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}
                            </NativeSelect>
                        </Field>
                        <Field label="Status">
                            <NativeSelect value={filters.status} onChange={(e) => apply({ status: e.target.value as Filters['status'] })}>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="all">All</option>
                            </NativeSelect>
                        </Field>
                        <Field label="Stock">
                            <NativeSelect value={filters.low ? 'low' : ''} onChange={(e) => apply({ low: e.target.value === 'low' })}>
                                <option value="">Any stock</option>
                                <option value="low">Low stock only</option>
                            </NativeSelect>
                        </Field>
                    </div>
                    <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
                        <span>{rows.length} products</span>
                        {filtered && <Button variant="ghost" size="sm" onClick={() => router.get(options.index.url())}>Clear filters</Button>}
                    </div>
                </Panel>

                <Panel flush>
                    <Table>
                        <thead>
                            <tr>
                                <th>Code</th><th>Product</th><th>Kit</th>
                                <th className="text-right">Figures</th><th className="text-right">Pots</th><th className="text-right">Brushes</th>
                                <th className="text-right">Retail</th><th className="text-right">Wholesale</th>
                                <th className="text-right">Ready stock</th><th className="text-right">Can make</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={10}>No products match these filters.</EmptyRow>}
                            {rows.map((p) => (
                                <tr key={p.id} className={p.active ? '' : 'opacity-50'}>
                                    <td className="font-mono whitespace-nowrap"><Link href={options.show(p.id)} className="hover:underline">{p.code}</Link></td>
                                    <td><Link href={options.show(p.id)} className="font-medium hover:underline">{p.name}</Link></td>
                                    <td>{p.kit && <Link href={products.show(p.kit_id)} className="hover:underline">{p.kit}</Link>}<div className="text-xs text-muted-foreground">{p.category}</div></td>
                                    <td className="text-right tabular-nums">{p.figures || '-'}</td>
                                    <td className="text-right tabular-nums">{p.pots || '-'}</td>
                                    <td className="text-right tabular-nums">{p.brushes || '-'}</td>
                                    <td className="text-right tabular-nums">{rs(p.retail_price)}</td>
                                    <td className="text-right tabular-nums">{p.wholesale_price !== null ? rs(p.wholesale_price) : '-'}</td>
                                    <td className={cn('text-right font-medium tabular-nums', p.low && 'text-brand-coral')}>{num(p.stock, 0)}</td>
                                    <td className="text-right tabular-nums" title={p.can_make.limit ? `Limited by ${p.can_make.limit}` : undefined}>{p.can_make.can_make === null ? '-' : num(p.can_make.can_make, 0)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>
        </>
    );
}

ProductList.layout = { breadcrumbs: [{ title: 'Products', href: options.index() }] };
