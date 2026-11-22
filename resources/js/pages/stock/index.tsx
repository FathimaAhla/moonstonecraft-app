import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { EmptyRow, Page, PageHeader, Panel, Stat, Table, num, rs } from '@/components/ops';
import { MovementsTable, StockActionModal } from '@/components/stock-actions';
import type { Movement, StockAction } from '@/components/stock-actions';
import * as components from '@/routes/components';
import * as options from '@/routes/options';
import * as stock from '@/routes/stock';
import * as stockChecks from '@/routes/stock-checks';

type CanMake = { can_make: number | null; limit: string | null };
type PartRow = { id: number; code: string; name: string; category: string; stock: number; reorder_level: number; unit_cost: number; has_recipe: boolean; from_materials: CanMake };
type KitRow = { id: number; code: string; name: string; product: string | null; stock: number; reorder_level: number; retail_price: number; unit_cost: number; can_make: CanMake };

export default function StockIndex({ components: parts, options: kits, movements, adjustReasons }: { components: PartRow[]; options: KitRow[]; movements: Movement[]; adjustReasons: string[] }) {
    const [action, setAction] = useState<StockAction | null>(null);
    const groups = Object.entries(
        parts.reduce<Record<string, PartRow[]>>((all, p) => ({ ...all, [p.category]: [...(all[p.category] ?? []), p] }), {}),
    );
    const partsValue = parts.reduce((sum, p) => sum + Math.max(p.stock, 0) * p.unit_cost, 0);
    const kitsValue = kits.reduce((sum, k) => sum + Math.max(k.stock, 0) * k.unit_cost, 0);
    const low = parts.filter((p) => p.stock <= p.reorder_level).length;

    return (
        <>
            <Head title="Stock" />
            <Page>
                <PageHeader title="Stock" description="Parts you make (figures, paint pots) and packed kits ready to sell. Materials have their own page.">
                    <Button variant="outline" asChild><Link href={stockChecks.index()}>Stock checks</Link></Button>
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Parts in stock" value={num(parts.reduce((s, p) => s + Math.max(p.stock, 0), 0), 0)} hint={rs(partsValue)} />
                    <Stat label="Packed kits" value={num(kits.reduce((s, k) => s + Math.max(k.stock, 0), 0), 0)} hint={rs(kitsValue)} />
                    <Stat label="Low parts" value={String(low)} tone={low > 0 ? 'bad' : 'good'} />
                    <Stat label="Stock value" value={rs(partsValue + kitsValue)} />
                </div>

                <Panel title="Parts stock" flush>
                    <Table>
                        <thead><tr><th>Part</th><th className="text-right">In stock</th><th className="text-right">Unit cost</th><th className="text-right">Materials can make</th><th /></tr></thead>
                        <tbody>
                            {parts.length === 0 && <EmptyRow colSpan={5}>No parts keep stock yet. Open a component and tick "Keeps own stock".</EmptyRow>}
                            {groups.map(([category, rows]) => [
                                <tr key={category} className="bg-muted/40"><td colSpan={5} className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">{category}</td></tr>,
                                ...rows.map((p) => (
                                    <tr key={p.id}>
                                        <td><Link href={components.show(p.id)} className="font-medium hover:underline">{p.code} {p.name}</Link></td>
                                        <td className={`text-right font-semibold tabular-nums ${p.stock <= p.reorder_level ? 'text-brand-coral' : ''}`}>{num(p.stock, 0)}</td>
                                        <td className="text-right tabular-nums">{rs(p.unit_cost)}</td>
                                        <td className="text-right text-muted-foreground tabular-nums">{p.has_recipe && p.from_materials.can_make !== null ? `${num(p.from_materials.can_make, 0)} (${p.from_materials.limit})` : '-'}</td>
                                        <td className="text-right">
                                            <div className="flex justify-end gap-2">
                                                <Button size="sm" onClick={() => setAction({ type: 'make', id: p.id, name: p.name, hasRecipe: p.has_recipe })}>+ Made</Button>
                                                <Button size="sm" variant="outline" onClick={() => setAction({ type: 'adjust', kind: 'components', id: p.id, name: p.name })}>Correct</Button>
                                            </div>
                                        </td>
                                    </tr>
                                )),
                            ])}
                        </tbody>
                    </Table>
                </Panel>

                <Panel title="Kits: ready and can make" flush>
                    <Table>
                        <thead><tr><th>Option</th><th className="text-right">Packed</th><th className="text-right">Can make</th><th>Limited by</th><th className="text-right">Cost</th><th className="text-right">Price</th><th /></tr></thead>
                        <tbody>
                            {kits.length === 0 && <EmptyRow colSpan={7}>No kit options yet.</EmptyRow>}
                            {kits.map((k) => (
                                <tr key={k.id}>
                                    <td><Link href={options.show(k.id)} className="font-medium hover:underline">{k.code}</Link> <span className="text-muted-foreground">{k.product} - {k.name}</span></td>
                                    <td className={`text-right font-semibold tabular-nums ${k.stock <= k.reorder_level && k.reorder_level > 0 ? 'text-brand-coral' : ''}`}>{num(k.stock, 0)}</td>
                                    <td className="text-right tabular-nums">{k.can_make.can_make === null ? '-' : num(k.can_make.can_make, 0)}</td>
                                    <td className="text-muted-foreground">{k.can_make.limit ?? '-'}</td>
                                    <td className="text-right tabular-nums">{rs(k.unit_cost)}</td>
                                    <td className="text-right tabular-nums">{rs(k.retail_price)}</td>
                                    <td className="text-right">
                                        <div className="flex justify-end gap-2">
                                            <Button size="sm" onClick={() => setAction({ type: 'pack', id: k.id, name: k.code })}>Pack</Button>
                                            <Button size="sm" variant="outline" disabled={k.stock <= 0} onClick={() => setAction({ type: 'unpack', id: k.id, name: k.code })}>Unpack</Button>
                                            <Button size="sm" variant="outline" onClick={() => setAction({ type: 'adjust', kind: 'options', id: k.id, name: k.code })}>Correct</Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                    <p className="border-t px-4 py-2 text-xs text-muted-foreground">Can make counts parts in stock as if you make only that option. Parts are shared, so packing one option lowers the others.</p>
                </Panel>

                <Panel title="Latest part and kit movements" flush>
                    <MovementsTable movements={movements} showItem />
                </Panel>
            </Page>

            <StockActionModal key={action ? `${action.type}-${action.id}` : 'none'} action={action} reasons={adjustReasons} onClose={() => setAction(null)} />
        </>
    );
}

StockIndex.layout = { breadcrumbs: [{ title: 'Stock', href: stock.index() }] };
