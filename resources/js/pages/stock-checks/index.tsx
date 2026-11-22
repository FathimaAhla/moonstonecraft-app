import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyRow, Field, FormModal, NativeSelect, Page, PageHeader, Panel, Table, Textarea, rs, today } from '@/components/ops';
import * as stockChecks from '@/routes/stock-checks';

type Check = {
    id: number; checked_on: string; scope: string; status: string; notes: string | null; completed_at: string | null;
    lines: number; counted: number; missing_value: number; found_value: number;
};

const SCOPE_LABELS: Record<string, string> = { all: 'Everything', materials: 'Materials (paint, pots, brushes…)', components: 'Parts (figures, paint pots)', kits: 'Packed kits' };

export default function StockCheckIndex({ checks, scopes }: { checks: Check[]; scopes: string[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ scope: 'all', checked_on: today(), notes: '' });

    return (
        <>
            <Head title="Stock checks" />
            <Page>
                <PageHeader title="Stock checks" description="Count what you really have. The difference from the system is saved as missing (short) or found (extra).">
                    <Button onClick={() => { form.reset(); setOpen(true); }}>New stock check</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Date</th><th>Scope</th><th>Status</th><th className="text-right">Counted</th><th className="text-right">Missing</th><th className="text-right">Found</th><th>Notes</th></tr></thead>
                        <tbody>
                            {checks.length === 0 && <EmptyRow colSpan={7}>No stock checks yet.</EmptyRow>}
                            {checks.map((c) => (
                                <tr key={c.id}>
                                    <td><Link href={stockChecks.show(c.id)} className="font-medium hover:underline">{c.checked_on.slice(0, 10)}</Link></td>
                                    <td>{SCOPE_LABELS[c.scope] ?? c.scope}</td>
                                    <td><Badge variant={c.status === 'draft' ? 'outline' : 'secondary'} className="capitalize">{c.status}</Badge></td>
                                    <td className="text-right tabular-nums">{c.counted} / {c.lines}</td>
                                    <td className="text-right text-brand-coral tabular-nums">{c.missing_value ? rs(c.missing_value) : '-'}</td>
                                    <td className="text-right text-brand-leaf tabular-nums">{c.found_value ? rs(c.found_value) : '-'}</td>
                                    <td className="max-w-64 truncate text-muted-foreground">{c.notes}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal
                open={open}
                onOpenChange={setOpen}
                title="New stock check"
                description="The system quantities are frozen now. Count soon after starting so sales do not mix in."
                submitLabel="Start counting"
                processing={form.processing}
                onSubmit={() => form.post(stockChecks.store.url())}
            >
                <Field label="What to count" error={form.errors.scope}>
                    <NativeSelect value={form.data.scope} onChange={(e) => form.setData('scope', e.target.value)}>
                        {scopes.map((s) => <option key={s} value={s}>{SCOPE_LABELS[s] ?? s}</option>)}
                    </NativeSelect>
                </Field>
                <Field label="Date" error={form.errors.checked_on}><Input type="date" value={form.data.checked_on} onChange={(e) => form.setData('checked_on', e.target.value)} /></Field>
                <Field label="Notes"><Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Month-end count" /></Field>
            </FormModal>
        </>
    );
}

StockCheckIndex.layout = { breadcrumbs: [{ title: 'Stock checks', href: stockChecks.index() }] };
