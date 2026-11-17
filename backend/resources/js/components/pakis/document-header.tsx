import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/pakis/status-badge';

/**
 * Kepala "dokumen bergaris" (15 §7.1: PO, penerimaan, mutasi, retur): tautan kembali, nomor
 * dokumen, badge status, baris keterangan singkat, dan tombol aksi di kanan (turun ke bawah di HP).
 */
export function DocumentHeader({
    backHref,
    backLabel,
    number,
    status,
    statusLabel,
    meta,
    badges,
    actions,
}: {
    backHref: string;
    backLabel: string;
    number: string;
    /** Kunci STATUS_MAP, mis. `purchase_order.approved`. */
    status: string;
    statusLabel: string;
    /** Keterangan di bawah nomor (supplier, gudang, tanggal). */
    meta?: ReactNode;
    /** Badge tambahan di samping status (mis. "Terlambat"). */
    badges?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="space-y-3">
            <Link
                href={backHref}
                className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                {backLabel}
            </Link>
            <header className="flex flex-wrap items-start gap-4">
                <div className="min-w-0 flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="font-mono text-[1.4rem] leading-tight font-bold break-all">
                            {number}
                        </h1>
                        <StatusBadge status={status} label={statusLabel} />
                        {badges}
                    </div>
                    {meta && (
                        <div className="text-sm text-muted-foreground">
                            {meta}
                        </div>
                    )}
                </div>
                {actions && (
                    <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                        {actions}
                    </div>
                )}
            </header>
        </div>
    );
}
