import { Head, Link } from '@inertiajs/react';
import {
    CustomerPhone,
    SignedPhoto,
    VERIFICATION_STATUS_TONE,
} from '@/components/customer/customer-ui';
import type { VerificationRow } from '@/components/customer/customer-ui';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatDate } from '@/lib/listing-query';
import { formatRupiah } from '@/lib/money';
import { index } from '@/routes/admin/customer-verifications';
import { show } from '@/routes/admin/customers';
import type { ListingProps } from '@/types/listing';

type QueueRow = Omit<VerificationRow, 'office_call_note'> & {
    customer: {
        id: number;
        code: string;
        store_name: string;
        owner_name: string;
        sales_area: string | null;
    } | null;
    /** HP yang dibuktikan; sudah termasking dari server bila tanpa hak lihat penuh (SEC-108). */
    phone_target: string;
};

type Props = ListingProps<QueueRow> & {
    phoneMasked: boolean;
};

const verificationHref = (customerId: number) =>
    show(customerId, { query: { tab: 'verifikasi' } }).url;

/**
 * Antrean verifikasi kios (15 §7.3 `customer_verifications`, BR-165, T-074). Bawaan: yang menunggu
 * persetujuan, terlama dulu. Keputusan diambil dari kotak masuk Persetujuan; baris ini membuka tab
 * Verifikasi detail kios. Draf "menunggu telepon kantor" belum menjadi baris (PA-49), jadi tampil
 * di detail kios saja.
 */
export default function CustomerVerificationsIndex({
    rows,
    listing,
    phoneMasked,
}: Props) {
    const columns: DataTableColumn<QueueRow>[] = [
        {
            key: 'customer',
            header: 'Kios',
            primary: true,
            cell: (r) =>
                r.customer ? (
                    <span className="grid">
                        <Link
                            href={verificationHref(r.customer.id)}
                            className="font-medium hover:underline"
                        >
                            {r.customer.store_name}
                        </Link>
                        <span className="font-mono text-xs text-muted-foreground">
                            {r.customer.code}
                        </span>
                    </span>
                ) : (
                    <span className="text-muted-foreground">–</span>
                ),
        },
        {
            key: 'photo',
            header: 'Foto',
            // Tautan bertanda tangan 5 menit dari props render ini (SEC-108); klik = tab baru.
            cell: (r) =>
                r.photo_url ? (
                    <SignedPhoto
                        url={r.photo_url}
                        alt={`Foto toko ${r.customer?.store_name ?? ''}`.trim()}
                        className="aspect-square w-14"
                    />
                ) : (
                    <span className="text-muted-foreground">–</span>
                ),
        },
        {
            key: 'owner',
            header: 'Pemilik · HP dibuktikan',
            cell: (r) => (
                <span className="grid">
                    <span>{r.customer?.owner_name ?? '–'}</span>
                    <CustomerPhone
                        phone={r.phone_target}
                        masked={phoneMasked}
                        className="text-xs text-muted-foreground"
                    />
                </span>
            ),
        },
        {
            key: 'area',
            header: 'Area',
            cell: (r) => r.customer?.sales_area ?? '–',
        },
        {
            key: 'method',
            header: 'Jenis · Bukti nomor',
            cell: (r) => (
                <span className="grid">
                    <span>{r.type_label}</span>
                    <span className="text-xs text-muted-foreground">
                        {r.method_label}
                    </span>
                </span>
            ),
        },
        {
            key: 'limit',
            header: 'Limit diminta',
            className: 'text-right tabular-nums whitespace-nowrap',
            cell: (r) => formatRupiah(r.requested_credit_limit),
        },
        {
            key: 'created_at',
            header: 'Diajukan',
            sortKey: 'created_at',
            className: 'whitespace-nowrap',
            cell: (r) => (
                <span className="grid">
                    <span className="tabular-nums">
                        {/* Server mengirim waktu lokal (`+07:00`): 10 karakter awal = tanggal lokal. */}
                        {r.created_at
                            ? formatDate(r.created_at.slice(0, 10))
                            : '–'}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {r.submitted_by?.name ?? '–'}
                    </span>
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`customer_verification.${r.status}`}
                    label={r.status_label}
                    tone={VERIFICATION_STATUS_TONE[r.status]}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Verifikasi Kios" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Verifikasi Kios
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pengajuan aktivasi kios yang menunggu persetujuan,
                        terlama di atas. Putuskan lewat menu Persetujuan; buka
                        nama kios untuk melihat detail dan riwayat
                        verifikasinya.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Antrean verifikasi"
                />
            </div>
        </>
    );
}

CustomerVerificationsIndex.layout = {
    breadcrumbs: [{ title: 'Verifikasi Kios', href: index() }],
};
