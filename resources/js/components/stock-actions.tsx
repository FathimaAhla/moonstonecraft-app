import { useForm } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { EmptyRow, Field, FormModal, NativeSelect, NumberInput, Table, num, rs, today } from '@/components/ops';
import * as stock from '@/routes/stock';

export type StockAction =
    | { type: 'make'; id: number; name: string; hasRecipe: boolean }
    | { type: 'pack' | 'unpack'; id: number; name: string }
    | { type: 'adjust'; kind: 'components' | 'options'; id: number; name: string };

export type Movement = {
    id: number;
    moved_on: string;
    qty: number;
    reason: string;
    note: string | null;
    unit_cost: number;
    ref_type: string | null;
    ref_id: number | null;
    component?: { code: string; name: string } | null;
    option?: { code: string; name: string } | null;
};

const TITLES = { make: 'Add made stock', pack: 'Pack kits', unpack: 'Unpack kits', adjust: 'Correct stock' };

const DESCRIPTIONS = {
    make: 'You made these by hand. Part stock goes up. Materials are not deducted now: tracked materials leave stock when the part is packed into a kit or sold.',
    pack: 'Parts and tracked materials go out of stock and the packed kits go into ready stock. The contents are saved with the packing.',
    unpack: 'Packed kits are opened and what was saved when they were packed goes back into stock. Kits packed before contents were recorded cannot be restored.',
    adjust: 'Use a negative number to remove (damaged, missing) and a positive number to add (found, correction).',
};

/**
 * One modal for making parts, packing / unpacking kits and correcting stock by hand.
 */
export function StockActionModal({ action, reasons, onClose }: { action: StockAction | null; reasons: string[]; onClose: () => void }) {
    const form = useForm({ qty: '', date: today(), reason: 'damaged', note: '' });

    if (!action) {
        return null;
    }

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        const { qty, date, note } = form.data;

        form.transform((data) => {
            switch (action.type) {
                case 'make':
                    return { qty, made_on: date, note };
                case 'adjust':
                    return { qty, reason: data.reason, note };
                default:
                    return { qty, packed_on: date, note };
            }
        });

        if (action.type === 'make') {
            form.post(stock.make.url(action.id), options);
        } else if (action.type === 'pack') {
            form.post(stock.pack.url(action.id), options);
        } else if (action.type === 'unpack') {
            form.post(stock.unpack.url(action.id), options);
        } else if (action.type === 'adjust') {
            form.post(action.kind === 'components' ? stock.adjustComponent.url(action.id) : stock.adjustOption.url(action.id), options);
        }
    };

    return (
        <FormModal open onOpenChange={(open) => !open && onClose()} title={`${TITLES[action.type]}: ${action.name}`} description={DESCRIPTIONS[action.type]} processing={form.processing} onSubmit={submit}>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={action.type === 'adjust' ? 'Quantity (+ / -)' : 'Quantity'} error={form.errors.qty}>
                    <NumberInput value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} autoFocus />
                </Field>
                {action.type === 'adjust' ? (
                    <Field label="Reason" error={form.errors.reason}>
                        <NativeSelect value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)}>
                            {reasons.map((r) => <option key={r} value={r} className="capitalize">{r}</option>)}
                        </NativeSelect>
                    </Field>
                ) : (
                    <Field label="Date"><Input type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} /></Field>
                )}
                <Field label="Note (optional)" className="sm:col-span-2" error={form.errors.note}>
                    <Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                </Field>
            </div>
        </FormModal>
    );
}

export function reasonTone(qty: number): string {
    return qty < 0 ? 'text-brand-coral' : 'text-brand-leaf';
}

export function MovementsTable({ movements, showItem = false }: { movements: Movement[]; showItem?: boolean }) {
    return (
        <Table>
            <thead>
                <tr>
                    <th>Date</th>
                    {showItem && <th>Item</th>}
                    <th>Reason</th>
                    <th>Note / reference</th>
                    <th className="text-right">Qty</th>
                    <th className="text-right">Unit cost</th>
                </tr>
            </thead>
            <tbody>
                {movements.length === 0 && <EmptyRow colSpan={showItem ? 6 : 5}>No stock movements yet.</EmptyRow>}
                {movements.map((m) => {
                    const item = m.component ?? m.option;

                    return (
                        <tr key={m.id}>
                            <td>{m.moved_on.slice(0, 10)}</td>
                            {showItem && <td>{item ? `${item.code} ${item.name}` : '-'}</td>}
                            <td className="capitalize">{m.reason}</td>
                            <td className="text-muted-foreground">{m.note ?? (m.ref_type ? `${m.ref_type.split('\\').pop()} #${m.ref_id}` : '-')}</td>
                            <td className={`text-right font-medium tabular-nums ${reasonTone(m.qty)}`}>{m.qty > 0 ? '+' : ''}{num(m.qty, 3)}</td>
                            <td className="text-right tabular-nums">{rs(m.unit_cost)}</td>
                        </tr>
                    );
                })}
            </tbody>
        </Table>
    );
}
