import { Head, router, useForm } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EmptyRow, NativeSelect, NumberInput, Page, PageHeader, Panel, Stat, Table, num, rs } from '@/components/ops';
import { reasonTone } from '@/components/stock-actions';
import * as stockChecks from '@/routes/stock-checks';

type Line = {
    id: number; item_type: string; name: string; unit: string; system_qty: number; counted_qty: number | null;
    unit_cost: number; reason: string | null; note: string | null; difference: number | null;
};
type Check = { id: number; checked_on: string; scope: string; status: string; notes: string | null; completed_at: string | null };
type Summary = { lines: number; counted: number; missing_value: number; found_value: number };
type Entry = { counted_qty: string; reason: string; note: string };

const TYPE_LABELS: Record<string, string> = { material: 'Materials', component: 'Parts', option: 'Packed kits' };

export default function StockCheckShow({ check, lines, summary, reasons }: { check: Check; lines: Line[]; summary: Summary; reasons: string[] }) {
    const draft = check.status === 'draft';
    const form = useForm<{ lines: Record<number, Entry> }>({
        lines: Object.fromEntries(lines.map((l) => [l.id, { counted_qty: l.counted_qty === null ? '' : String(l.counted_qty), reason: l.reason ?? '', note: l.note ?? '' }])),
    });
    const setEntry = (id: number, key: keyof Entry, value: string) => form.setData('lines', { ...form.data.lines, [id]: { ...form.data.lines[id], [key]: value } });

    const diffOf = (l: Line): number | null => {
        const counted = form.data.lines[l.id]?.counted_qty ?? '';

        return counted === '' ? null : Math.round((Number(counted) - l.system_qty) * 1000) / 1000;
    };
    const liveMissing = lines.reduce((s, l) => s + Math.min(diffOf(l) ?? 0, 0) * l.unit_cost, 0);
    const liveFound = lines.reduce((s, l) => s + Math.max(diffOf(l) ?? 0, 0) * l.unit_cost, 0);
    const counted = lines.filter((l) => diffOf(l) !== null).length;
    const save = (then?: () => void) => form.put(stockChecks.update.url(check.id), { preserveScroll: true, onSuccess: then });

    const complete = () => {
        if (confirm('Complete this check? Differences will be written to stock as missing / found and the check will be locked.')) {
            save(() => router.post(stockChecks.complete.url(check.id), {}, { preserveScroll: true }));
        }
    };

    let lastType = '';

    return (
        <>
            <Head title={`Stock check ${check.checked_on.slice(0, 10)}`} />
            <Page>
                <PageHeader title={`Stock check ${check.checked_on.slice(0, 10)}`} description={check.notes ?? 'Type what you really counted. Leave blank to skip an item.'}>
                    <Badge variant={draft ? 'outline' : 'secondary'} className="capitalize">{check.status}</Badge>
                    {draft && (
                        <>
                            <DeleteButton url={stockChecks.destroy.url(check.id)} message="Delete this draft stock check?" />
                            <Button variant="outline" disabled={form.processing} onClick={() => save()}>Save counts</Button>
                            <Button disabled={form.processing} onClick={complete}>Complete check</Button>
                        </>
                    )}
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Counted" value={`${draft ? counted : summary.counted} / ${summary.lines}`} />
                    <Stat label="Missing value" value={rs(draft ? -liveMissing : summary.missing_value)} tone="bad" />
                    <Stat label="Found value" value={rs(draft ? liveFound : summary.found_value)} tone="good" />
                    <Stat label="Net" value={rs(draft ? liveFound + liveMissing : summary.found_value - summary.missing_value)} />
                </div>

                <Panel flush>
                    <Table>
                        <thead><tr><th>Item</th><th className="text-right">System</th><th className="w-32">Counted</th><th className="text-right">Difference</th><th className="text-right">Value</th><th className="w-36">Reason</th><th>Note</th></tr></thead>
                        <tbody>
                            {lines.length === 0 && <EmptyRow colSpan={7}>Nothing to count in this scope.</EmptyRow>}
                            {lines.map((l) => {
                                const entry = form.data.lines[l.id];
                                const diff = draft ? diffOf(l) : l.difference;
                                const header = l.item_type !== lastType;
                                lastType = l.item_type;

                                return [
                                    header && <tr key={`h-${l.item_type}`} className="bg-muted/40"><td colSpan={7} className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">{TYPE_LABELS[l.item_type] ?? l.item_type}</td></tr>,
                                    <tr key={l.id}>
                                        <td className="font-medium">{l.name}</td>
                                        <td className="text-right tabular-nums">{num(l.system_qty, 3)} {l.unit}</td>
                                        <td>{draft ? <NumberInput value={entry.counted_qty} onChange={(e) => setEntry(l.id, 'counted_qty', e.target.value)} /> : <span className="tabular-nums">{l.counted_qty === null ? '-' : num(l.counted_qty, 3)}</span>}</td>
                                        <td className={`text-right font-medium tabular-nums ${diff ? reasonTone(diff) : 'text-muted-foreground'}`}>{diff === null ? '-' : diff === 0 ? 'OK' : `${diff > 0 ? '+' : ''}${num(diff, 3)}`}</td>
                                        <td className="text-right tabular-nums">{diff ? rs(diff * l.unit_cost) : '-'}</td>
                                        <td>
                                            {draft ? (
                                                <NativeSelect value={entry.reason} onChange={(e) => setEntry(l.id, 'reason', e.target.value)} disabled={!diff}>
                                                    <option value="">{diff && diff > 0 ? 'Found' : 'Missing'}</option>
                                                    {reasons.map((r) => <option key={r} value={r} className="capitalize">{r}</option>)}
                                                </NativeSelect>
                                            ) : <span className="capitalize">{diff ? (l.reason ?? (diff < 0 ? 'missing' : 'found')) : ''}</span>}
                                        </td>
                                        <td>{draft ? <Input value={entry.note} onChange={(e) => setEntry(l.id, 'note', e.target.value)} disabled={!diff} /> : <span className="text-muted-foreground">{l.note}</span>}</td>
                                    </tr>,
                                ];
                            })}
                        </tbody>
                    </Table>
                </Panel>
            </Page>
        </>
    );
}

StockCheckShow.layout = { breadcrumbs: [{ title: 'Stock checks', href: stockChecks.index() }] };
