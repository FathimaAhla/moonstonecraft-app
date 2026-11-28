import { NativeSelect, num } from '@/components/ops';

export type PackSizeRef = { id: number; name: string; size: number };

/**
 * Picks which size was bought (75 ml, 500 ml, 1 L...). Blank means type the unit by hand.
 */
export function PackSizeSelect({ packSizes, unit, value, onPick }: { packSizes: PackSizeRef[]; unit: string; value: string; onPick: (packSize: PackSizeRef | null) => void }) {
    return (
        <NativeSelect aria-label="Pack size" value={value} onChange={(e) => onPick(packSizes.find((p) => String(p.id) === e.target.value) ?? null)}>
            <option value="">Other size...</option>
            {packSizes.map((p) => (
                <option key={p.id} value={p.id}>{p.name} ({num(p.size, 3)} {unit})</option>
            ))}
        </NativeSelect>
    );
}
