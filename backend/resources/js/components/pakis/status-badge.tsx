import { cn } from '@/lib/utils';

export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'muted';

type StatusDefinition = { label: string; tone: StatusTone };

/**
 * Label & warna status yang sama di seluruh aplikasi (07 §6). "accent" di dokumen = tone `warning`.
 * Kunci memakai awalan domain bila nilai bentrok antar-domain (mis. `pending`).
 */
export const STATUS_MAP: Record<string, StatusDefinition> = {
    // Absensi harian
    present: { label: 'Hadir', tone: 'success' },
    late: { label: 'Telat', tone: 'warning' },
    early_leave: { label: 'Pulang cepat', tone: 'warning' },
    no_checkout: { label: 'Tidak absen pulang', tone: 'warning' },
    absent: { label: 'Tidak hadir', tone: 'danger' },
    holiday: { label: 'Libur', tone: 'info' },
    off: { label: 'Tidak dijadwalkan', tone: 'muted' },
    // Koreksi absen
    'correction.pending': { label: 'Menunggu', tone: 'warning' },
    'correction.approved': { label: 'Disetujui', tone: 'success' },
    'correction.rejected': { label: 'Ditolak', tone: 'danger' },
    'correction.cancelled': { label: 'Dibatalkan', tone: 'muted' },
    // Tugas luar kota (07 §6, T-036A)
    'field_trip.pending': { label: 'Menunggu', tone: 'warning' },
    'field_trip.approved': { label: 'Disetujui', tone: 'success' },
    'field_trip.rejected': { label: 'Ditolak', tone: 'danger' },
    'field_trip.cancelled': { label: 'Dibatalkan', tone: 'muted' },
    // Perangkat
    'device.pending': { label: 'Menunggu', tone: 'warning' },
    'device.active': { label: 'Aktif', tone: 'success' },
    'device.revoked': { label: 'Dicabut', tone: 'danger' },
    'device.replaced': { label: 'Diganti', tone: 'muted' },
    'device.rejected': { label: 'Ditolak', tone: 'danger' },
    // Berkas ekspor (halaman Unduhan, T-006)
    'export.queued': { label: 'Antre', tone: 'warning' },
    'export.processing': { label: 'Diproses', tone: 'info' },
    'export.ready': { label: 'Siap', tone: 'success' },
    'export.failed': { label: 'Gagal', tone: 'danger' },
    'export.expired': { label: 'Kedaluwarsa', tone: 'muted' },
    // Lokasi (T-021)
    'location.active': { label: 'Aktif', tone: 'success' },
    'location.inactive': { label: 'Nonaktif', tone: 'muted' },
    // Departemen (T-022)
    'department.active': { label: 'Aktif', tone: 'success' },
    'department.inactive': { label: 'Nonaktif', tone: 'muted' },
    // Jabatan (T-023)
    'position.active': { label: 'Aktif', tone: 'success' },
    'position.inactive': { label: 'Nonaktif', tone: 'muted' },
    // Shift (T-033)
    'shift.active': { label: 'Aktif', tone: 'success' },
    'shift.inactive': { label: 'Nonaktif', tone: 'muted' },
    // Akun pengguna (T-020)
    'user.active': { label: 'Aktif', tone: 'success' },
    'user.inactive': { label: 'Nonaktif', tone: 'muted' },
    // Notifikasi (T-029)
    'notification.unread': { label: 'Belum dibaca', tone: 'info' },
    'notification.read': { label: 'Sudah dibaca', tone: 'muted' },
    // Pegawai
    'import.uploaded': { label: 'Diunggah', tone: 'muted' },
    'import.mapped': { label: 'Divalidasi…', tone: 'info' },
    'import.validated': { label: 'Siap diproses', tone: 'success' },
    'import.processing': { label: 'Diproses', tone: 'info' },
    'import.completed': { label: 'Selesai', tone: 'success' },
    'import.failed': { label: 'Gagal', tone: 'danger' },
    'import.cancelled': { label: 'Dibatalkan', tone: 'muted' },

    'import_row.valid': { label: 'Valid', tone: 'success' },
    'import_row.invalid': { label: 'Galat', tone: 'danger' },
    'import_row.created': { label: 'Dibuat', tone: 'success' },
    'import_row.updated': { label: 'Diperbarui', tone: 'info' },
    'import_row.skipped': { label: 'Dilewati', tone: 'muted' },

    'employee.active': { label: 'Aktif', tone: 'success' },
    'employee.inactive': { label: 'Nonaktif', tone: 'muted' },

    // Mesin persetujuan (T-067, 07 §6)
    'approval.pending': { label: 'Menunggu', tone: 'warning' },
    'approval.approved': { label: 'Disetujui', tone: 'success' },
    'approval.rejected': { label: 'Ditolak', tone: 'danger' },
    'approval.cancelled': { label: 'Dibatalkan', tone: 'muted' },
    'approval_step.waiting': { label: 'Belum giliran', tone: 'muted' },
    'approval_step.pending': { label: 'Menunggu', tone: 'warning' },
    'approval_step.approved': { label: 'Disetujui', tone: 'success' },
    'approval_step.rejected': { label: 'Ditolak', tone: 'danger' },
    'approval_step.skipped': { label: 'Dilewati', tone: 'muted' },
    'delegation.active': { label: 'Berlaku', tone: 'success' },
    'delegation.scheduled': { label: 'Terjadwal', tone: 'info' },
    'delegation.expired': { label: 'Berakhir', tone: 'muted' },
    'delegation.revoked': { label: 'Dicabut', tone: 'danger' },
    'approval_rule.active': { label: 'Aktif', tone: 'success' },
    'approval_rule.inactive': { label: 'Nonaktif', tone: 'muted' },
    // Lokasi stok, rak, kendaraan (T-068, 07 §6)
    'stock_location.active': { label: 'Aktif', tone: 'success' },
    'stock_location.inactive': { label: 'Nonaktif', tone: 'muted' },
    'stock_location.system': { label: 'Sistem', tone: 'info' },
    'stock_bin.active': { label: 'Aktif', tone: 'success' },
    'stock_bin.inactive': { label: 'Nonaktif', tone: 'muted' },
    'bin_zone.general': { label: 'Umum', tone: 'muted' },
    'bin_zone.pesticide': { label: 'Pestisida', tone: 'warning' },
    'bin_zone.restricted': { label: 'Terbatas', tone: 'danger' },
    'bin_zone.quarantine': { label: 'Karantina', tone: 'info' },
    'vehicle.active': { label: 'Aktif', tone: 'success' },
    'vehicle.inactive': { label: 'Nonaktif', tone: 'muted' },
    // Status batch stok & ED (T-078, 15 §7.2 StockStatusBadge / ExpiryBadge)
    'stock_batch.available': { label: 'Tersedia', tone: 'success' },
    'stock_batch.quarantine': { label: 'Karantina', tone: 'info' },
    'stock_batch.expired': { label: 'Kedaluwarsa', tone: 'danger' },
    'stock_batch.disposed': { label: 'Dimusnahkan', tone: 'muted' },
    'expiry.expired': { label: 'Lewat ED', tone: 'danger' },
    'expiry.near': { label: 'Mendekati ED', tone: 'warning' },
    'expiry.ok': { label: 'ED aman', tone: 'muted' },
    'expiry.none': { label: 'Tanpa ED', tone: 'muted' },
    // Purchase order (T-079, 15 §6.6)
    'purchase_order.draft': { label: 'Draf', tone: 'muted' },
    'purchase_order.submitted': {
        label: 'Menunggu persetujuan',
        tone: 'warning',
    },
    'purchase_order.approved': { label: 'Disetujui', tone: 'info' },
    'purchase_order.partially_received': {
        label: 'Diterima sebagian',
        tone: 'warning',
    },
    'purchase_order.received': { label: 'Diterima', tone: 'success' },
    'purchase_order.closed': { label: 'Ditutup', tone: 'muted' },
    'purchase_order.rejected': { label: 'Ditolak', tone: 'danger' },
    'purchase_order.cancelled': { label: 'Dibatalkan', tone: 'muted' },
    // Mutasi antar lokasi (T-082, 15 §6.6)
    'stock_transfer.draft': { label: 'Draf', tone: 'muted' },
    'stock_transfer.shipped': { label: 'Dikirim', tone: 'info' },
    'stock_transfer.received': { label: 'Diterima', tone: 'success' },
    'stock_transfer.received_with_variance': {
        label: 'Diterima dengan selisih',
        tone: 'warning',
    },
    'stock_transfer.closed': { label: 'Selesai', tone: 'muted' },
    'stock_transfer.cancelled': { label: 'Dibatalkan', tone: 'muted' },
    // Penerimaan barang, baris penerimaan, nomor seri (T-080, 15 §6.6)
    'goods_receipt.draft': { label: 'Draf', tone: 'muted' },
    'goods_receipt.checked': { label: 'Diperiksa', tone: 'info' },
    'goods_receipt.pending_approval': {
        label: 'Menunggu persetujuan',
        tone: 'warning',
    },
    'goods_receipt.posted': { label: 'Diposting', tone: 'success' },
    'goods_receipt.rejected': { label: 'Ditolak', tone: 'danger' },
    'receipt_line.ok': { label: 'Sesuai', tone: 'success' },
    'receipt_line.short_shelf_life': {
        label: 'Sisa umur pendek',
        tone: 'warning',
    },
    'receipt_line.registration_mismatch': {
        label: 'Izin edar tidak cocok',
        tone: 'danger',
    },
    'serial.in_stock': { label: 'Di stok', tone: 'success' },
    'serial.reserved': { label: 'Dipesan', tone: 'info' },
    'serial.sold': { label: 'Terjual', tone: 'muted' },
    'serial.returned': { label: 'Retur pelanggan', tone: 'warning' },
    'serial.in_service': { label: 'Servis', tone: 'info' },
    'serial.returned_to_principal': {
        label: 'Retur ke prinsipal',
        tone: 'muted',
    },
    'serial.scrapped': { label: 'Dimusnahkan', tone: 'danger' },
};

