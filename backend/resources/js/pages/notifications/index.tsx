import { Head, Link } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { index, read, readAll } from '@/routes/admin/notifications';
import type { ListingProps } from '@/types/listing';

type NotificationRow = {
    id: string;
    type: string;
    type_label: string;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    created_at: string | null;
};

const columns: DataTableColumn<NotificationRow>[] = [
    {
        key: 'created_at',
        header: 'Waktu',
        sortKey: 'created_at',
        className: 'whitespace-nowrap',
        cell: (r) => r.created_at ?? '–',
    },
    {
        key: 'title',
        header: 'Judul',
        primary: true,
        cell: (r) => (
            // POST tandai dibaca; bila ada tautan langsung dibuka (server hanya menerima path internal).
            <Link
                href={read(r.id, { query: r.url ? { open: 1 } : {} })}
                method="post"
                as="button"
                preserveScroll
                className={cn(
                    'inline-flex items-center gap-2 text-left hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    r.read ? 'font-normal' : 'font-bold',
                )}
            >
                {!r.read && (
                    <span
                        aria-hidden="true"
                        className="size-2 shrink-0 rounded-full bg-primary"
                    />
                )}
                {r.title}
            </Link>
        ),
    },
    {
        key: 'body',
        header: 'Isi',
        className: 'max-w-[28rem] whitespace-normal',
        cell: (r) => (
            <span className="line-clamp-2 text-muted-foreground">{r.body}</span>
        ),
    },
    {
        key: 'type',
        header: 'Jenis',
        className: 'whitespace-nowrap',
        cell: (r) => r.type_label,
    },
    {
        key: 'read',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={r.read ? 'notification.read' : 'notification.unread'}
            />
        ),
    },
];

/** Notifikasi milik sendiri (05 §4.2, BR-070). */
export default function NotificationsIndex({
    rows,
    listing,
}: ListingProps<NotificationRow>) {
    return (
        <>
            <Head title="Notifikasi" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Notifikasi
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pemberitahuan untuk akun Anda. Klik judul untuk menandai
                        dibaca dan membuka halamannya.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar notifikasi"
                    rowKey={(r) => r.id}
                    toolbarActions={
                        <Button asChild size="sm" variant="outline">
                            <Link
                                href={readAll()}
                                method="post"
                                as="button"
                                preserveScroll
                            >
                                <CheckCheck className="size-4" aria-hidden />
                                Tandai semua dibaca
                            </Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [{ title: 'Notifikasi', href: index() }],
};
