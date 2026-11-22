import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { DeleteButton, EmptyRow, Field, FormModal, NativeSelect, Page, PageHeader, Panel, Table, Textarea, pct, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import * as options from '@/routes/options';
import * as products from '@/routes/products';

type Product = { id: number; code: string; name: string; category_id: number; category: string; notes: string | null; active: boolean };
type Option = { id: number; code: string; name: string; retail_price: number; wholesale_price: number | null; active: boolean; cost: number; cost_courier: number };

export default function ProductShow({ product, options: optionRows, categories }: { product: Product; options: Option[]; categories: { id: number; name: string }[] }) {
    const [open, setOpen] = useState(false);
    const details = useForm({ category_id: String(product.category_id), code: product.code, name: product.name, notes: product.notes ?? '', active: product.active });

    return (
        <>
            <Head title={product.name} />
            <Page>
                <PageHeader title={product.name} description={`${product.code} · ${product.category} · kit`}>
                    {!product.active && <Badge variant="outline">Inactive</Badge>}
                    <Button variant="outline" onClick={() => { details.clearErrors(); setOpen(true); }}><Pencil /> Edit</Button>
                    <DeleteButton url={products.destroy.url(product.id)} message="Delete this kit?" withText />
                </PageHeader>

                {product.notes && <p className="text-sm text-muted-foreground">{product.notes}</p>}

                <Panel title="Options" flush actions={<Button size="sm" asChild><Link href={options.create(product.id)}><Plus /> Add option</Link></Button>}>
                    <Table>
                        <thead><tr><th>Code</th><th>Name</th><th className="text-right">Kit cost</th><th className="text-right">Retail</th><th className="text-right">Profit</th><th className="text-right">Profit %</th><th className="text-right">Profit w/ courier</th><th className="text-right">Wholesale</th></tr></thead>
                        <tbody>
                            {optionRows.length === 0 && <EmptyRow colSpan={8}>No options yet. Add one and pick its figure, paints and packing.</EmptyRow>}
                            {optionRows.map((o) => {
                                const profit = o.retail_price - o.cost;

                                return (
                                    <tr key={o.id} className={o.active ? '' : 'opacity-50'}>
                                        <td className="font-mono"><Link href={options.show(o.id)} className="hover:underline">{o.code}</Link></td>
                                        <td><Link href={options.show(o.id)} className="font-medium hover:underline">{o.name}</Link></td>
                                        <td className="text-right tabular-nums">{rs(o.cost)}</td>
                                        <td className="text-right font-medium tabular-nums">{rs(o.retail_price)}</td>
                                        <td className={cn('text-right tabular-nums', profit < 0 ? 'text-brand-coral' : 'text-brand-leaf')}>{rs(profit)}</td>
                                        <td className="text-right tabular-nums">{o.retail_price ? pct(profit / o.retail_price) : '-'}</td>
                                        <td className="text-right tabular-nums">{rs(o.retail_price - o.cost_courier)}</td>
                                        <td className="text-right tabular-nums">{o.wholesale_price === null ? '-' : rs(o.wholesale_price)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title="Edit kit" processing={details.processing} onSubmit={() => details.put(products.update.url(product.id), { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Category" error={details.errors.category_id}>
                        <NativeSelect value={details.data.category_id} onChange={(e) => details.setData('category_id', e.target.value)}>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </NativeSelect>
                    </Field>
                    <Field label="Code" error={details.errors.code}><Input value={details.data.code} onChange={(e) => details.setData('code', e.target.value.toUpperCase())} /></Field>
                    <Field label="Name" error={details.errors.name} className="sm:col-span-2"><Input value={details.data.name} onChange={(e) => details.setData('name', e.target.value)} /></Field>
                    <Field label="Notes" className="sm:col-span-2"><Textarea value={details.data.notes} onChange={(e) => details.setData('notes', e.target.value)} /></Field>
                </div>
                <label className="flex items-center gap-2 text-sm"><Checkbox checked={details.data.active} onCheckedChange={(v) => details.setData('active', v === true)} /> Active</label>
            </FormModal>
        </>
    );
}

ProductShow.layout = { breadcrumbs: [{ title: 'Kits', href: products.index() }] };