const TONE_CLASS: Record<StatusTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    danger: 'bg-status-danger-bg text-status-danger',
    info: 'bg-status-info-bg text-status-info',
    muted: 'bg-status-muted-bg text-status-muted',
};

type Props = {
    /** Kunci di STATUS_MAP, mis. `late` atau `device.pending`. */
    status: string;
    /** Untuk status di luar peta (mis. nilai dari referensi dinamis). */
    label?: string;
    tone?: StatusTone;
    className?: string;
};

export function StatusBadge({ status, label, tone, className }: Props) {
    const known = STATUS_MAP[status];
    const resolvedTone = tone ?? known?.tone ?? 'muted';

    return (
        <span
            data-status={status}
            className={cn(
                // Pil tebal seperti `.badge` prototipe.
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold whitespace-nowrap',
                TONE_CLASS[resolvedTone],
                className,
            )}
        >
            {label ?? known?.label ?? status}
        </span>
    );
}

export type ExpiryState = 'expired' | 'near' | 'ok' | 'none';

/**
 * Kelompok ED (15 §7.2): merah bila sudah lewat (< 0 hari), oranye bila ≤ ambang terbesar
 * `inventory.near_expiry_days`, abu lainnya. Server (lookup `available_batches`) mengirim nilai
 * yang sama di `expiry_state`; fungsi ini untuk layar yang hanya punya sisa hari.
 */
