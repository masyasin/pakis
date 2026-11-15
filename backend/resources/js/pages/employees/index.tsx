import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatPhone } from '@/lib/employee-format';
import { create, exportMethod, index, show } from '@/routes/admin/employees';
import type { ListingProps } from '@/types/listing';

type EmployeeRow = {
    id: number;
    employee_number: string;
    full_name: string;
    position: string | null;
    location: string | null;
    employment_status: string | null;
    phone: string | null;
    join_date: string;
    has_account: boolean;
    status: 'active' | 'inactive';
};

type Props = ListingProps<EmployeeRow> & {
    can: { create: boolean; export: boolean };
};

const dash = <span className="text-muted-foreground">–</span>;

const columns: DataTableColumn<EmployeeRow>[] = [
    {
        key: 'employee_number',
        header: 'No. pegawai',
        sortKey: 'employee_number',
        className: 'whitespace-nowrap',
        cell: (r) => (
            <span className="font-mono text-xs">{r.employee_number}</span>
        ),
    },
    {
        key: 'full_name',
        header: 'Nama',
        sortKey: 'full_name',
        primary: true,
        cell: (r) => <span className="font-medium">{r.full_name}</span>,
    },
    { key: 'position', header: 'Jabatan', cell: (r) => r.position ?? dash },
    { key: 'location', header: 'Lokasi', cell: (r) => r.location ?? dash },
    {
        key: 'employment_status',
        header: 'Status kerja',
        cell: (r) => r.employment_status ?? dash,
    },
    {
        key: 'phone',
        header: 'HP',
        className: 'whitespace-nowrap tabular-nums',
        cell: (r) => (r.phone ? formatPhone(r.phone) : dash),
    },
    {
        key: 'join_date',
        header: 'Tgl masuk',
        sortKey: 'join_date',
        className: 'whitespace-nowrap',
        cell: (r) => formatDate(r.join_date),
    },
    {
        key: 'has_account',
        header: 'Akun',
        cell: (r) =>
            r.has_account ? (
                <StatusBadge status="account.yes" label="Ada" tone="info" />
            ) : (
                dash
            ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => <StatusBadge status={`employee.${r.status}`} />,
    },
];

/** Pegawai (05 §4.6, FR-025). Daftar mengikuti scope data pengguna (06 §5). */
export default function EmployeesIndex({ rows, listing, can }: Props) {
    const addButton = can.create ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Pegawai
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Pegawai" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Pegawai
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Data pegawai beserta jabatan dan lokasi tugas utamanya
                        hari ini. Pegawai yang sudah keluar ada di status
                        Nonaktif.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar pegawai"
                    rowHref={(r) => show(r.id).url}
                    toolbarActions={
                        <>
                            {can.export && (
                                <ExportButton
                                    href={exportMethod().url}
                                    total={listing.pagination.total}
                                />
                            )}
                            {addButton}
                        </>
                    }
                    emptyAction={addButton}
                />
            </div>
        </>
    );
}

EmployeesIndex.layout = {
    breadcrumbs: [{ title: 'Pegawai', href: index() }],
};
