import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/listing-query';

type Props = {
    /** URL route `admin.<modul>.export`. */
    href: string;
    /** Total baris hasil filter saat ini (`listing.pagination.total`). */
    total: number;
};

/**
 * Tombol "Ekspor (132 baris)" (07 §3.2.9). Memakai filter, pencarian, dan sort yang sedang aktif
 * dengan menyalin query string halaman. `<a>` biasa (bukan Inertia) karena responsnya bisa berupa
 * berkas (≤ batas sinkron) atau redirect balik dengan pesan (antrean / ditolak).
 */
export function ExportButton({ href, total }: Props) {
    const url = `${href}${window.location.search}`;

    if (total === 0) {
        return (
            <Button type="button" variant="outline" size="sm" disabled>
                <Download className="size-4" aria-hidden />
                Ekspor
            </Button>
        );
    }

    return (
        <Button asChild variant="outline" size="sm">
            <a href={url}>
                <Download className="size-4" aria-hidden />
                Ekspor ({formatNumber(total)} baris)
            </a>
        </Button>
    );
}
