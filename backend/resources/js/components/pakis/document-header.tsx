import type { InertiaLinkProps } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { DetailBackLink, DetailHero } from '@/components/pakis/detail-hero';
import type {
    DetailHeroHighlight,
    DetailHeroMeta,
    DetailHeroSummary,
} from '@/components/pakis/detail-hero';
import { StatusBadge } from '@/components/pakis/status-badge';

/**
 * Kepala halaman dokumen (15 §7.1: PO, penerimaan, mutasi, retur; juga persetujuan, koreksi, audit):
 * varian `DetailHero` agar halaman dokumen terasa satu aplikasi dengan halaman detail lain — pita
 * tipis bermotif pakis, ikon dokumen di avatar, nomor dokumen monospace sebagai judul, badge status,
 * meta berikon, sorotan di kanan pita, dan slot aksi. Generik: isi meta/sorotan/aksi diatur halaman.
 *
 * Aksi: tombol utama sesuai status + "Ubah" langsung terlihat, aksi sekunder (Cetak, Batalkan …)
 * lewat `MoreActionsMenu`. Di HP pakai `ActionLabel` + `aria-label` agar tombol cukup ikon.
 */
export function DocumentHeader({
    backHref,
    backLabel,
    number,
    title,
    kind,
    icon = FileText,
    status,
    statusLabel,
    badges,
    meta,
    highlights,
    summary,
    notice,
    actions,
    muted = false,
}: {
    backHref: NonNullable<InertiaLinkProps['href']>;
    backLabel: string;
    /**
     * Nomor dokumen. Tanpa `title`, nomor menjadi judul (monospace besar); dengan `title`, nomor
     * tampil sebagai chip kode di bawah judul.
     */
    number?: string | null;
    /** Judul teks untuk dokumen yang tidak dikenali dari nomornya (mis. ringkasan persetujuan). */
    title?: string;
    /** Jenis dokumen, tampil kecil di atas judul (mis. "Purchase order"). */
    kind?: ReactNode;
    /** Ikon di avatar kotak; bawaan ikon berkas. */
    icon?: LucideIcon;
    /** Kunci STATUS_MAP, mis. `purchase_order.approved`. Badge tidak tampil bila kosong. */
    status?: string;
    statusLabel?: string;
    /** Badge tambahan di samping status (mis. "Terlambat"). */
    badges?: ReactNode;
    /** Butir berikon di bawah judul (pihak, gudang, tanggal …); nilai kosong disembunyikan. */
    meta?: DetailHeroMeta[];
    /** Sorotan (maks. 3) di kanan pita: total, tanggal penting, progres. */
    highlights?: DetailHeroHighlight[];
    /** Sel ringkas di dasar kartu (maks. 4). */
    summary?: DetailHeroSummary;
    notice?: ReactNode;
    /** Tombol size="sm"; aksi sekunder lewat `MoreActionsMenu`. */
    actions?: ReactNode;
    /** Dokumen final/batal: pita diredupkan. */
    muted?: boolean;
}) {
    const statusBadge =
        status && statusLabel ? (
            <StatusBadge status={status} label={statusLabel} />
        ) : null;

    return (
        <>
            <DetailBackLink href={backHref} label={backLabel} />
            <DetailHero
                title={title ?? number ?? ''}
                monoTitle={title === undefined}
                code={title !== undefined ? number : undefined}
                eyebrow={kind}
                avatar={{ icon, shape: 'square' }}
                badges={
                    statusBadge || badges ? (
                        <>
                            {statusBadge}
                            {badges}
                        </>
                    ) : undefined
                }
                meta={meta}
                highlights={highlights}
                summary={summary}
                notice={notice}
                actions={actions}
                muted={muted}
            />
        </>
    );
}
