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
