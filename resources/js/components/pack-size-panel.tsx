import { useForm } from '@inertiajs/react';
import { DeleteButton, EmptyRow, Field, NumberInput, Panel, Table, num, rs } from '@/components/ops';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as packSizeRoutes from '@/routes/pack-sizes';

export type PricedPackSize = { id: number; name: string; size: number; last_price: number | null; last_unit_cost: number | null };

/**
 * The sizes a material is bought in, with the last price of each and which is cheapest per unit.
 */
export function PackSizePanel({ materialId, unit, packSizes }: { materialId: number; unit: string; packSizes: PricedPackSize[] }) {
    const form = useForm({ name: '', size: '' });
    const priced = packSizes.filter((p) => p.last_unit_cost !== null);
    const cheapest = priced.length > 1 ? Math.min(...priced.map((p) => p.last_unit_cost as number)) : null;

    return (
        <Panel title="Pack sizes" flush>
            <Table>
                <thead><tr><th>Pack</th><th className="text-right">Holds</th><th className="text-right">Last price</th><th className="text-right">Cost per {unit}</th><th /></tr></thead>
                <tbody>
                    {packSizes.length === 0 && <EmptyRow colSpan={5}>No pack sizes yet. Add each size you buy, then choose it on a purchase.</EmptyRow>}
                    {packSizes.map((p) => (
                        <tr key={p.id}>
                            <td className="font-medium">{p.name}</td>
                            <td className="text-right tabular-nums">{num(p.size, 3)} {unit}</td>
                            <td className="text-right tabular-nums">{p.last_price === null ? 'Not bought yet' : rs(p.last_price)}</td>
                            <td className="text-right tabular-nums">
                                {p.last_unit_cost === null ? '-' : rs(p.last_unit_cost, 4)}
                                {p.last_unit_cost !== null && p.last_unit_cost === cheapest && <Badge variant="outline" className="ml-2 border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">Cheapest</Badge>}
                            </td>
                            <td className="text-right"><DeleteButton url={packSizeRoutes.destroy.url(p.id)} message="Remove this pack size? Past purchases are not changed." /></td>
                        </tr>
                    ))}
                </tbody>
            </Table>
            <form
                className="grid items-start gap-3 border-t p-4 sm:grid-cols-[1fr_1fr_auto]"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(packSizeRoutes.store.url(materialId), { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <Field label="Pack name" error={form.errors.name}><Input value={form.data.name} maxLength={20} placeholder="500 ml bottle" onChange={(e) => form.setData('name', e.target.value)} /></Field>
                <Field label={`How many ${unit} it holds`} error={form.errors.size}><NumberInput value={form.data.size} placeholder="500" onChange={(e) => form.setData('size', e.target.value)} /></Field>
                <Button className="sm:mt-5.5" disabled={form.processing}>Add pack size</Button>
            </form>
        </Panel>
    );
}
