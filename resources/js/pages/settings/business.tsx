import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, NativeSelect, NumberInput, Textarea } from '@/components/ops';
import { edit } from '@/routes/business';
import * as business from '@/routes/business';

export default function Business({ settings }: { settings: Record<string, string> }) {
    const form = useForm(settings);
    const text = (key: string, label: string) => (
        <Field label={label} error={form.errors[key]}><Input value={form.data[key] ?? ''} onChange={(e) => form.setData(key, e.target.value)} /></Field>
    );
    const area = (key: string, label: string) => (
        <Field label={label} error={form.errors[key]}><Textarea value={form.data[key] ?? ''} onChange={(e) => form.setData(key, e.target.value)} /></Field>
    );

    return (
        <>
            <Head title="Business settings" />
            <div className="space-y-6">
                <Heading variant="small" title="Business" description="Details printed on receipts and invoices, and pricing defaults" />
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(business.update.url(), { preserveScroll: true });
                    }}
                >
                    {text('business_name', 'Business name')}
                    {area('business_address', 'Address')}
                    {text('business_phone', 'Phone')}
                    {text('business_email', 'Email')}
                    {area('bank_details', 'Bank details (printed on bank invoices)')}
                    {text('invoice_footer', 'Invoice footer')}
                    {text('receipt_footer', 'Receipt footer')}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Receipt width" error={form.errors.receipt_width_mm}>
                            <NativeSelect value={form.data.receipt_width_mm} onChange={(e) => form.setData('receipt_width_mm', e.target.value)}>
                                <option value="80">80 mm</option>
                                <option value="58">58 mm</option>
                            </NativeSelect>
                        </Field>
                        <Field label="After saving a sale" error={form.errors.default_print_action}>
                            <NativeSelect value={form.data.default_print_action} onChange={(e) => form.setData('default_print_action', e.target.value)}>
                                <option value="receipt">Open and print the receipt</option>
                                <option value="invoice_page">Open the invoice page</option>
                                <option value="none">Start a new sale</option>
                            </NativeSelect>
                        </Field>
                        {text('invoice_prefix', 'Invoice prefix')}
                        <Field label="Minimum margin % (red warning below this)" error={form.errors.min_margin_pct}>
                            <NumberInput value={form.data.min_margin_pct} onChange={(e) => form.setData('min_margin_pct', e.target.value)} />
                        </Field>
                        <Field label="Round suggested prices up to (Rs.)" error={form.errors.price_step}>
                            <NumberInput value={form.data.price_step} onChange={(e) => form.setData('price_step', e.target.value)} />
                        </Field>
                    </div>
                    <Button disabled={form.processing}>Save</Button>
                </form>
            </div>
        </>
    );
}

Business.layout = { breadcrumbs: [{ title: 'Business settings', href: edit() }] };
