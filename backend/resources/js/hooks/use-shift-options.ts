import { useEffect, useRef, useState } from 'react';
import { fetchLookup } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';

/**
 * Pilihan shift aktif dalam scope pengguna (lookup `shifts`, maks 20 — BR-006). Dimuat sekali saat
 * `enabled` (dialog dibuka), bukan per pemilih hari: tujuh pemilih memakai daftar yang sama.
 * Bila dialog ditutup sebelum selesai, permintaan dibatalkan dan dicoba lagi saat dibuka kembali.
 */
export function useShiftOptions(enabled: boolean) {
    const [options, setOptions] = useState<LookupOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const loaded = useRef(false);

    useEffect(() => {
        if (!enabled || loaded.current) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setFailed(false);
        fetchLookup('shifts', {}, controller.signal)
            .then((result) => {
                loaded.current = true;
                setOptions(result);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [enabled]);

    return { options, loading, failed };
}
