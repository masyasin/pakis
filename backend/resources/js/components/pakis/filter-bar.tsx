import { ArrowUpDown, RotateCcw, Search, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { FilterSheet } from '@/components/pakis/filter-sheet';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { UseListing } from '@/hooks/use-listing';
import type { ListingSpec, ListingState } from '@/types/listing';

type Props = {
    spec: ListingSpec;
    state: ListingState;
    listing: UseListing;
    canReset: boolean;
    actions?: ReactNode;
};

/** Toolbar daftar: cari, filter, urutkan, reset (07 §3.1). */
export function FilterBar({ spec, state, listing, canReset, actions }: Props) {
    const sortKey = state.sort.replace(/^-/, '');
    const direction = state.sort.startsWith('-') ? 'desc' : 'asc';

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-48 flex-1 sm:max-w-sm">
                <Search
                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden
                />
                <Input
                    type="search"
                    role="searchbox"
                    aria-label={spec.search_placeholder}
                    placeholder={spec.search_placeholder}
                    className="pr-8 pl-8"
                    value={listing.search}
                    maxLength={100}
                    onChange={(e) => listing.setSearch(e.target.value)}
                />
                {listing.search !== '' && (
                    <button
                        type="button"
                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                        aria-label="Hapus pencarian"
                        onClick={listing.clearSearch}
                    >
                        <X className="size-4" aria-hidden />
                    </button>
                )}
            </div>

            <FilterSheet
                spec={spec}
                state={state}
                onApply={listing.applyFilters}
                onReset={listing.reset}
            />

            {spec.sorts.length > 0 && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button type="button" variant="outline" size="sm">
                            <ArrowUpDown className="size-4" aria-hidden />
                            Urutkan
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-52">
                        <DropdownMenuLabel>Urutkan menurut</DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            value={sortKey}
                            onValueChange={(key) =>
                                listing.setSort(
                                    direction === 'desc' ? `-${key}` : key,
                                )
                            }
                        >
                            {spec.sorts.map((s) => (
                                <DropdownMenuRadioItem
                                    key={s.key}
                                    value={s.key}
                                >
                                    {s.label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuRadioGroup
                            value={direction}
                            onValueChange={(dir) =>
                                listing.setSort(
                                    dir === 'desc' ? `-${sortKey}` : sortKey,
                                )
                            }
                        >
                            <DropdownMenuRadioItem value="asc">
                                Naik (A–Z, lama–baru)
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="desc">
                                Turun (Z–A, baru–lama)
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            {canReset && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={listing.reset}
                >
                    <RotateCcw className="size-4" aria-hidden />
                    Reset
                </Button>
            )}

            {actions && (
                <div className="ml-auto flex items-center gap-2">{actions}</div>
            )}
        </div>
    );
}
