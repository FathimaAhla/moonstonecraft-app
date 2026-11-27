import { KIT_GROUPS } from '@/components/kit-picker';
import { NativeSelect } from '@/components/ops';

/**
 * Which section of the kit builder an item shows under. Blank guesses from the name.
 */
export function KitGroupSelect({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    return (
        <NativeSelect value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">Automatic (from the name)</option>
            {KIT_GROUPS.map((g) => <option key={g.key} value={g.key}>{g.label}</option>)}
        </NativeSelect>
    );
}
