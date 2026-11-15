import { Head, Link } from '@inertiajs/react';
import { Plus, ShieldCheck, ShieldOff } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatRelativeTime } from '@/lib/relative-time';
import { create, exportMethod, index, show } from '@/routes/admin/users';
import type { ListingProps } from '@/types/listing';

type UserRow = {
    id: number;
    name: string;
    username: string;
    email: string | null;
    phone: string | null;
    roles: { name: string; label: string }[];
    employee_id: number | null;
    is_active: boolean;
    mfa_enabled: boolean;
    last_login_at: string | null;
};

type Props = ListingProps<UserRow> & {
    canCreate: boolean;
    canExport: boolean;
};

/** Pengguna (05 §4.4, 07 §3.4 `users`, FR-016). */
export default function UsersIndex({
    rows,
    listing,
    canCreate,
    canExport,
}: Props) {
    const columns: DataTableColumn<UserRow>[] = [
        {
            key: 'name',
            header: 'Nama',
            sortKey: 'name',
            primary: true,
            cell: (u) => <span className="font-medium">{u.name}</span>,
        },
        {
            key: 'username',
            header: 'Username',
            sortKey: 'username',
            cell: (u) => (
                <span className="font-mono text-[0.8rem]">{u.username}</span>
            ),
        },
        {
            key: 'email',
            header: 'Email',
            cell: (u) =>
                u.email ?? <span className="text-muted-foreground">–</span>,
        },
        {
            key: 'roles',
            header: 'Role',
            cell: (u) =>
                u.roles.length > 0 ? (
                    <span className="flex flex-wrap gap-1">
                        {u.roles.map((r) => (
                            <Badge key={r.name} variant="secondary">
                                {r.label}
                            </Badge>
                        ))}
                    </span>
                ) : (
                    <span className="text-muted-foreground">Tanpa role</span>
                ),
        },
        {
            key: 'employee',
            header: 'Pegawai',
            // TODO(T-024): nama & no. pegawai terkait.
            cell: (u) =>
                u.employee_id !== null ? (
                    <span className="tabular-nums">#{u.employee_id}</span>
                ) : (
                    <span className="text-muted-foreground">–</span>
                ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (u) => (
                <StatusBadge
                    status={u.is_active ? 'user.active' : 'user.inactive'}
                />
            ),
        },
        {
            key: 'mfa',
            header: 'MFA',
            cell: (u) =>
                u.mfa_enabled ? (
                    <span className="inline-flex items-center gap-1 text-status-success">
                        <ShieldCheck className="size-4" aria-hidden="true" />
                        Aktif
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1 text-muted-foreground">
                        <ShieldOff className="size-4" aria-hidden="true" />
                        Belum
                    </span>
                ),
        },
        {
            key: 'last_login_at',
            header: 'Login terakhir',
            sortKey: 'last_login_at',
            cell: (u) =>
                u.last_login_at ? (
                    <time dateTime={u.last_login_at} title={u.last_login_at}>
                        {formatRelativeTime(u.last_login_at)}
                    </time>
                ) : (
                    <span className="text-muted-foreground">Belum pernah</span>
                ),
        },
    ];

    const addButton = canCreate ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Pengguna
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Pengguna" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Pengguna
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Akun untuk masuk ke PAKIS ONE dan Pakis Pro, beserta
                        role-nya.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar pengguna"
                    rowHref={(u) => show(u.id).url}
                    emptyAction={addButton}
                    toolbarActions={
                        <>
                            {canExport && listing.spec.exportable && (
                                <ExportButton
                                    href={exportMethod().url}
                                    total={listing.pagination.total}
                                />
                            )}
                            {addButton}
                        </>
                    }
                />
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Pengguna', href: index() }],
};
