import type {
    DateRangeValue,
    FilterSpec,
    FilterValue,
    ListingSpec,
    NumberRangeValue,
} from '@/types/listing';

/** State daftar di sisi klien sebelum dikirim sebagai query string (05 §3.1). */
export type ListingQueryState = {
    q: string;
    sort: string;
    filters: Record<string, FilterValue>;
    page: number;
    perPage: number;
};

export type QueryParams = {
    q?: string;
    sort?: string;
    page?: number;
    per_page?: number;
    filter?: Record<string, unknown>;
};

export function isEmptyValue(value: unknown): boolean {
    if (value === null || value === undefined || value === '') {
        return true;
    }

    if (Array.isArray(value)) {
        return value.length === 0;
    }

    if (typeof value === 'object') {
        return Object.values(value as object).every(
            (v) => v === null || v === undefined || v === '',
        );
    }

    return false;
}

function serializeFilter(spec: FilterSpec, value: FilterValue): unknown {
    switch (spec.type) {
        case 'boolean':
            return value ? '1' : '0';
        case 'date_range':
        case 'number_range':
            return Object.fromEntries(
                Object.entries(value as object).filter(
                    ([, v]) => v !== '' && v !== null && v !== undefined,
                ),
            );
        default:
            return value;
    }
}

/**
 * Ubah state menjadi parameter URL. Aturan penting:
 * - Filter yang punya default selalu dikirim; bila dihapus dikirim kosong (`filter[x]=`)
 *   supaya server tidak menerapkan default lagi (BR-003, chip default bisa dihapus).
 * - Nilai bawaan (sort default, halaman 1) tidak ditulis agar URL tetap pendek.
 */
export function toQueryParams(
    spec: ListingSpec,
    state: ListingQueryState,
): QueryParams {
    const params: QueryParams = {};

    if (state.q.trim() !== '') {
        params.q = state.q.trim();
    }

    if (state.sort !== '' && state.sort !== spec.default_sort) {
        params.sort = state.sort;
    }

    if (state.page > 1) {
        params.page = state.page;
    }

    params.per_page = state.perPage;

    const filter: Record<string, unknown> = {};
    for (const f of spec.filters) {
        const value = state.filters[f.key];

        if (!isEmptyValue(value)) {
            filter[f.key] = serializeFilter(f, value);
        } else if (f.default !== undefined) {
            filter[f.key] = '';
        }
    }

    if (Object.keys(filter).length > 0) {
        params.filter = filter;
    }

    return params;
}

export function countActiveFilters(filters: Record<string, unknown>): number {
    return Object.values(filters).filter((v) => !isEmptyValue(v)).length;
}

/** Nilai filter bawaan dari spec, dipakai tombol Reset. */
export function defaultFilters(spec: ListingSpec): Record<string, FilterValue> {
    const result: Record<string, FilterValue> = {};
    for (const f of spec.filters) {
        if (f.default !== undefined) {
            result[f.key] = f.default as FilterValue;
        }
    }

    return result;
}

/** `sort` berikutnya saat header diklik: naik → turun → naik. */
export function nextSort(current: string, key: string): string {
    return current === key ? `-${key}` : key;
}

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

const numberFormatter = new Intl.NumberFormat('id-ID');

/** `2026-11-03` → `3 Nov 2026` (07 §7). Tanggal diperlakukan sebagai tanggal lokal murni. */
export function formatDate(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) {
        return value;
    }

    return dateFormatter.format(
        new Date(Date.UTC(+match[1], +match[2] - 1, +match[3])),
    );
}

export function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

function optionLabel(spec: FilterSpec, value: string | number): string {
    if ('options' in spec && spec.options) {
        const option = spec.options.find((o) => o.value === String(value));
        if (option) {
            return option.label;
        }
    }

    // Filter relasi: opsi ada di lookup (T-011); sementara tampilkan ID.
    return 'options_source' in spec && spec.options_source
        ? `#${value}`
        : String(value);
}

export function describeFilterValue(
    spec: FilterSpec,
    value: FilterValue,
): string {
    switch (spec.type) {
        case 'select':
            return optionLabel(spec, value as string | number);
        case 'multi_select':
            return (value as Array<string | number>)
                .map((v) => optionLabel(spec, v))
                .join(', ');
        case 'boolean':
            return value ? 'Ya' : 'Tidak';
        case 'date_range': {
            const { from, to } = value as DateRangeValue;
            if (from && to) {
                return from === to
                    ? formatDate(from)
                    : `${formatDate(from)} – ${formatDate(to)}`;
            }

            return from
                ? `sejak ${formatDate(from)}`
                : `sampai ${formatDate(to ?? '')}`;
        }
        case 'number_range': {
            const { min, max } = value as NumberRangeValue;
            if (min !== undefined && max !== undefined) {
                return `${formatNumber(min)}–${formatNumber(max)}`;
            }

            return min !== undefined
                ? `≥ ${formatNumber(min)}`
                : `≤ ${formatNumber(max ?? 0)}`;
        }
    }
}

export type FilterChip = { key: string; label: string; removable: boolean };

export function filterChips(
    spec: ListingSpec,
    filters: Record<string, FilterValue>,
): FilterChip[] {
    return spec.filters
        .filter((f) => !isEmptyValue(filters[f.key]))
        .map((f) => ({
            key: f.key,
            label: `${f.label}: ${describeFilterValue(f, filters[f.key])}`,
            removable: !f.required,
        }));
}
