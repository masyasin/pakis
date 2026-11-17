import { Head, Link, useHttp, usePage } from '@inertiajs/react';
import { simulate } from '@/actions/App/Http/Controllers/Admin/PromotionController';
import { Calculator, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import { AsyncSelect } from '@/components/pakis/async-select';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

type PromoRow = {
    id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    starts_at: string;
    ends_at: string;
    priority: number;
    is_stackable: boolean;
    funded_by_label: string;
    status: 'draft' | 'pending_approval' | 'active' | 'ended' | 'cancelled';
    status_label: string;
};

type Option = { value: string; label: string };

type Props = ListingProps<PromoRow> & {
    channels: Option[];
    canManage: boolean;
};

export const PROMO_TONE = {
    draft: 'muted',
    pending_approval: 'warning',
    active: 'success',
    ended: 'muted',
    cancelled: 'danger',
} as const;

/** Daftar promo (15 §7.3 `promotions`, FR-A20) + simulasi harga & promo (BR-169, hanya membaca). */
export default function PromotionsIndex({
    rows,
    listing,
    channels,
    canManage,
}: Props) {
    const timezone = usePage().props.timezone;
    const [simulating, setSimulating] = useState(false);

    const columns: DataTableColumn<PromoRow>[] = [
        {
            key: 'name',
            header: 'Promo',
            primary: true,
            cell: (r) => (
                <Link href={`/promo/${r.id}/ubah`} className="hover:underline">
                    <span className="block font-medium">{r.name}</span>
                    <span className="block font-mono text-xs text-muted-foreground">
                        {r.code}
                    </span>
                </Link>
            ),
        },
        { key: 'type', header: 'Tipe', cell: (r) => r.type_label },
        {
            key: 'period',
            header: 'Periode',
            sortKey: 'starts_at',
            cell: (r) => (
                <span className="text-sm">
                    {formatDateTimeIn(r.starts_at, timezone)}
                    <span className="block text-xs text-muted-foreground">
                        s.d. {formatDateTimeIn(r.ends_at, timezone)}
                    </span>
                </span>
            ),
        },
        {
            key: 'priority',
            header: 'Prioritas',
            sortKey: 'priority',
            cell: (r) => (
                <span>
                    {r.priority}
                    <span className="block text-xs text-muted-foreground">
                        {r.is_stackable ? 'Bisa digabung' : 'Tidak digabung'}
                    </span>
                </span>
            ),
        },
        { key: 'funding', header: 'Dana', cell: (r) => r.funded_by_label },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={r.status}
                    label={r.status_label}
                    tone={PROMO_TONE[r.status]}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Promo" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Promo
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Diskon, beli X gratis Y, harga bundel, dan diskon
                        bertingkat. Promo diproses dari prioritas terkecil;
                        promo yang tidak bisa digabung mengunci barisnya dari
                        promo berikutnya.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar promo"
                    toolbarActions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setSimulating(true)}
                            >
                                <Calculator aria-hidden="true" />
                                Simulasi
                            </Button>
                            {canManage && (
                                <Button asChild>
                                    <Link href="/promo/baru">
                                        <Plus aria-hidden="true" />
                                        Tambah Promo
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />
            </div>
            {simulating && (
                <SimulateDialog
                    channels={channels}
                    onClose={() => setSimulating(false)}
                />
            )}
        </>
    );
}

type SimLine = { product_id: string; product_unit_id: string; qty: string };

type SimResult = {
    lines: {
        key: string;
        sku: string;
        name: string;
        unit: string;
        qty: string;
        unit_price: string;
        price_source: string;
        total: string;
        discount: string;
        applied: string[];
    }[];
    bonus: { code: string; name: string; qty_base: string }[];
    subtotal: string;
    discount: string;
    total: string;
};

type SimForm = {
    customer_id: number | null;
    channel: string;
    lines: { product_unit_id: number; qty: string }[];
};

/** Simulasi: hanya membaca (server tidak menulis apa pun). Harga dari daftar harga + promo aktif. */
function SimulateDialog({
    channels,
    onClose,
}: {
    channels: Option[];
    onClose: () => void;
}) {
    const [customer, setCustomer] = useState('');
    const [channel, setChannel] = useState('office');
    const [lines, setLines] = useState<SimLine[]>([
        { product_id: '', product_unit_id: '', qty: '1' },
    ]);
    const [result, setResult] = useState<SimResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const http = useHttp<SimForm, SimResult>({
        customer_id: null,
        channel: 'office',
        lines: [],
    });

    const setLine = (i: number, patch: Partial<SimLine>) =>
        setLines(lines.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        http.transform(() => ({
            customer_id: customer === '' ? null : Number(customer),
            channel,
            lines: lines
                .filter((l) => l.product_unit_id !== '')
                .map((l) => ({
                    product_unit_id: Number(l.product_unit_id),
                    qty: l.qty,
                })),
        }));
        http.submit(simulate(), {
            onSuccess: (res) => setResult(res),
            onHttpException: () => {
                setResult(null);
                setError(
                    'Harga tidak ditemukan untuk salah satu produk/satuan, atau isian belum benar.',
                );
            },
            onNetworkError: () => setError('Koneksi terputus. Coba lagi.'),
        }).catch(() => {
            // Ditangani callback di atas.
        });
    };
    const errs = http.errors as Record<string, string | undefined>;
    const processing = http.processing;

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Calculator}
            size="lg"
            title="Simulasi harga & promo"
            description="Menghitung harga dari daftar harga dan promo yang aktif sekarang. Tidak menyimpan apa pun."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Tutup
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Hitung
                    </Button>
                </>
            }
        >
            <FormStatus
                tone="error"
                message={
                    error ??
                    errs.lines ??
                    errs['lines.0.product_unit_id'] ??
                    errs['lines.0.qty']
                }
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="sim-customer">Kios (opsional)</Label>
                    <AsyncSelect
                        id="sim-customer"
                        source="customers"
                        value={customer === '' ? [] : [customer]}
                        onChange={(v) => setCustomer(String(v[0] ?? ''))}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="sim-channel">Kanal</Label>
                    <NativeSelect
                        id="sim-channel"
                        value={channel}
                        onChange={(e) => setChannel(e.target.value)}
                    >
                        {channels.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            </div>
            <ul className="space-y-2">
                {lines.map((line, i) => (
                    <li
                        key={i}
                        className="grid gap-2 sm:grid-cols-[2fr_1fr_6rem_auto] sm:items-end"
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor={`sim-product-${i}`}>Produk</Label>
                            <AsyncSelect
                                id={`sim-product-${i}`}
                                source="products"
                                value={
                                    line.product_id === ''
                                        ? []
                                        : [line.product_id]
                                }
                                onChange={(v) =>
                                    setLine(i, {
                                        product_id: String(v[0] ?? ''),
                                        product_unit_id: '',
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`sim-unit-${i}`}>Satuan</Label>
                            <AsyncSelect
                                id={`sim-unit-${i}`}
                                source="product_units"
                                params={{ parent: line.product_id }}
                                disabled={line.product_id === ''}
                                value={
                                    line.product_unit_id === ''
                                        ? []
                                        : [line.product_unit_id]
                                }
                                onChange={(v) =>
                                    setLine(i, {
                                        product_unit_id: String(v[0] ?? ''),
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`sim-qty-${i}`}>Jumlah</Label>
                            <Input
                                id={`sim-qty-${i}`}
                                inputMode="decimal"
                                value={line.qty}
                                onChange={(e) =>
                                    setLine(i, { qty: e.target.value })
                                }
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={lines.length === 1}
                            aria-label="Hapus baris"
                            onClick={() =>
                                setLines(lines.filter((_, idx) => idx !== i))
                            }
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                    </li>
                ))}
            </ul>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                    setLines([
                        ...lines,
                        { product_id: '', product_unit_id: '', qty: '1' },
                    ])
                }
            >
                <Plus aria-hidden="true" />
                Tambah Baris
            </Button>

            {result && (
                <div
                    className="space-y-2 rounded-lg border p-3 text-sm"
                    aria-live="polite"
                >
                    {result.lines.map((l) => (
                        <div
                            key={l.key}
                            className="flex flex-wrap justify-between gap-2 border-b pb-2 last:border-0"
                        >
                            <span>
                                <span className="font-medium">{l.name}</span>
                                <span className="block text-xs text-muted-foreground">
                                    {formatQty(l.qty)} {l.unit.toLowerCase()} ×{' '}
                                    {formatRupiah(l.unit_price)} (
                                    {l.price_source})
                                    {l.applied.length > 0 &&
                                        ` · promo ${l.applied.join(', ')}`}
                                </span>
                            </span>
                            <span className="text-right tabular-nums">
                                {formatRupiah(l.total)}
                                {l.discount !== '0.00' && (
                                    <span className="block text-xs text-muted-foreground">
                                        hemat {formatRupiah(l.discount)}
                                    </span>
                                )}
                            </span>
                        </div>
                    ))}
                    {result.bonus.map((b, i) => (
                        <p key={i} className="text-xs">
                            Bonus {b.code}: {b.name} × {formatQty(b.qty_base)}{' '}
                            (satuan dasar)
                        </p>
                    ))}
                    <p className="flex justify-between font-semibold">
                        <span>Total</span>
                        <span className="tabular-nums">
                            {formatRupiah(result.total)}
                        </span>
                    </p>
                </div>
            )}
        </FormDialog>
    );
}

PromotionsIndex.layout = {
    breadcrumbs: [{ title: 'Promo', href: '/promo' }],
};
