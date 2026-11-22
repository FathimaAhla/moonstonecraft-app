import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EmptyRow, Field, FormModal, NumberInput, Page, PageHeader, Pagination, Panel, Table, rs, today } from '@/components/ops';
import type { Paginated } from '@/components/ops';
import * as expenses from '@/routes/expenses';

type Expense = { id: number; spent_on: string; category: string; description: string | null; amount: number; ref_id: number | null };

export default function Expenses({ expenses: page, categories }: { expenses: Paginated<Expense>; categories: string[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ spent_on: today(), category: '', description: '', amount: '' });

    return (
        <>
            <Head title="Expenses" />
            <Page>
                <PageHeader title="Expenses" description="Costs that are not materials: rent, ads, internet, purchase refreshments.">
                    <Button onClick={() => { form.reset('description', 'amount'); form.clearErrors(); setOpen(true); }}><Plus /> New expense</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Date</th><th>Category</th><th>Description</th><th className="text-right">Amount</th><th /></tr></thead>
                        <tbody>
                            {page.data.length === 0 && <EmptyRow colSpan={5}>No expenses yet.</EmptyRow>}
                            {page.data.map((e) => (
                                <tr key={e.id}>
                                    <td>{e.spent_on.slice(0, 10)}</td>
                                    <td className="font-medium">{e.category}</td>
                                    <td>{e.description ?? '-'}</td>
                                    <td className="text-right tabular-nums">{rs(e.amount)}</td>
                                    <td className="text-right">{!e.ref_id && <DeleteButton url={expenses.destroy.url(e.id)} />}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
                <Pagination links={page.links} />
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title="New expense" submitLabel="Add" processing={form.processing} onSubmit={() => form.post(expenses.store.url(), { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Date" error={form.errors.spent_on}><Input type="date" value={form.data.spent_on} onChange={(e) => form.setData('spent_on', e.target.value)} /></Field>
                    <Field label="Amount (Rs.)" error={form.errors.amount}><NumberInput value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></Field>
                </div>
                <Field label="Category" error={form.errors.category}>
                    <Input list="expense-categories" value={form.data.category} placeholder="Rent, Ads, Internet..." onChange={(e) => form.setData('category', e.target.value)} />
                    <datalist id="expense-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                </Field>
                <Field label="Description"><Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
            </FormModal>
        </>
    );
}

Expenses.layout = { breadcrumbs: [{ title: 'Expenses', href: expenses.index() }] };
