import { Link } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatRelativeTime } from '@/lib/relative-time';

export type DeviceSummary = {
    id: number;
    model: string | null;
    os_version: string | null;
    app_version: string | null;
    status: 'pending' | 'active' | 'revoked' | 'replaced' | 'rejected';
    request_reason: string | null;
    created_at: string | null;
    last_seen_at: string | null;
};

type Props = {
    devices: DeviceSummary[];
    emptyText: string;
    /** Tautan ke halaman Perangkat (hanya untuk pemegang `devices.view`). */
    manageHref?: string | null;
};

/**
 * Daftar ringkas HP Pakis Pro satu akun (Profil › Keamanan, detail Pengguna & Pegawai, BR-040).
 * Persetujuan/pencabutan tetap di halaman Perangkat agar aturannya satu tempat.
 */
export function DeviceSummaryList({ devices, emptyText, manageHref }: Props) {
    if (devices.length === 0) {
        return (
            <div className="flex items-start gap-3 rounded-lg border border-dashed p-4 text-sm">
                <Smartphone
                    className="mt-px size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <p className="text-muted-foreground">{emptyText}</p>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <ul className="divide-y rounded-lg border">
                {devices.map((d) => (
                    <li
                        key={d.id}
                        className="flex flex-wrap items-start justify-between gap-3 p-3 text-sm"
                    >
                        <div className="flex min-w-0 items-start gap-3">
                            <Smartphone
                                className="mt-px size-5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div className="min-w-0">
                                <p className="font-medium">
                                    {d.model ?? 'Perangkat'}
                                </p>
                                <p className="text-muted-foreground">
                                    {d.os_version ?? '–'}
                                    {d.app_version &&
                                        ` · Pakis Pro ${d.app_version}`}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {d.last_seen_at
                                        ? `Terakhir aktif ${formatRelativeTime(d.last_seen_at)}`
                                        : d.created_at
                                          ? `Didaftarkan ${formatRelativeTime(d.created_at)}`
                                          : null}
                                </p>
                                {d.status === 'rejected' &&
                                    d.request_reason && (
                                        <p className="text-xs text-muted-foreground">
                                            Alasan ditolak: {d.request_reason}
                                        </p>
                                    )}
                            </div>
                        </div>
                        <StatusBadge status={`device.${d.status}`} />
                    </li>
                ))}
            </ul>
            {manageHref && (
                <Link
                    href={manageHref}
                    className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                >
                    Kelola di halaman Perangkat
                </Link>
            )}
        </div>
    );
}
