import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyRow, Field, FormModal, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, num, rs, today } from '@/components/ops';
import { ColourDots, PaintSetLabel } from '@/components/paint-dots';
import type { PaintSet } from '@/components/paint-dots';
import { cn } from '@/lib/utils';
import * as components from '@/routes/components';
import * as paintColours from '@/routes/paint-colours';
import * as paintSets from '@/routes/paint-sets';
import * as sales from '@/routes/sales';
import * as stock from '@/routes/stock';

type SetRow = PaintSet & { from_paint: { can_make: number | null; limit: string | null }; sold_month: number; sold: number };
type Colour = { id: number; name: string; short_name: string; colour: string | null; stock: number; unit: string; reorder_level: number; used_this_month: number; pots: number };
type Waiting = { sale_item_id: number; sale_id: number; invoice_no: string; sold_on: string; customer: string | null; item: string; qty: number; remaining: number };

export default function PaintSets({ sets, colours, potMaterials, waiting, potCounts }: {
    sets: SetRow[];
    colours: Colour[];
    potMaterials: { id: number; name: string; unit: string; stock: number }[];
    waiting: Waiting[];
    potCounts: number[];
}) {
    const [modal, setModal] = useState<'set' | 'colour' | null>(null);
    const [making, setMaking] = useState<SetRow | null>(null);
    const newSet = useForm({ pots: '3', colours: ['', '', ''] as string[], ml_per_pot: '3', pot_material_id: '', name: '' });
    const newColour = useForm({ name: '', colour: '#e24b4a', unit: 'ml' });
    const made = useForm({ qty: '', made_on: today(), note: '' });
    const close = (open: boolean) => !open && setModal(null);
    const colourById = (id: string) => colours.find((c) => String(c.id) === id);
    const totalSets = sets.reduce((sum, s) => sum + Math.max(s.stock, 0), 0);

    const setPots = (pots: string) => {
        const count = Number(pots);
        newSet.setData((prev) => ({ ...prev, pots, colours: Array.from({ length: count }, (_, i) => prev.colours[i] ?? '') }));
    };

    const errors = newSet.errors as Record<string, string>;

    return (
        <>
            <Head title="Paint sets" />
            <Page>
                <PageHeader title="Paint sets" description="Colours, the pot sets you cut from strips, single pots per colour, and what each set sold.">
                    <Button variant="outline" onClick={() => { newColour.reset(); newColour.clearErrors(); setModal('colour'); }}><Plus /> Add colour</Button>
                    <Button onClick={() => { newSet.reset(); newSet.clearErrors(); setModal('set'); }}><Plus /> New paint set</Button>
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat label="Paint sets in stock" value={num(totalSets, 0)} hint={`${sets.length} colour combinations`} />
                    <Stat label="Colours" value={num(colours.length, 0)} />
                    <Stat label="Kits waiting for paint" value={num(waiting.reduce((sum, w) => sum + w.remaining, 0), 0)} tone={waiting.length ? 'bad' : undefined} hint={waiting.length ? 'Choose on the sale' : 'Nothing waiting'} />
                </div>

                {waiting.length > 0 && (
                    <Panel title="Sold, paint set not chosen yet" flush>
                        <Table>
                            <thead><tr><th>Sale</th><th>Kit</th><th className="text-right">Not chosen</th><th /></tr></thead>
                            <tbody>
                                {waiting.map((w) => (
                                    <tr key={w.sale_item_id}>
                                        <td className="font-mono">{w.invoice_no}<div className="font-sans text-xs text-muted-foreground">{w.sold_on}{w.customer ? ` · ${w.customer}` : ''}</div></td>
                                        <td>{w.item}</td>
                                        <td className="text-right tabular-nums">{num(w.remaining, 0)} of {num(w.qty, 0)}</td>
                                        <td className="text-right"><Button size="sm" variant="outline" asChild><Link href={sales.show(w.sale_id)}>Choose paint</Link></Button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Panel>
                )}

                <Panel title="Paint sets" flush>
                    <Table>
                        <thead><tr><th>Set</th><th className="text-right">In stock</th><th className="text-right">Paint per set</th><th className="text-right">Can make from paint</th><th className="text-right">Sold (month / all)</th><th /></tr></thead>
                        <tbody>
                            {sets.length === 0 && <EmptyRow colSpan={6}>Add your colours, then make a paint set such as 3-pot Red / Yellow / Blue.</EmptyRow>}
                            {sets.map((s) => (
                                <tr key={s.id}>
                                    <td><Link href={components.show(s.id)} className="hover:underline"><PaintSetLabel set={s} /></Link><div className="font-mono text-xs text-muted-foreground">{s.code} · {rs(s.unit_cost)} each</div></td>
                                    <td className={cn('text-right font-medium tabular-nums', s.stock <= s.reorder_level && 'text-brand-coral')}>{num(s.stock, 0)}</td>
                                    <td className="text-right text-xs tabular-nums">{s.colours.map((c) => `${num(c.ml, 1)} ml ${c.name}`).join(', ')}</td>
                                    <td className="text-right tabular-nums">{s.from_paint.can_make === null ? '-' : num(s.from_paint.can_make, 0)}{s.from_paint.limit && <div className="text-xs text-muted-foreground">{s.from_paint.limit}</div>}</td>
                                    <td className="text-right tabular-nums">{num(s.sold_month, 0)} / {num(s.sold, 0)}</td>
                                    <td className="text-right"><Button size="sm" onClick={() => { made.reset(); made.clearErrors(); setMaking(s); }}>Made sets</Button></td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>

                <Panel title="Paint colours" flush>
                    <Table>
                        <thead><tr><th>Colour</th><th className="text-right">Filled pots in sets</th><th className="text-right">Paint left</th><th className="text-right">Used this month</th><th className="text-right">Swatch</th></tr></thead>
                        <tbody>
                            {colours.length === 0 && <EmptyRow colSpan={5}>No paint colours yet. Add one, or put a material in the Paint set section.</EmptyRow>}
                            {colours.map((c) => (
                                <tr key={c.id}>
                                    <td><ColourDots colours={[c]} className="mr-2" />{c.name}</td>
                                    <td className="text-right font-medium tabular-nums">{num(c.pots, 0)}</td>
                                    <td className={cn('text-right tabular-nums', c.stock <= c.reorder_level && 'text-brand-coral')}>{num(c.stock, 1)} {c.unit}</td>
                                    <td className="text-right tabular-nums">{num(c.used_this_month, 1)} {c.unit}</td>
                                    <td className="text-right">
                                        <input
                                            type="color"
                                            aria-label={`Swatch for ${c.name}`}
                                            defaultValue={c.colour ?? '#cccccc'}
                                            className="h-7 w-10 cursor-pointer rounded border bg-transparent"
                                            onBlur={(e) => e.target.value !== c.colour && router.patch(paintColours.update.url(c.id), { colour: e.target.value }, { preserveScroll: true })}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal
                open={modal === 'set'}
                onOpenChange={close}
                title="New paint set"
                description="Pick how many pots you cut and the colour of each pot. Making sets later takes this paint out of stock."
                submitLabel="Add paint set"
                processing={newSet.processing}
                onSubmit={() => {
                    newSet.transform((d) => ({ colours: d.colours, ml_per_pot: d.ml_per_pot, pot_material_id: d.pot_material_id || null, name: d.name || null }));
                    newSet.post(paintSets.store.url(), { preserveScroll: true, onSuccess: () => setModal(null) });
                }}
            >
                <Field label="Pots in the set">
                    <div className="flex gap-2">
                        {potCounts.map((n) => (
                            <Button key={n} type="button" variant={newSet.data.pots === String(n) ? 'default' : 'outline'} size="sm" onClick={() => setPots(String(n))}>{n} pot</Button>
                        ))}
                    </div>
                </Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    {newSet.data.colours.map((value, i) => (
                        <Field key={i} label={`Pot ${i + 1}`} error={errors[`colours.${i}`]}>
                            <div className="flex items-center gap-2">
                                <ColourDots colours={[{ name: '', colour: colourById(value)?.colour ?? null }]} />
                                <NativeSelect value={value} onChange={(e) => newSet.setData('colours', newSet.data.colours.map((c, j) => (j === i ? e.target.value : c)))}>
                                    <option value="">Choose colour...</option>
                                    {colours.map((c) => <option key={c.id} value={c.id}>{c.short_name}</option>)}
                                </NativeSelect>
                            </div>
                        </Field>
                    ))}
                </div>
                {errors.colours && <p className="text-sm text-red-600">{errors.colours}</p>}
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Paint per pot (ml)" error={newSet.errors.ml_per_pot}><NumberInput value={newSet.data.ml_per_pot} onChange={(e) => newSet.setData('ml_per_pot', e.target.value)} /></Field>
                    <Field label="Empty pot (optional)" error={newSet.errors.pot_material_id}>
                        <NativeSelect value={newSet.data.pot_material_id} onChange={(e) => newSet.setData('pot_material_id', e.target.value)}>
                            <option value="">Don't count pots</option>
                            {potMaterials.map((m) => <option key={m.id} value={m.id}>{m.name} ({num(m.stock, 0)} {m.unit})</option>)}
                        </NativeSelect>
                    </Field>
                </div>
                <Field label="Name (optional)" error={newSet.errors.name}><Input placeholder="Made from the colours if left empty" value={newSet.data.name} onChange={(e) => newSet.setData('name', e.target.value)} /></Field>
            </FormModal>

            <FormModal
                open={modal === 'colour'}
                onOpenChange={close}
                title="Add colour"
                description="A paint colour is a material. Buy it through Purchases to add paint stock."
                submitLabel="Add colour"
                processing={newColour.processing}
                onSubmit={() => newColour.post(paintColours.store.url(), { preserveScroll: true, onSuccess: () => setModal(null) })}
            >
                <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                    <Field label="Name" error={newColour.errors.name}><Input placeholder="Paint - Red" value={newColour.data.name} onChange={(e) => newColour.setData('name', e.target.value)} /></Field>
                    <Field label="Swatch" error={newColour.errors.colour}><input type="color" value={newColour.data.colour} onChange={(e) => newColour.setData('colour', e.target.value)} className="h-9 w-14 cursor-pointer rounded border bg-transparent" /></Field>
                    <Field label="Unit" error={newColour.errors.unit}><Input className="w-20" value={newColour.data.unit} onChange={(e) => newColour.setData('unit', e.target.value)} /></Field>
                </div>
            </FormModal>

            <FormModal
                open={making !== null}
                onOpenChange={(open) => !open && setMaking(null)}
                title={making ? `Made sets: ${making.name}` : 'Made sets'}
                description="How many sets you filled and cut. The set count goes up and the paint (and pots) used come out of stock now."
                submitLabel="Add to stock"
                processing={made.processing}
                onSubmit={() => making && made.post(stock.make.url(making.id), { preserveScroll: true, onSuccess: () => setMaking(null) })}
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Sets made" error={made.errors.qty}><NumberInput autoFocus value={made.data.qty} onChange={(e) => made.setData('qty', e.target.value)} /></Field>
                    <Field label="Date" error={made.errors.made_on}><Input type="date" value={made.data.made_on} onChange={(e) => made.setData('made_on', e.target.value)} /></Field>
                </div>
                {making && Number(made.data.qty) > 0 && (
                    <div className="rounded-md border p-3 text-sm">
                        <div className="mb-1 text-xs font-medium text-muted-foreground">Paint used</div>
                        {making.colours.map((c) => {
                            const left = colours.find((x) => x.id === c.id)?.stock ?? 0;
                            const use = c.ml * Number(made.data.qty);

                            return (
                                <div key={c.id} className={cn('flex justify-between tabular-nums', use > left && 'text-brand-coral')}>
                                    <span><ColourDots colours={[c]} className="mr-2" />{c.name}</span>
                                    <span>{num(use, 1)} ml of {num(left, 1)} ml{use > left ? ' (not enough)' : ''}</span>
                                </div>
                            );
                        })}
                    </div>
                )}
                <Field label="Note (optional)"><Input value={made.data.note} onChange={(e) => made.setData('note', e.target.value)} /></Field>
            </FormModal>
        </>
    );
}

PaintSets.layout = { breadcrumbs: [{ title: 'Paint sets', href: paintSets.index() }] };
