/**
 * Klien lookup `GET /lookup/{source}` (05 §4.11, BR-006): maks 20 opsi, aktif, ber-scope.
 *
 * Label yang pernah dilihat disimpan di cache modul agar chip filter bisa menampilkan nama
 * ("Pelaku: Demo Admin"), bukan ID, tanpa meminta ulang ke server.
 */

export type LookupOption = {
    value: number | string;
    label: string;
    hint?: string | null;
};

export const LOOKUP_DEBOUNCE_MS = 250;

const labels = new Map<string, string>();

const cacheKey = (source: string, value: number | string) =>
    `${source}:${value}`;

export function rememberLabels(source: string, options: LookupOption[]): void {
    options.forEach((o) => labels.set(cacheKey(source, o.value), o.label));
}

export function cachedLabel(
    source: string,
    value: number | string,
): string | undefined {
    return labels.get(cacheKey(source, value));
}

/** Untuk test: kosongkan cache antar kasus. */
export function clearLookupCache(): void {
    labels.clear();
}

export function lookupUrl(
    source: string,
    params: { q?: string; ids?: Array<number | string> } = {},
): string {
    const search = new URLSearchParams();
    if (params.q) {
        search.set('q', params.q);
    }
    params.ids?.forEach((id) => search.append('ids[]', String(id)));
    const query = search.toString();

    return `/lookup/${source}${query ? `?${query}` : ''}`;
}

export async function fetchLookup(
    source: string,
    params: { q?: string; ids?: Array<number | string> },
    signal?: AbortSignal,
): Promise<LookupOption[]> {
    const response = await fetch(lookupUrl(source, params), {
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Lookup ${source} gagal (${response.status}).`);
    }

    const options = (await response.json()) as LookupOption[];
    rememberLabels(source, options);

    return options;
}
