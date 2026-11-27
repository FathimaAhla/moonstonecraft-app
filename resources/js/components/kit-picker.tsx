import { Check } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { NumberInput, num, rs } from '@/components/ops';
import { cn } from '@/lib/utils';

export const KIT_GROUPS = [
    { key: 'figure', label: 'Figure' },
    { key: 'paint', label: 'Paint set' },
    { key: 'brush', label: 'Brush' },
    { key: 'packing', label: 'Packing' },
    { key: 'other', label: 'Other items' },
] as const;

export type KitItem = {
    kind: 'component' | 'material';
    id: number;
    group: string;
    label: string;
    unit: string;
    unit_cost: number;
    measured_qty?: number | null;
};

export type PartRow = { kind: 'component' | 'material'; id: number; qty: string };

/**
 * The kit builder: one section per slot, items shown as buttons. Click to put an item in the kit,
 * click again to take it out, and set the quantity right under it.
 */
export function KitPicker({ items, parts, onChange, errors }: { items: KitItem[]; parts: PartRow[]; onChange: (parts: PartRow[]) => void; errors: Partial<Record<string, string>> }) {
    const [search, setSearch] = useState('');
    const indexOf = (item: KitItem) => parts.findIndex((p) => p.kind === item.kind && p.id === item.id);
    const needle = search.trim().toLowerCase();

    const toggle = (item: KitItem) => {
        const index = indexOf(item);
        onChange(index >= 0 ? parts.filter((_, i) => i !== index) : [...parts, { kind: item.kind, id: item.id, qty: '1' }]);
    };

    const setQty = (index: number, qty: string) => onChange(parts.map((p, i) => (i === index ? { ...p, qty } : p)));

    return (
        <div className="divide-y">
            <div className="p-3">
                <Input aria-label="Search items" placeholder="Search items..." value={search} onChange={(e) => setSearch(e.target.value)} className="sm:max-w-xs" />
            </div>
            {KIT_GROUPS.map((group) => {
                const inGroup = items.filter((item) => item.group === group.key);
                const selected = inGroup.filter((item) => indexOf(item) >= 0);
                const visible = inGroup.filter((item) => !needle || item.label.toLowerCase().includes(needle) || indexOf(item) >= 0);

                if (visible.length === 0) {
                    return null;
                }

                return (
                    <section key={group.key} className="p-3">
                        <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold">
                            {group.label}
                            <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', selected.length ? 'bg-brand-teal-tint text-brand-teal' : 'bg-muted text-muted-foreground')}>
                                {selected.length ? `${selected.length} selected` : 'none'}
                            </span>
                        </h3>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {visible.map((item) => {
                                const index = indexOf(item);
                                const isSelected = index >= 0;

                                return (
                                    <div key={`${item.kind}:${item.id}`} className={cn('rounded-md border', isSelected && 'border-brand-teal bg-brand-teal-tint/40 ring-1 ring-brand-teal')}>
                                        <button type="button" aria-pressed={isSelected} onClick={() => toggle(item)} className="flex w-full items-start gap-2 p-2.5 text-left text-sm">
                                            <span className={cn('mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border', isSelected && 'border-brand-teal bg-brand-teal text-white')}>
                                                {isSelected && <Check className="size-3" />}
                                            </span>
                                            <span>
                                                <span className="block font-medium">{item.label}</span>
                                                <span className="block text-xs text-muted-foreground">{rs(item.unit_cost)} / {item.unit}</span>
                                            </span>
                                        </button>
                                        {isSelected && (
                                            <div className="flex flex-wrap items-center gap-2 border-t px-2.5 py-2 text-xs">
                                                <span>Qty</span>
                                                <NumberInput aria-label={`Quantity of ${item.label}`} className="h-8 w-20 text-right" value={parts[index].qty} onChange={(e) => setQty(index, e.target.value)} />
                                                <span className="text-muted-foreground">{item.unit}</span>
                                                <span className="ml-auto font-medium tabular-nums">{rs(item.unit_cost * (Number(parts[index].qty) || 0))}</span>
                                                {!!item.measured_qty && (
                                                    <button type="button" className="w-full text-left text-primary hover:underline" onClick={() => setQty(index, String(item.measured_qty))}>
                                                        Use real usage: {num(item.measured_qty, 3)} {item.unit} per kit
                                                    </button>
                                                )}
                                                {errors[`parts.${index}.qty`] && <p className="w-full text-red-600">Enter a quantity above 0.</p>}
                                                {errors[`parts.${index}.id`] && <p className="w-full text-red-600">{errors[`parts.${index}.id`]}</p>}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}
