import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { FilterField } from '@/components/pakis/filter-fields';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { countActiveFilters, withDependentsCleared } from '@/lib/listing-query';
import type { FilterValue, ListingSpec, ListingState } from '@/types/listing';

type Props = {
    spec: ListingSpec;
    state: ListingState;
    onApply: (filters: Record<string, FilterValue>) => void;
    onReset: () => void;
};

/**
 * Panel filter: samping di desktop, bottom sheet di HP (07 §3.2.3). Perubahan ditampung di draft
 * dan baru dikirim saat "Terapkan", agar mengetik tanggal tidak memicu banyak request.
 */
export function FilterSheet({ spec, state, onApply, onReset }: Props) {
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<Record<string, FilterValue>>(
        state.filters,
    );
    const active = countActiveFilters(state.filters);

    if (spec.filters.length === 0) {
        return null;
    }

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                // Buka ulang = mulai dari filter yang sedang berlaku.
                if (next) {
                    setDraft(state.filters);
                }
                setOpen(next);
            }}
        >
            <SheetTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <SlidersHorizontal className="size-4" aria-hidden />
                    Filter{active > 0 ? ` (${active})` : ''}
                </Button>
            </SheetTrigger>
            <SheetContent
                side={isMobile ? 'bottom' : 'right'}
                className={isMobile ? 'max-h-[85svh]' : 'w-full sm:max-w-sm'}
            >
                <SheetHeader>
                    <SheetTitle>Filter</SheetTitle>
                    <SheetDescription>
                        Persempit daftar. Filter diterapkan setelah menekan
                        Terapkan.
                    </SheetDescription>
                </SheetHeader>

                <div className="grid gap-5 overflow-y-auto px-4">
                    {spec.filters.map((filter) => {
                        const parentKey =
                            'depends_on' in filter
                                ? filter.depends_on
                                : undefined;
                        const parentSpec = spec.filters.find(
                            (f) => f.key === parentKey,
                        );

                        return (
                            <FilterField
                                key={filter.key}
                                filter={filter}
                                value={draft[filter.key]}
                                error={state.invalid_filters[filter.key]}
                                parent={
                                    parentSpec
                                        ? {
                                              value: draft[parentSpec.key],
                                              label: parentSpec.label,
                                          }
                                        : undefined
                                }
                                onChange={(value) =>
                                    setDraft((current) =>
                                        withDependentsCleared(
                                            spec,
                                            current,
                                            filter.key,
                                            value,
                                        ),
                                    )
                                }
                            />
                        );
                    })}
                </div>

                <SheetFooter className="flex-row gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="flex-1"
                        onClick={() => {
                            setOpen(false);
                            onReset();
                        }}
                    >
                        Reset
                    </Button>
                    <Button
                        type="button"
                        className="flex-1"
                        onClick={() => {
                            setOpen(false);
                            onApply(draft);
                        }}
                    >
                        Terapkan
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