export function expiryState(
    daysToExpiry: number | null,
    nearDays: number,
): ExpiryState {
    if (daysToExpiry === null) {
        return 'none';
    }

    if (daysToExpiry < 0) {
        return 'expired';
    }

    return daysToExpiry <= nearDays ? 'near' : 'ok';
}

/** Badge ED: warna dari STATUS_MAP `expiry.*`, teks sisa hari agar tidak hanya bergantung warna. */
export function ExpiryBadge({
    state,
    daysToExpiry,
    className,
}: {
    state: ExpiryState;
    daysToExpiry: number | null;
    className?: string;
}) {
    const label =
        daysToExpiry === null
            ? 'Tanpa ED'
            : daysToExpiry < 0
              ? `Lewat ED ${-daysToExpiry} hari`
              : daysToExpiry === 0
                ? 'ED hari ini'
                : `ED ${daysToExpiry} hari lagi`;

    return (
        <StatusBadge
            status={`expiry.${state}`}
            label={label}
            className={className}
        />
    );
}

/** Status batch stok (`stock_batches.status`). */
export function StockStatusBadge({
    status,
    className,
}: {
    status: 'available' | 'quarantine' | 'expired' | 'disposed';
    className?: string;
}) {
    return (
        <StatusBadge status={`stock_batch.${status}`} className={className} />
    );
}
