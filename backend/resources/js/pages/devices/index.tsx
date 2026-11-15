import { Head, router } from '@inertiajs/react';
import { Ban, Check, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatRelativeTime } from '@/lib/relative-time';
import {
    approve,
    exportMethod,
    index,
    reject,
    revoke,
} from '@/routes/admin/devices';
import type { ListingProps } from '@/types/listing';

export type DeviceRow = {
    id: number;
    user: { id: number; name: string; username: string };
    employee: {
        id: number;
        employee_number: string;
        full_name: string;
    } | null;
    model: string | null;
    os_version: string | null;
    app_version: string | null;
    status: 'pending' | 'active' | 'revoked' | 'replaced' | 'rejected';
    request_reason: string | null;
    created_at: string | null;
    last_seen_at: string | null;
};

type Props = ListingProps<DeviceRow> & { can: { manage: boolean } };

type Pending = { kind: 'approve' | 'reject' | 'revoke'; device: DeviceRow };

const columns: DataTableColumn<DeviceRow>[] = [
    {
        key: 'user',
        header: 'Pengguna',
        primary: true,
        cell: (r) => (
            <span className="grid">
                <span className="font-medium">{r.user.name}</span>
                <span className="text-xs font-normal text-muted-foreground">
                    @{r.user.username}
                    {r.employee && ` · ${r.employee.employee_number}`}
                </span>
            </span>
        ),
    },
    {
        key: 'model',
        header: 'Perangkat',
        cell: (r) => (
            <span className="grid">
                <span>{r.model ?? '–'}</span>
                <span className="text-xs text-muted-foreground">
                    {r.os_version ?? '–'}
                </span>
            </span>
        ),
    },
    {
        key: 'app_version',
        header: 'Versi app',
        cell: (r) => (
            <span className="font-mono text-xs">{r.app_version ?? '–'}</span>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => (
            <span className="grid justify-items-start gap-0.5">
                <StatusBadge status={`device.${r.status}`} />
                {r.status === 'rejected' && r.request_reason && (
                    <span className="text-xs text-muted-foreground">
                        {r.request_reason}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'created_at',
        header: 'Didaftarkan',
        sortKey: 'created_at',
        cell: (r) => (r.created_at ? formatRelativeTime(r.created_at) : '–'),
    },
    {
        key: 'last_seen_at',
        header: 'Terakhir aktif',
        sortKey: 'last_seen_at',
        cell: (r) =>
            r.last_seen_at ? (
                formatRelativeTime(r.last_seen_at)
            ) : (
                <span className="text-muted-foreground">Belum pernah</span>
            ),
    },
];

const COPY = {
    approve: {
        title: 'Setujui perangkat',
        description:
            'HP lama milik akun ini (bila ada) otomatis diganti dan keluar dari Pakis Pro. Minta pemilik masuk ulang dari HP baru.',
        button: 'Setujui',
    },
    reject: {
        title: 'Tolak perangkat',
        description:
            'Pemilik tidak bisa memakai Pakis Pro dari HP ini. Tulis alasannya agar pemilik tahu.',
        button: 'Tolak',
    },
    revoke: {
        title: 'Cabut perangkat',
        description:
            'Pakis Pro di HP ini langsung keluar dan tidak bisa absen lagi. Pakai saat HP hilang, dijual, atau pegawai keluar.',
        button: 'Cabut',
    },
} as const;

/** Perangkat Pakis Pro (05 §4.8, FR-034, BR-040). */
export default function DevicesIndex({ rows, listing, can }: Props) {
    const [pending, setPending] = useState<Pending | null>(null);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const open = (kind: Pending['kind'], device: DeviceRow) => {
        setReason('');
        setReasonError(undefined);
        setPending({ kind, device });
    };

    const submit = () => {
        if (!pending) {
            return;
        }

        const target = {
            approve: approve(pending.device.id),
            reject: reject(pending.device.id),
            revoke: revoke(pending.device.id),
        }[pending.kind];

        router.post(target.url, pending.kind === 'reject' ? { reason } : {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: () => setPending(null),
            onError: (errors) => {
                if (errors.reason) {
                    setReasonError(errors.reason);

                    return;
                }
                toast.error(errors.device ?? 'Perangkat tidak bisa diproses.');
                setPending(null);
            },
            onFinish: () => setProcessing(false),
        });
    };

    const copy = pending ? COPY[pending.kind] : null;

    return (
        <>
            <Head title="Perangkat" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Perangkat
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Satu akun hanya bisa absen dari satu HP, dan satu HP
                        hanya untuk satu akun. HP baru perlu disetujui di sini.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar perangkat"
                    toolbarActions={
                        <ExportButton
                            href={exportMethod().url}
                            total={listing.pagination.total}
                        />
                    }
                    rowActions={
                        can.manage
                            ? (r) => (
                                  <div className="flex flex-wrap justify-end gap-1">
                                      {r.status === 'pending' && (
                                          <>
                                              <Button
                                                  size="sm"
                                                  aria-label={`Setujui perangkat ${r.user.name}`}
                                                  onClick={() =>
                                                      open('approve', r)
                                                  }
                                              >
                                                  <Check aria-hidden="true" />
                                                  Setujui
                                              </Button>
                                              <Button
                                                  size="sm"
                                                  variant="ghost"
                                                  aria-label={`Tolak perangkat ${r.user.name}`}
                                                  onClick={() =>
                                                      open('reject', r)
                                                  }
                                              >
                                                  <X aria-hidden="true" />
                                                  Tolak
                                              </Button>
                                          </>
                                      )}
                                      {r.status === 'active' && (
                                          <Button
                                              size="sm"
                                              variant="ghost"
                                              aria-label={`Cabut perangkat ${r.user.name}`}
                                              onClick={() => open('revoke', r)}
                                          >
                                              <Ban aria-hidden="true" />
                                              Cabut
                                          </Button>
                                      )}
                                  </div>
                              )
                            : undefined
                    }
                />
            </div>

            <Dialog
                open={pending !== null}
                onOpenChange={(isOpen) => !isOpen && setPending(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {copy?.title} {pending?.device.user.name}?
                    </DialogTitle>
                    <DialogDescription>
                        {pending?.device.model ?? 'Perangkat'} ·{' '}
                        {pending?.device.os_version ?? '–'}. {copy?.description}
                    </DialogDescription>
                    {pending?.kind === 'reject' && (
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Alasan</Label>
                            <textarea
                                id="reason"
                                className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                value={reason}
                                maxLength={255}
                                rows={2}
                                placeholder="Mis. HP milik keluarga, bukan HP pegawai."
                                onChange={(e) => setReason(e.target.value)}
                                aria-invalid={reasonError ? true : undefined}
                            />
                            <InputError message={reasonError} />
                        </div>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button
                            variant={
                                pending?.kind === 'approve'
                                    ? 'default'
                                    : 'destructive'
                            }
                            disabled={processing}
                            onClick={submit}
                        >
                            {processing && <Spinner />}
                            {copy?.button}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

DevicesIndex.layout = {
    breadcrumbs: [{ title: 'Perangkat', href: index() }],
};
