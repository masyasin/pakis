import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    defaultFilters,
    nextSort,
    toQueryParams,
    withDependentsCleared,
} from '@/lib/listing-query';
import type { ListingQueryState } from '@/lib/listing-query';
import type { FilterValue, Listing } from '@/types/listing';

/** 07 §3.2.2 */
export const SEARCH_DEBOUNCE_MS = 400;
const SEARCH_MIN_LENGTH = 2;

const storageKey = (listingKey: string) =>
    `pakis.listing.${listingKey}.per_page`;

function readStoredPerPage(listingKey: string): number | null {
    try {
        const raw = window.localStorage.getItem(storageKey(listingKey));

        return raw === null ? null : Number(raw);
    } catch {
        return null;
    }
}

function storePerPage(listingKey: string, perPage: number): void {
    try {
        window.localStorage.setItem(storageKey(listingKey), String(perPage));
    } catch {
        // Mode privat / storage penuh: preferensi tidak diingat, daftar tetap jalan.
    }
}

/**
 * State daftar yang tersinkron ke URL (07 §3.2.5). Sumber kebenaran tetap prop `listing` dari
 * server; hook ini hanya mengirim perubahan lewat Inertia lalu server membalas state baru.
 */
export function useListing(listing: Listing) {
    const { spec, state, pagination } = listing;
    const [search, setSearchInput] = useState(state.q);
    const [isLoading, setIsLoading] = useState(false);
    const lastSentQ = useRef(state.q);

    const current = useCallback(
        (): ListingQueryState => ({
            q: state.q,
            sort: state.sort,
            filters: state.filters,
            page: pagination.page,
            perPage: pagination.per_page,
        }),
        [state, pagination],
    );

    const visit = useCallback(
        (patch: Partial<ListingQueryState>, replace = false) => {
            const next = { ...current(), ...patch };
            lastSentQ.current = next.q;

            router.get(
                window.location.pathname,
                toQueryParams(spec, next) as Record<string, never>,
                {
                    preserveState: true,
                    preserveScroll: true,
                    // Mengetik tidak menumpuk riwayat; filter/sort/halaman bisa di-"Back".
                    replace,
                    only: ['rows', 'listing'],
                    onStart: () => setIsLoading(true),
                    onFinish: () => setIsLoading(false),
                },
            );
        },
        [current, spec],
    );

    // Server bisa mengubah q (back browser, normalisasi): samakan isi kotak cari.
    useEffect(() => {
        if (state.q !== lastSentQ.current) {
            lastSentQ.current = state.q;
            setSearchInput(state.q);
        }
    }, [state.q]);

    // Pencarian: debounce 400 ms, kurang dari 2 karakter tidak dikirim (kecuali dikosongkan).
    useEffect(() => {
        const trimmed = search.trim();
        if (trimmed === lastSentQ.current.trim()) {
            return;
        }
        if (trimmed.length > 0 && trimmed.length < SEARCH_MIN_LENGTH) {
            return;
        }

        const timer = window.setTimeout(
            () => visit({ q: trimmed, page: 1 }, true),
            SEARCH_DEBOUNCE_MS,
        );

        return () => window.clearTimeout(timer);
    }, [search, visit]);

    // Per halaman yang diingat (07 §3.2.6), dipakai bila URL tidak menentukan.
    useEffect(() => {
        const stored = readStoredPerPage(spec.key);
        const urlHasPerPage = new URLSearchParams(window.location.search).has(
            'per_page',
        );

        if (
            stored !== null &&
            !urlHasPerPage &&
            stored !== pagination.per_page &&
            spec.per_page_options.includes(stored)
        ) {
            visit({ perPage: stored, page: 1 }, true);
        }
        // Hanya saat daftar pertama kali dibuka.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [spec.key]);

    return {
        search,
        setSearch: setSearchInput,
        clearSearch: () => setSearchInput(''),
        isLoading,
        setFilter: (key: string, value: FilterValue | null) => {
            const filters = { ...state.filters };
            if (value === null) {
                delete filters[key];
            } else {
                filters[key] = value;
            }
            visit({ filters, page: 1 });
        },
        /** Terapkan sekaligus semua filter dari panel (tombol "Terapkan"). */
        applyFilters: (filters: Record<string, FilterValue>) =>
            visit({ filters, page: 1 }),
        removeFilter: (key: string) => {
            // Filter berjenjang: hapus induk ikut menghapus anaknya (provinsi → kab/kota → kecamatan).
            const filters = withDependentsCleared(
                spec,
                state.filters,
                key,
                null,
            );
            visit({ filters, page: 1 });
        },
        reset: () => {
            setSearchInput('');
            visit({
                q: '',
                sort: spec.default_sort,
                filters: defaultFilters(spec),
                page: 1,
            });
        },
        /** Seperti `reset`, tetapi filter tersembunyi (mis. tab halaman) tetap dipertahankan. */
        resetKeeping: (keep: Record<string, FilterValue>) => {
            setSearchInput('');
            visit({
                q: '',
                sort: spec.default_sort,
                filters: { ...defaultFilters(spec), ...keep },
                page: 1,
            });
        },
        toggleSort: (key: string) =>
            visit({ sort: nextSort(state.sort, key), page: 1 }),
        setSort: (sort: string) => visit({ sort, page: 1 }),
        setPage: (page: number) => visit({ page }),
        setPerPage: (perPage: number) => {
            storePerPage(spec.key, perPage);
            visit({ perPage, page: 1 });
        },
    };
}

export type UseListing = ReturnType<typeof useListing>;
