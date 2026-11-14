import { useEffect, useState } from 'react';
import { cachedLabel, fetchLookup } from '@/lib/lookup';
import type { FilterValue, ListingSpec } from '@/types/listing';

/**
 * Pastikan label nilai filter relasi yang aktif ada di cache lookup (mis. dari URL yang dibagikan),
 * lalu render ulang agar chip menampilkan nama, bukan ID. Satu permintaan `?ids[]=` per sumber.
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

        const values = (Array.isArray(raw) ? raw : [raw]) as Array<
            number | string
        >;
        const unknown = values.filter(
            (v) => cachedLabel(source, v) === undefined,
        );
        if (unknown.length) {
            missing.set(source, [...(missing.get(source) ?? []), ...unknown]);
        }
    }

    const signature = [...missing.entries()]
        .map(([source, ids]) => `${source}=${ids.join(',')}`)
        .join('|');

    useEffect(() => {
        if (signature === '') {
            return;
        }

        // Dibaca ulang dari signature: effect hanya bergantung pada string stabil itu.
        const requests = signature.split('|').map((part) => {
            const [source, ids] = part.split('=');

            return { source, ids: ids.split(',').slice(0, 20) };
        });

        const controller = new AbortController();
        // allSettled tidak pernah gagal: label yang tidak ditemukan tetap tampil sebagai ID.
        void Promise.allSettled(
            requests.map(({ source, ids }) =>
                fetchLookup(source, { ids }, controller.signal),
            ),
        ).then(() => {
            if (!controller.signal.aborted) {
                setVersion((v) => v + 1);
            }
        });

        return () => controller.abort();
    }, [signature]);
}
