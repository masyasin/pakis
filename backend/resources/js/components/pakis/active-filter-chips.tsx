import { X } from 'lucide-react';
import type { FilterChip } from '@/lib/listing-query';

type Props = {
    chips: FilterChip[];
    onRemove: (key: string) => void;
};

/** Chip filter aktif, termasuk filter default, masing-masing bisa dihapus (07 §3.2.3). */
export function ActiveFilterChips({ chips, onRemove }: Props) {
    if (chips.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-wrap gap-2" aria-label="Filter aktif">
            {chips.map((chip) => (
                <li
                    key={chip.key}
                    className="inline-flex items-center gap-1 rounded-full border bg-muted/60 py-0.5 pr-1 pl-3 text-xs"
                >
                    <span>{chip.label}</span>
                    <button
                        type="button"
                        className="rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label={`Hapus filter ${chip.label}`}
                        onClick={() => onRemove(chip.key)}
                    >
                        <X className="size-3.5" aria-hidden />
                    </button>
                </li>
            ))}
        </ul>
    );
}
