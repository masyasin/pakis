import { useEffect, useState } from 'react';
import { cachedLabel, fetchLookup } from '@/lib/lookup';
import type { FilterValue, ListingSpec } from '@/types/listing';

/**
 * Pastikan label nilai filter relasi yang aktif ada di cache lookup (mis. dari URL yang dibagikan),
 * lalu render ulang agar chip menampilkan nama, bukan ID. Satu permintaan `?ids[]=` per sumber +
 * parameter tetap (mis. `regions` level `district`).
 */
export function useLookupLabels(
    spec: ListingSpec,
    filters: Record<string, FilterValue>,
): void {
    const [, setVersion] = useState(0);

    const missing = new Map<string, Array<number | string>>();
    for (const filter of spec.filters) {
        const source =
            'options_source' in filter ? filter.options_source : null;
        const raw = filters[filter.key];
        if (!source || raw === undefined || raw === null) {
            continue;
        }

        const params =
            'options_params' in filter && filter.options_params
                ? new URLSearchParams(filter.options_params).toString()
                : '';
        const values = (Array.isArray(raw) ? raw : [raw]) as Array<
            number | string
        >;
        const unknown = values.filter(
            (v) => cachedLabel(source, v) === undefined,
        );
        if (unknown.length) {
            const requestKey = `${source}?${params}`;
            missing.set(requestKey, [
                ...(missing.get(requestKey) ?? []),
                ...unknown,
            ]);
        }
    }

    const signature = [...missing.entries()]
        .map(([requestKey, ids]) => `${requestKey}=${ids.join(',')}`)
        .join('|');

    useEffect(() => {
        if (signature === '') {
            return;
        }

        // Dibaca ulang dari signature: effect hanya bergantung pada string stabil itu.
        const requests = signature.split('|').map((part) => {
            const separator = part.lastIndexOf('=');
            const [source, query] = part.slice(0, separator).split('?');

            return {
                source,
                extra: Object.fromEntries(new URLSearchParams(query)),
                ids: part
                    .slice(separator + 1)
                    .split(',')
                    .slice(0, 20),
            };
        });

        const controller = new AbortController();
        // allSettled tidak pernah gagal: label yang tidak ditemukan tetap tampil sebagai ID.
        void Promise.allSettled(
            requests.map(({ source, extra, ids }) =>
                fetchLookup(source, { ids, extra }, controller.signal),
            ),
        ).then(() => {
            if (!controller.signal.aborted) {
                setVersion((v) => v + 1);
            }
        });

        return () => controller.abort();
    }, [signature]);
}
