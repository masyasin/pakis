import { Head, Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    Boxes,
    CalendarClock,
    Factory,
    Handshake,
    MapPin,
    Pencil,
    UserRound,
} from 'lucide-react';
import { revealAccount } from '@/actions/App/Http/Controllers/Admin/SupplierController';
import {
    DetailCard,
    EmptyPanel,
    Field,
    FieldGrid,
} from '@/components/employee/detail-ui';
import {
    ActionLabel,
    DetailBackLink,
    DetailHero,
} from '@/components/pakis/detail-hero';
import { MaskedValue } from '@/components/pakis/masked-value';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTimeIn } from '@/lib/approval-format';

type Supplier = {
    id: number;
    code: string;
    name: string;
    principal: string | null;
    tax_id: string | null;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    village_label: string | null;
    payment_term_days: number;
    bank_name: string | null;
    bank_account_masked: string | null;
    bank_account_name: string | null;
    is_active: boolean;
};

type SuppliedProduct = {
    product_id: number;
    sku: string;
    name: string;
    supplier_sku: string | null;
    lead_time_days: number | null;
    is_preferred: boolean;
    last_price: string | null;
    last_price_unit: string | null;
    updated_at: string | null;
};

type Props = {
    supplier: Supplier;
    products: SuppliedProduct[];
    can: { manage: boolean };
};

/** "12500.00" → "Rp12.500" tanpa float (ADR-101): pisah ribuan dari string. */
function rupiah(value: string): string {
    const [whole, cents = '00'] = value.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return cents === '00' ? `Rp${grouped}` : `Rp${grouped},${cents}`;
}

