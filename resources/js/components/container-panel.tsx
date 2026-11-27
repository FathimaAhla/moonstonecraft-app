import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyRow, Field, FormModal, NativeSelect, NumberInput, Panel, Table, destroyWithConfirm, num, rs, today } from '@/components/ops';
import type { PricedPackSize } from '@/components/pack-size-panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as containerRoutes from '@/routes/containers';

export type Container = {
    id: number;
    label: string;
    size: number;
    cost: number;
    opened_on: string;
    finished_on: string | null;
    kits_made: number | null;
    suggested_kits: number | null;
};

export type Measured = { qty_per_kit: number; cost_per_kit: number; containers: number };

type Props = {
    material: { id: number; unit: string; stock: number };
    packSizes: PricedPackSize[];
    containers: Container[];
    measured: Measured | null;
};

/**
 * Open and finish bottles of a material whose use per kit can't be measured.
 */
export function ContainerPanel({ material, packSizes, containers, measured }: Props) {
    const { unit } = material;
    const [opening, setOpening] = useState(false);
    const [finishing, setFinishing] = useState<Container | null>(null);
    const openForm = useForm({ pack_size_id: '', label: '', size: '', opened_on: today() });
    const finishForm = useForm({ finished_on: today(), kits_made: '' });

    const startOpen = () => {
        const first = packSizes[0];
        openForm.clearErrors();
        openForm.setData({ pack_size_id: first ? String(first.id) : '', label: first?.name ?? 'Bottle', size: first ? String(first.size) : '', opened_on: today() });
        setOpening(true);
    };

    const startFinish = (container: Container) => {
        finishForm.clearErrors();
        finishForm.setData({ finished_on: today(), kits_made: container.suggested_kits ? String(container.suggested_kits) : '' });
        setFinishing(container);
    };

    return (
        <>
            <Panel title="Opened and finished" flush actions={<Button size="sm" onClick={startOpen}>Open one</Button>}>
                <p className="px-4 py-3 text-sm text-muted-foreground">
                    Unopened: <span className="font-semibold text-foreground">{num(material.stock, 3)} {unit}</span>
                    {measured
                        ? <> · Real usage: <span className="font-semibold text-foreground">{num(measured.qty_per_kit, 3)} {unit} ({rs(measured.cost_per_kit)}) per kit</span>, from {measured.containers} finished</>
                        : ' · Finish one to see the real usage per kit'}
                </p>
                <Table>
                    <thead><tr><th>Container</th><th>Opened</th><th>Finished</th><th className="text-right">Cost</th><th className="text-right">Kits made</th><th className="text-right">Cost per kit</th><th /></tr></thead>
                    <tbody>
                        {containers.length === 0 && <EmptyRow colSpan={7}>Nothing opened yet.</EmptyRow>}
                        {containers.map((c) => (
                            <tr key={c.id}>
                                <td className="font-medium">{c.label} ({num(c.size, 3)} {unit})</td>
                                <td>{c.opened_on}</td>
                                <td>{c.finished_on ?? <span className="font-medium text-brand-teal">In use</span>}</td>
                                <td className="text-right tabular-nums">{rs(c.cost)}</td>
                                <td className="text-right tabular-nums">{c.finished_on ? num(c.kits_made, 3) : `${num(c.suggested_kits, 3)} so far`}</td>
                                <td className="text-right tabular-nums">{c.kits_made ? rs(c.cost / c.kits_made) : '-'}</td>
                                <td className="space-x-2 text-right">
                                    {c.finished_on ? (
                                        <Button size="sm" variant="ghost" onClick={() => router.post(containerRoutes.reopen.url(c.id), {}, { preserveScroll: true })}>Undo finish</Button>
                                    ) : (
                                        <>
                                            <Button size="sm" onClick={() => startFinish(c)}>Finished</Button>
                                            <Button size="sm" variant="ghost" onClick={() => destroyWithConfirm(containerRoutes.destroy.url(c.id), 'Opened by mistake? The quantity goes back into stock.')}>Remove</Button>
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Panel>

            <FormModal
                open={opening}
                onOpenChange={setOpening}
                title="Open one"
                description="Takes this quantity out of unopened stock."
                submitLabel="Open"
                processing={openForm.processing}
                onSubmit={() => openForm.post(containerRoutes.store.url(material.id), { preserveScroll: true, onSuccess: () => setOpening(false) })}
            >
                {packSizes.length > 0 && (
                    <Field label="Pack size">
                        <NativeSelect
                            value={openForm.data.pack_size_id}
                            onChange={(e) => {
                                const pack = packSizes.find((p) => String(p.id) === e.target.value);
                                openForm.setData({ ...openForm.data, pack_size_id: e.target.value, label: pack?.name ?? 'Bottle', size: pack ? String(pack.size) : '' });
                            }}
                        >
                            {packSizes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            <option value="">Other size...</option>
                        </NativeSelect>
                    </Field>
                )}
                <Field label="Name" error={openForm.errors.label}><Input value={openForm.data.label} maxLength={50} onChange={(e) => openForm.setData('label', e.target.value)} /></Field>
                <Field label={`Quantity in ${unit}`} error={openForm.errors.size}><NumberInput value={openForm.data.size} onChange={(e) => openForm.setData('size', e.target.value)} /></Field>
                <Field label="Opened on" error={openForm.errors.opened_on}><Input type="date" value={openForm.data.opened_on} onChange={(e) => openForm.setData('opened_on', e.target.value)} /></Field>
            </FormModal>

            <FormModal
                open={finishing !== null}
                onOpenChange={(open) => !open && setFinishing(null)}
                title={`Finished: ${finishing?.label ?? ''}`}
                description="How many kits did it last? The number is filled from kits packed or sold since it was opened; change it if needed."
                submitLabel="Mark finished"
                processing={finishForm.processing}
                onSubmit={() => finishing && finishForm.post(containerRoutes.finish.url(finishing.id), { preserveScroll: true, onSuccess: () => setFinishing(null) })}
            >
                <Field label="Kits made with it" error={finishForm.errors.kits_made}><NumberInput value={finishForm.data.kits_made} onChange={(e) => finishForm.setData('kits_made', e.target.value)} autoFocus /></Field>
                <Field label="Finished on" error={finishForm.errors.finished_on}><Input type="date" value={finishForm.data.finished_on} onChange={(e) => finishForm.setData('finished_on', e.target.value)} /></Field>
            </FormModal>
        </>
    );
}
