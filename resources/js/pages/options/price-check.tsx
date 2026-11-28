import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Page, PageHeader, Panel, num, rs } from '@/components/ops';
import { cn } from '@/lib/utils';
import { priceCheck } from '@/routes';
import * as options from '@/routes/options';

type Product = { id: number; code: string; name: string; kit_id: number; retail_price: number; wholesale_price: number | null; stock: number; figures: number; pots: number; brushes: number; can_make: number | null };
type Named = { id: number; name: string };
type CountKey = 'figures' | 'pots' | 'brushes';
type Picked = Record<CountKey, number | null>;

const COUNT_ROWS: { key: CountKey; label: string }[] = [
    { key: 'figures', label: 'Figures' },
    { key: 'pots', label: 'Pots' },
    { key: 'brushes', label: 'Brushes' },
];

const empty: Picked = { figures: null, pots: null, brushes: null };

/**
 * Read-only price lookup: pick a kit and its counts to see the price. Nothing is saved.
 */
export default function PriceCheck({ products, categories, kits }: { products: Product[]; categories: Named[]; kits: (Named & { category_id: number | null })[] }) {
    const [categoryId, setCategoryId] = useState<number | null>(categories.find((c) => kits.some((k) => k.category_id === c.id))?.id ?? null);
    const [kitId, setKitId] = useState<number | null>(null);
    const [picked, setPicked] = useState<Picked>(empty);

    const kitList = kits.filter((k) => categoryId === null || k.category_id === categoryId);
    const inKit = products.filter((p) => p.kit_id === kitId);
    const matches = (p: Product, except?: CountKey) => COUNT_ROWS.every(({ key }) => key === except || picked[key] === null || p[key] === picked[key]);
    const results = inKit.filter((p) => matches(p));
    const usesCounts = inKit.some((p) => p.figures > 0 || p.pots > 0 || p.brushes > 0);

    const chooseKit = (id: number) => {
        setKitId(id);
        setPicked(empty);
    };

    const toggle = (key: CountKey, value: number) => setPicked((prev) => ({ ...prev, [key]: prev[key] === value ? null : value }));

    return (
        <>
            <Head title="Price check" />
            <Page>
                <PageHeader title="Price check" description="Look up a price quickly. Nothing is saved and no sale is started." />

                <Panel>
                    <div className="space-y-4">
                        <ChipRow label="Category">
                            {categories.filter((c) => kits.some((k) => k.category_id === c.id)).map((c) => (
                                <Chip key={c.id} active={categoryId === c.id} onClick={() => { setCategoryId(c.id); setKitId(null); setPicked(empty); }}>{c.name}</Chip>
                            ))}
                        </ChipRow>
                        <ChipRow label="Kit">
                            {kitList.length === 0 && <span className="text-sm text-muted-foreground">No kits in this category.</span>}
                            {kitList.map((k) => <Chip key={k.id} active={kitId === k.id} onClick={() => chooseKit(k.id)}>{k.name}</Chip>)}
                        </ChipRow>

                        {kitId !== null && usesCounts && COUNT_ROWS.map(({ key, label }) => {
                            const values = [...new Set(inKit.map((p) => p[key]))].sort((a, b) => a - b);

                            if (values.length <= 1 && values[0] === 0) {
                                return null;
                            }

                            return (
                                <ChipRow key={key} label={label}>
                                    {values.map((value) => {
                                        const possible = inKit.some((p) => p[key] === value && matches(p, key));

                                        return <Chip key={value} active={picked[key] === value} disabled={!possible} onClick={() => toggle(key, value)}>{value}</Chip>;
                                    })}
                                </ChipRow>
                            );
                        })}
                    </div>
                </Panel>

                {kitId === null ? (
                    <p className="text-sm text-muted-foreground">Pick a kit to see its prices.</p>
                ) : (
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">{results.length === 1 ? 'This product matches:' : `${results.length} products match. Pick more counts to narrow it down.`}</p>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {results.map((p) => <PriceCard key={p.id} product={p} highlight={results.length === 1} />)}
                        </div>
                    </div>
                )}
            </Page>
        </>
    );
}

function ChipRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5 sm:flex-row sm:items-start">
            <span className="w-20 shrink-0 pt-1.5 text-sm font-medium text-muted-foreground">{label}</span>
            <div className="flex flex-wrap gap-2">{children}</div>
        </div>
    );
}

function Chip({ active, disabled = false, onClick, children }: { active: boolean; disabled?: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'min-w-10 rounded-md border px-3 py-1.5 text-sm font-medium tabular-nums transition-colors',
                active ? 'border-brand-teal bg-brand-teal text-white' : 'hover:bg-muted',
                disabled && 'cursor-not-allowed opacity-35 hover:bg-transparent',
            )}
        >
            {children}
        </button>
    );
}

function PriceCard({ product, highlight }: { product: Product; highlight: boolean }) {
    const contents = [product.figures && `${product.figures} figures`, product.pots && `${product.pots} pots`, product.brushes && `${product.brushes} brushes`].filter(Boolean).join(' · ');

    return (
        <div className={cn('rounded-lg border bg-card p-4 shadow-xs', highlight && 'border-brand-teal ring-1 ring-brand-teal')}>
            <Link href={options.show(product.id)} className="font-mono text-sm hover:underline">{product.code}</Link>
            <div className="text-sm text-muted-foreground">{product.name}</div>
            {contents && <div className="mt-1 text-xs text-muted-foreground">{contents}</div>}
            <div className="mt-3 grid grid-cols-2 gap-2">
                <div>
                    <div className="text-xs text-muted-foreground">Retail</div>
                    <div className="text-2xl font-semibold tabular-nums">{rs(product.retail_price)}</div>
                </div>
                <div>
                    <div className="text-xs text-muted-foreground">Wholesale</div>
                    <div className="text-2xl font-semibold tabular-nums">{product.wholesale_price !== null ? rs(product.wholesale_price) : '-'}</div>
                </div>
            </div>
            <div className="mt-3 flex justify-between border-t pt-2 text-xs text-muted-foreground tabular-nums">
                <span>Ready kits: {num(product.stock, 0)}</span>
                <span>Can make: {product.can_make === null ? '-' : num(product.can_make, 0)}</span>
            </div>
        </div>
    );
}

PriceCheck.layout = { breadcrumbs: [{ title: 'Price check', href: priceCheck() }] };
