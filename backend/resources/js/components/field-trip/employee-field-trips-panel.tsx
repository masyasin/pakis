import { Link } from '@inertiajs/react';
import { Luggage, Plus } from 'lucide-react';
import { DetailCard, EmptyPanel } from '@/components/employee/detail-ui';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatCivilRange } from '@/lib/local-date';
import { create, index } from '@/routes/admin/field-trips';
import type { FieldTripRow } from '@/pages/field-trips/index';

export type EmployeeFieldTripsData = {
    rows: FieldTripRow[];
    total: number;
    canCreate: boolean;
};

/** Tab Tugas Luar Kota di detail pegawai (T-036A, BR-071): tugas terbaru, semua status. */
export function EmployeeFieldTripsPanel({
    employeeName,
    data,
}: {
    employeeName: string;
    data: EmployeeFieldTripsData;
}) {
    const addButton = data.canCreate ? (
        <Button variant="outline" size="sm" asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah tugas
            </Link>
        </Button>
    ) : undefined;

    if (data.rows.length === 0) {
        return (
            <EmptyPanel
                icon={Luggage}
                title="Belum ada tugas luar kota"
                text="Selama tugas luar kota, pegawai boleh presensi di lokasi tujuan di samping lokasi biasanya."
                action={addButton}
            />
        );
    }

    return (
        <DetailCard title="Tugas luar kota" icon={Luggage} action={addButton}>
            <ul className="divide-y">
                {data.rows.map((t) => (
                    <li
                        key={t.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5"
                    >
                        <span className="grid min-w-40">
                            <span className="text-sm tabular-nums">
                                {formatCivilRange(t.start_date, t.end_date)}
                            </span>
                            <span className="font-mono text-xs text-muted-foreground">
                                {t.number}
                            </span>
                        </span>
                        <span className="mr-auto grid">
                            <span className="text-sm font-medium">
                                {t.destination}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {t.rule.label}
                            </span>
                        </span>
                        <StatusBadge
                            status={`field_trip.${t.status.value}`}
                            label={t.status.label}
                        />
                    </li>
                ))}
            </ul>
            {data.total > data.rows.length && (
                <p className="mt-3 text-sm">
                    <Link
                        href={index({
                            query: { q: employeeName, 'filter[date]': '' },
                        })}
                        className="text-primary hover:underline"
                    >
                        Lihat semua {data.total} tugas
                    </Link>
                </p>
            )}
        </DetailCard>
    );
}