/** Detail supplier (15 §5, FR-A11). Rekening termasking; "Lihat" tercatat (SEC-108). */
export default function SupplierShow({ supplier, products, can }: Props) {
    const timezone = usePage().props.timezone;

    return (
        <>
            <Head title={supplier.name} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DetailBackLink href="/supplier" label="Daftar supplier" />

                <DetailHero
                    title={supplier.name}
                    code={supplier.code}
                    avatar={{ icon: Handshake }}
                    muted={!supplier.is_active}
                    highlights={[
                        {
                            icon: CalendarClock,
                            label: 'Tempo bayar',
                            value:
                                supplier.payment_term_days === 0
                                    ? 'Tunai'
                                    : `${supplier.payment_term_days} hari`,
                        },
                        {
                            icon: Boxes,
                            label: 'Memasok',
                            value: `${products.length} produk`,
                            tone: products.length === 0 ? 'warning' : 'default',
                        },
                    ]}
                    badges={
                        <StatusBadge
                            status={supplier.is_active ? 'active' : 'inactive'}
                            label={supplier.is_active ? 'Aktif' : 'Nonaktif'}
                            tone={supplier.is_active ? 'success' : 'muted'}
                        />
                    }
                    meta={[
                        {
                            icon: Factory,
                            label: 'Prinsipal',
                            value: supplier.principal,
                        },
                        {
                            icon: UserRound,
                            label: 'Nama kontak',
                            value: supplier.contact_name,
                        },
                        {
                            icon: MapPin,
                            label: 'Wilayah',
                            value: supplier.village_label,
                        },
                    ]}
                    // Tempo bayar & jumlah produk sudah di sorotan pita; ringkasan memuat kontak & bank.
                    summary={[
                        {
                            label: 'Supplier utama untuk',
                            value: `${products.filter((p) => p.is_preferred).length} produk`,
                        },
                        { label: 'Bank', value: supplier.bank_name ?? '–' },
                        { label: 'Telepon', value: supplier.phone ?? '–' },
                        { label: 'Email', value: supplier.email ?? '–' },
                    ]}
                    actions={
                        can.manage ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={`/supplier/${supplier.id}/ubah`}
                                    aria-label={`Ubah supplier ${supplier.name}`}
                                >
                                    <Pencil aria-hidden="true" />
                                    <ActionLabel>Ubah Supplier</ActionLabel>
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <div className="grid gap-4 lg:grid-cols-2">
                    <DetailCard title="Identitas & kontak" icon={Handshake}>
                        <FieldGrid>
                            <Field
                                label="Prinsipal"
                                value={supplier.principal}
                            />
                            <Field label="NPWP" value={supplier.tax_id} mono />
                            <Field
                                label="Tempo bayar"
                                value={
                                    supplier.payment_term_days === 0
                                        ? 'Tunai'
                                        : `${supplier.payment_term_days} hari`
                                }
                            />
                            <Field
                                label="Nama kontak"
                                value={supplier.contact_name}
                            />
                            <Field label="Telepon" value={supplier.phone} />
                            <Field label="Email" value={supplier.email} />
                            <Field
                                label="Alamat"
                                value={supplier.address}
                                wide
                            />
                            <Field
                                label="Wilayah"
                                value={supplier.village_label}
                                wide
                            />
                        </FieldGrid>
                    </DetailCard>
                    <DetailCard title="Rekening bank" icon={Banknote}>
                        <FieldGrid>
                            <Field label="Bank" value={supplier.bank_name} />
                            <Field
                                label="Atas nama"
                                value={supplier.bank_account_name}
                            />
                            <div className="sm:col-span-full">
                                <dt className="text-xs font-medium tracking-wide text-muted-foreground">
                                    Nomor rekening
                                </dt>
                                <dd className="mt-1 font-mono text-sm">
                                    <MaskedValue
                                        masked={supplier.bank_account_masked}
                                        reveal={
                                            can.manage
                                                ? revealAccount(supplier.id)
                                                : null
                                        }
                                        label="Nomor rekening"
                                        responseKey="account"
                                    />
                                </dd>
                            </div>
                        </FieldGrid>
                        <p className="mt-3 text-xs text-muted-foreground">
                            Nomor lengkap hanya bisa dibuka pengelola supplier
                            dan setiap pembukaan tercatat di log keamanan.
                        </p>
                    </DetailCard>
                </div>

                <DetailCard title="Produk dipasok" icon={Boxes}>
                    {products.length === 0 ? (
                        <EmptyPanel
                            icon={Boxes}
                            title="Belum ada produk dipasok"
                            text="Tambahkan produk yang biasa dibeli dari supplier ini lewat tombol Ubah Supplier."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr className="border-b">
                                        <th className="py-2 pr-3 font-medium">
                                            Produk
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            Kode di supplier
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            Lead time
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            Harga beli terakhir
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {products.map((p) => (
                                        <tr
                                            key={p.product_id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2.5 pr-3">
                                                <Link
                                                    href={`/produk/${p.product_id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {p.name}
                                                </Link>
                                                <span className="block font-mono text-xs text-muted-foreground">
                                                    {p.sku}
                                                    {p.is_preferred &&
                                                        ' · supplier utama'}
                                                </span>
                                            </td>
                                            <td className="py-2.5 pr-3 font-mono">
                                                {p.supplier_sku ?? '–'}
                                            </td>
                                            <td className="py-2.5 pr-3">
                                                {p.lead_time_days !== null
                                                    ? `${p.lead_time_days} hari`
                                                    : '–'}
                                            </td>
                                            <td className="py-2.5 pr-3">
                                                {p.last_price !== null ? (
                                                    <span>
                                                        {rupiah(p.last_price)}
                                                        {p.last_price_unit &&
                                                            ` / ${p.last_price_unit.toLowerCase()}`}
                                                        {p.updated_at && (
                                                            <span className="block text-xs text-muted-foreground">
                                                                {formatDateTimeIn(
                                                                    p.updated_at,
                                                                    timezone,
                                                                )}
                                                            </span>
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Belum ada penerimaan
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DetailCard>
            </div>
        </>
    );
}

SupplierShow.layout = {
    breadcrumbs: [{ title: 'Supplier', href: '/supplier' }],
};
