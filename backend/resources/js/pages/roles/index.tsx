import { Head, Link, router } from '@inertiajs/react';
import { Lock, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { create, edit, index } from '@/routes/admin/roles';
import type { ListingProps } from '@/types/listing';

type RoleRow = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    data_scope: string;
    data_scope_label: string;
    mfa_required: boolean;
    is_system: boolean;
    permissions_count: number;
    users_count: number;
    read_only: 'locked' | 'own' | null;
};

type Props = ListingProps<RoleRow> & { canManage: boolean };

/** Alasan role tidak bisa dihapus (BR-031), ditampilkan sebagai keterangan tombol. */
function deleteBlocker(r: RoleRow): string | null {
    if (r.read_only === 'locked') {
        return 'Role terkunci.';
    }
    if (r.read_only === 'own') {
        return 'Anda sedang memegang role ini.';
    }
    if (r.is_system) {
        return 'Role sistem tidak bisa dihapus.';
    }
    if (r.users_count > 0) {
        return `Masih dipegang ${r.users_count} pengguna.`;
    }

    return null;
}

/** Role & Hak Akses (05 §4, 07 §4, FR-017). */
export default function RolesIndex({ rows, listing, canManage }: Props) {
    const [deleting, setDeleting] = useState<RoleRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const columns: DataTableColumn<RoleRow>[] = [
        {
            key: 'label',
            header: 'Nama',
            sortKey: 'label',
            primary: true,
            cell: (r) => (
                <span className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{r.label}</span>
                    {r.is_system && (
                        <Badge variant="secondary" className="gap-1">
                            <Lock className="size-3" aria-hidden="true" />
                            Sistem
                        </Badge>
                    )}
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.name}
                    </span>
                </span>
            ),
        },
        {
            key: 'description',
            header: 'Deskripsi',
            cell: (r) => (
                <span className="text-muted-foreground">
                    {r.description ?? '–'}
                </span>
            ),
        },
        { key: 'scope', header: 'Scope', cell: (r) => r.data_scope_label },
        {
            key: 'mfa',
            header: 'Wajib MFA',
            cell: (r) =>
                r.mfa_required ? (
                    <span className="inline-flex items-center gap-1 text-status-success">
                        <ShieldCheck className="size-4" aria-hidden="true" />
                        Ya
                    </span>
                ) : (
                    <span className="text-muted-foreground">Tidak</span>
                ),
        },
        {
            key: 'permissions',
            header: 'Permission',
            className: 'tabular-nums',
            cell: (r) => r.permissions_count.toLocaleString('id-ID'),
        },
        {
            key: 'users',
            header: 'Pengguna',
            className: 'tabular-nums',
            cell: (r) => r.users_count.toLocaleString('id-ID'),
        },
    ];

    return (
        <>
            <Head title="Role & Hak Akses" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Role & Hak Akses</h1>
                    <p className="text-sm text-muted-foreground">
                        Kumpulan hak akses yang bisa ditetapkan ke pengguna.
                        Permission dibuat sistem; di sini Anda memilih
                        permission untuk tiap role.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar role"
                    toolbarActions={
                        canManage ? (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    Tambah Role
                                </Link>
                            </Button>
                        ) : undefined
                    }
                    rowActions={
                        canManage
                            ? (r) => {
                                  const blocker = deleteBlocker(r);

                                  return (
                                      <div className="flex justify-end gap-1">
                                          <Button
                                              variant="ghost"
                                              size="sm"
                                              asChild
                                          >
                                              <Link
                                                  href={edit(r.id)}
                                                  aria-label={`${r.read_only ? 'Lihat' : 'Ubah'} role ${r.label}`}
                                              >
                                                  <Pencil aria-hidden="true" />
                                                  {r.read_only
                                                      ? 'Lihat'
                                                      : 'Ubah'}
                                              </Link>
                                          </Button>
                                          <Button
                                              variant="ghost"
                                              size="sm"
                                              disabled={blocker !== null}
                                              title={blocker ?? undefined}
                                              aria-label={`Hapus role ${r.label}${blocker ? ` (${blocker})` : ''}`}
                                              onClick={() => setDeleting(r)}
                                          >
                                              <Trash2 aria-hidden="true" />
                                          </Button>
                                      </div>
                                  );
                              }
                            : undefined
                    }
                />
            </div>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogTitle>Hapus role {deleting?.label}?</DialogTitle>
                    <DialogDescription>
                        Role dan matriks permission-nya dihapus permanen.
                        Tindakan ini tercatat di audit.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={() => {
                                if (!deleting) {
                                    return;
                                }
                                router.delete(
                                    RoleController.destroy(deleting.id).url,
                                    {
                                        preserveScroll: true,
                                        onStart: () => setProcessing(true),
                                        // Penolakan server (role sistem/terpakai) dikirim sebagai galat `role`.
                                        onError: (errors) =>
                                            toast.error(
                                                errors.role ??
                                                    'Role tidak bisa dihapus.',
                                            ),
                                        onFinish: () => {
                                            setProcessing(false);
                                            setDeleting(null);
                                        },
                                    },
                                );
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus Role
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [{ title: 'Role & Hak Akses', href: index() }],
};
