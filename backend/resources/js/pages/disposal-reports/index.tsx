import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import type { ListingProps } from '@/types/listing';

export type DisposalReportRow = {
    id: number;
    number: string;
    location: string;
    method: string;
    method_label: string;
    lines_count: number;
    total_cost: string;
    status: string;
    status_label: string;
    planned_date: string | null;
    executed_at: string | null;
};

/**
 * Daftar berita acara pemusnahan/serah (15 §7.3 `disposal_reports`, FR-A29, BR-163). Hanya lokasi
 * dalam scope Stok pengguna.
 */
export default function DisposalReportsIndex({
    rows,
    listing,
}: ListingProps<DisposalReportRow>) {
    const { timezone } = usePage<{ timezone: string }>().props;

    const columns: DataTableColumn<DisposalReportRow>[] = [
        {
            key: 'number',
            header: 'Nomor',
            sortKey: 'number',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/pemusnahan/${r.id}`}
                    className="font-mono font-medium hover:underline"
                >
                    {r.number}
                </Link>
            ),
        },
        { key: 'location', header: 'Lokasi', cell: (r) => r.location },
        { key: 'method', header: 'Metode', cell: (r) => r.method_label },
        {
            key: 'lines',
            header: 'Batch',
            className: 'text-right tabular-nums',
            cell: (r) => r.lines_count,
        },
        {
            key: 'total_cost',
            header: 'Nilai',
            sortKey: 'total_cost',
            className: 'text-right tabular-nums',
            cell: (r) => formatRupiah(r.total_cost),
        },
        {
            key: 'when',
            header: 'Rencana / dilaksanakan',
            cell: (r) =>
                r.executed_at
                    ? formatDateTimeIn(r.executed_at, timezone)
                    : r.planned_date
                      ? formatCivilDate(r.planned_date)
                      : '–',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`disposal_report.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Pemusnahan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Pemusnahan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Berita acara untuk batch karantina atau kedaluwarsa:
                        dimusnahkan, diserahkan ke instansi, atau dikembalikan
                        ke prinsipal setelah disetujui.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar berita acara"
                    toolbarActions={
                        <Button asChild>
                            <Link href="/pemusnahan/baru">
                                <Plus aria-hidden="true" />
                                Buat Berita Acara
                            </Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}

DisposalReportsIndex.layout = {
    breadcrumbs: [{ title: 'Pemusnahan', href: '/pemusnahan' }],
};
