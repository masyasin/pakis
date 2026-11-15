import { createContext, useContext, useEffect } from 'react';
import type { BreadcrumbItem } from '@/types';

/**
 * Ekor breadcrumb dinamis. `Page.layout = { breadcrumbs }` bersifat statis (tidak tahu props
 * halaman), padahal halaman bertab perlu menampilkan tab aktif, mis. "Pengaturan › Branding" atau
 * "Pegawai › Endang Rahayu › Penugasan". Layout menyimpan ekornya; halaman mengisinya lewat hook
 * ini, dan ekor dikosongkan saat halaman ditinggalkan.
 */
export const BreadcrumbTailContext = createContext<
    (items: BreadcrumbItem[]) => void
>(() => {});

export function useBreadcrumbTail(items: BreadcrumbItem[]): void {
    const setTail = useContext(BreadcrumbTailContext);
    // Dibandingkan sebagai teks: array baru tiap render tidak boleh memicu efek berulang.
    const key = JSON.stringify(items);

    useEffect(() => {
        setTail(JSON.parse(key) as BreadcrumbItem[]);

        return () => setTail([]);
    }, [key, setTail]);
}
