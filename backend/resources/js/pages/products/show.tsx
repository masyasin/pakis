import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Barcode,
    BadgeCheck,
    Boxes,
    Factory,
    FlaskConical,
    History,
    Info,
    Layers,
    Package,
    Pencil,
    Plus,
    Ruler,
    ShieldAlert,
    ShieldCheck,
    ShieldX,
    Tag,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { RegistrationRow } from '@/components/catalog/registration-ui';
import { RegistrationsPanel } from '@/components/catalog/registrations-panel';
import type { RegistrationState } from '@/components/catalog/registrations-panel';
import { FormStatus } from '@/components/form-status';
import {
    DetailCard,
    EmptyPanel,
    Field,
    FieldGrid,
    Timeline,
    TimelineItem,
} from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import {
    ActionLabel,
    DetailBackLink,
    DetailHero,
} from '@/components/pakis/detail-hero';
import type { DetailHeroHighlight } from '@/components/pakis/detail-hero';
import { DetailTabs, useQueryTab } from '@/components/pakis/detail-tabs';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatDate } from '@/lib/listing-query';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';

type FlagKey =
    | 'tracks_batch'
    | 'tracks_expiry'
    | 'tracks_serial'
    | 'requires_registration';

type Product = Record<FlagKey, boolean> & {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    kind_label: string | null;
    brand: string | null;
    principal: string | null;
    active_ingredient: string | null;
    formulation: string | null;
    package_size: string | null;
    base_unit: string;
    description: string | null;
    usage_note: string | null;
    is_restricted: boolean;
    restricted_note: string | null;
    warranty_months: number | null;
    min_shelf_life_months: number | null;
    is_sellable: boolean;
    is_purchasable: boolean;
    status: 'active' | 'inactive' | 'discontinued';
    status_label: string;
    photo_url: string | null;
    category_flags: Record<FlagKey, boolean>;
};

type UnitRow = {
    id: number;
    unit_id: number;
    unit: string;
    factor: string;
    factor_label: string;
    integer_only: boolean;
    is_base: boolean;
    is_default_sales: boolean;
    is_default_purchase: boolean;
    is_active: boolean;
    barcodes: { id: number; barcode: string }[];
};

type PriceRow = {
    list_id: number;
    list: string;
    kind_label: string;
    version_id: number;
    version_no: number;
    unit: string;
    min_qty: string;
    price: string;
};

type HistoryRow = {
    id: number;
    occurred_at: string;
    actor: string;
    event_label: string;
    reason: string | null;
};

type Props = {
    product: Product;
    units: UnitRow[];
    unitOptions: { value: number; label: string; integer_only: boolean }[];
    registrations: RegistrationRow[];
    registrationState: RegistrationState;
    history?: HistoryRow[];
    /** null = pengguna tanpa izin `prices.view` (tab Harga disembunyikan). */
    prices?: PriceRow[] | null;
    can: { manage: boolean; manage_registrations: boolean };
};

const SAVE_OPTIONS = { preserveScroll: true, preserveState: true } as const;

const STATUS_TONE = {
    active: 'success',
    inactive: 'muted',
    discontinued: 'warning',
} as const;

const FLAG_LABELS: Record<FlagKey, string> = {
    tracks_batch: 'Lacak nomor batch',
    tracks_expiry: 'Lacak tanggal kedaluwarsa',
    tracks_serial: 'Lacak nomor seri',
    requires_registration: 'Wajib izin edar',
};

const TABS = [
    { key: 'data', label: 'Data', icon: Info },
    { key: 'satuan', label: 'Satuan & Barcode', icon: Ruler },
    { key: 'izin', label: 'Izin Edar', icon: ShieldCheck },
    { key: 'harga', label: 'Harga', icon: Tag },
    { key: 'stok', label: 'Stok', icon: Boxes },
    { key: 'riwayat', label: 'Riwayat', icon: History },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const yesNo = (v: boolean) => (v ? 'Ya' : 'Tidak');

/**
 * Sorotan izin edar di pita kepala (BR-152): status yang sama dengan tab Izin Edar, diringkas.
 * Baris berlaku = baris aktif pertama (server mengurutkan aktif dulu, lalu berlaku terjauh).
 */
function registrationHighlight(
    required: boolean,
    state: RegistrationState,
    rows: RegistrationRow[],
): DetailHeroHighlight {
    const current = rows.find((r) => r.status === 'active');
    const label = 'Izin edar';

    if (state === 'active' && current) {
        return {
            icon: ShieldCheck,
            label,
            value: `s/d ${formatDate(current.valid_until)}`,
            tone: 'success',
        };
    }

    if (state === 'expiring' && current) {
        return {
            icon: ShieldAlert,
            label,
            value: `Akan habis ${formatDate(current.valid_until)}`,
            tone: 'warning',
        };
    }

    if (state === 'expired') {
        return {
            icon: ShieldX,
            label,
            value: 'Habis',
            tone: required ? 'danger' : 'warning',
        };
    }

    return required
        ? { icon: ShieldX, label, value: 'Belum ada', tone: 'danger' }
        : { icon: ShieldCheck, label, value: 'Tidak wajib' };
}

/** Detail produk bertab (15 §7.3): data, satuan & barcode, lalu tab modul berikutnya. */
export default function ProductShow({
    product,
    units,
    unitOptions,
    registrations,
    registrationState,
    history,
    prices,
    can,
}: Props) {
    // Tab Harga hanya untuk pemegang prices.view (server mengirim prices = null bila tidak berhak).
    const tabs = TABS.filter((t) => t.key !== 'harga' || prices !== null);
    const active = useQueryTab<TabKey>(
        tabs.map((t) => t.key),
        'data',
    );

    return (
        <>
            <Head title={product.name} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DetailBackLink href="/produk" label="Daftar produk" />

                <DetailHero
                    title={product.name}
                    code={product.sku}
                    avatar={{
                        photoUrl: product.photo_url,
                        photoAlt: `Foto ${product.name}`,
                        icon: Package,
                        shape: 'square',
                    }}
                    muted={product.status !== 'active'}
                    highlights={[
                        registrationHighlight(
                            product.requires_registration,
                            registrationState,
                            registrations,
                        ),
                    ]}
                    badges={
                        <>
                            <StatusBadge
                                status={product.status}
                                label={product.status_label}
                                tone={STATUS_TONE[product.status]}
                            />
                            {product.is_restricted && (
                                <StatusBadge
                                    status="restricted"
                                    label="Terbatas"
                                    tone="danger"
                                />
                            )}
                        </>
                    }
                    meta={[
                        {
                            icon: Layers,
                            label: 'Kategori',
                            value: product.category
                                ? `${product.category}${product.kind_label ? ` · ${product.kind_label}` : ''}`
                                : null,
                        },
                        {
                            icon: Factory,
                            label: 'Prinsipal',
                            value: product.principal,
                        },
                        { icon: Tag, label: 'Merek', value: product.brand },
                        {
                            icon: FlaskConical,
                            label: 'Bahan aktif',
                            value: product.active_ingredient,
                        },
                    ]}
                    summary={[
                        { label: 'Satuan dasar', value: product.base_unit },
                        {
                            label: 'Ukuran kemasan',
                            value: product.package_size ?? '–',
                        },
                        { label: 'Dijual', value: yesNo(product.is_sellable) },
                        {
                            label: 'Dibeli',
                            value: yesNo(product.is_purchasable),
                        },
                    ]}
                    actions={
                        can.manage ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={`/produk/${product.id}/ubah`}
                                    aria-label={`Ubah produk ${product.name}`}
                                >
                                    <Pencil aria-hidden="true" />
                                    <ActionLabel>Ubah Produk</ActionLabel>
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <DetailTabs
                    tabs={[...tabs]}
                    active={active}
                    label="Bagian produk"
                    hrefFor={(key) =>
                        key === 'data'
                            ? `/produk/${product.id}`
                            : `/produk/${product.id}?tab=${key}`
                    }
                />

                {active === 'data' && <DataTab product={product} />}
                {active === 'satuan' && (
                    <UnitsTab
                        product={product}
                        units={units}
                        unitOptions={unitOptions}
                        canManage={can.manage}
                    />
                )}
                {active === 'izin' && (
                    <RegistrationsPanel
                        productId={product.id}
                        requiresRegistration={product.requires_registration}
                        state={registrationState}
                        rows={registrations}
                        canManage={can.manage_registrations}
                    />
                )}
                {active === 'harga' && prices !== null && (
                    <PricesTab rows={prices ?? []} />
                )}
                {active === 'stok' && (
                    <EmptyPanel
                        icon={Boxes}
                        title="Stok belum tersedia"
                        text="Saldo per lokasi dan batch tampil setelah modul stok aktif."
                    />
                )}
                {active === 'riwayat' && <HistoryTab rows={history ?? []} />}
            </div>
        </>
    );
}

function DataTab({ product }: { product: Product }) {
    const flags = Object.keys(FLAG_LABELS) as FlagKey[];

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <DetailCard title="Identitas" icon={Package}>
                <FieldGrid>
                    <Field label="SKU" value={product.sku} mono />
                    <Field label="Prinsipal" value={product.principal} />
                    <Field label="Merek" value={product.brand} />
                    <Field
                        label="Bahan aktif"
                        value={product.active_ingredient}
                    />
                    <Field label="Formulasi" value={product.formulation} />
                    <Field
                        label="Ukuran kemasan"
                        value={product.package_size}
                    />
                    <Field
                        label="Satuan dasar stok"
                        value={product.base_unit}
                    />
                    <Field label="Deskripsi" value={product.description} wide />
                    <Field
                        label="Petunjuk pemakaian"
                        value={product.usage_note}
                        wide
                    />
                </FieldGrid>
            </DetailCard>
            <div className="space-y-4">
                <DetailCard title="Penanda perilaku" icon={BadgeCheck}>
                    <ul className="space-y-2.5">
                        {flags.map((key) => {
                            const relaxed =
                                product.category_flags[key] && !product[key];

                            return (
                                <li
                                    key={key}
                                    className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                >
                                    <span>{FLAG_LABELS[key]}</span>
                                    <span className="flex items-center gap-1.5">
                                        {relaxed && (
                                            <StatusBadge
                                                status="relaxed"
                                                label="Dilonggarkan"
                                                tone="warning"
                                            />
                                        )}
                                        <StatusBadge
                                            status={product[key] ? 'on' : 'off'}
                                            label={
                                                product[key] ? 'Aktif' : 'Tidak'
                                            }
                                            tone={
                                                product[key]
                                                    ? 'success'
                                                    : 'muted'
                                            }
                                        />
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Alasan pelonggaran tercatat di tab Riwayat.
                    </p>
                </DetailCard>
                <DetailCard title="Penjualan & penyimpanan" icon={Tag}>
                    <FieldGrid>
                        <Field
                            label="Bisa dijual"
                            value={yesNo(product.is_sellable)}
                        />
                        <Field
                            label="Bisa dibeli"
                            value={yesNo(product.is_purchasable)}
                        />
                        <Field
                            label="Pestisida terbatas"
                            value={yesNo(product.is_restricted)}
                            hint={product.restricted_note ?? undefined}
                        />
                        <Field
                            label="Garansi"
                            value={
                                product.warranty_months !== null
                                    ? `${product.warranty_months} bulan`
                                    : null
                            }
                        />
                        <Field
                            label="Sisa umur simpan minimal"
                            value={
                                product.min_shelf_life_months !== null
                                    ? `${product.min_shelf_life_months} bulan`
                                    : 'Ikut pengaturan umum'
                            }
                        />
                    </FieldGrid>
                </DetailCard>
            </div>
        </div>
    );
}

function UnitsTab({
    product,
    units,
    unitOptions,
    canManage,
}: {
    product: Product;
    units: UnitRow[];
    unitOptions: Props['unitOptions'];
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<UnitRow | 'new' | null>(null);
    const [barcodeFor, setBarcodeFor] = useState<UnitRow | null>(null);
    const [removing, setRemoving] = useState<{
        unit: UnitRow;
        id: number;
        barcode: string;
    } | null>(null);
    const used = new Set(units.map((u) => u.unit_id));

    return (
        <>
            <DetailCard
                title="Satuan & konversi"
                icon={Ruler}
                action={
                    canManage && (
                        <Button size="sm" onClick={() => setEditing('new')}>
                            <Plus aria-hidden="true" />
                            Tambah Satuan
                        </Button>
                    )
                }
            >
                <p className="mb-4 text-sm text-muted-foreground">
                    Stok selalu disimpan dalam {product.base_unit.toLowerCase()}
                    . Faktor = isi satu satuan dalam satuan dasar.
                </p>
                <ul className="divide-y rounded-lg border">
                    {units.map((u) => (
                        <li key={u.id} className="space-y-3 p-3 sm:p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <p className="font-semibold">
                                        {u.unit}
                                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                                            {u.is_base
                                                ? 'satuan dasar'
                                                : `= ${u.factor_label} ${product.base_unit.toLowerCase()}`}
                                        </span>
                                    </p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {u.is_default_sales && (
                                            <StatusBadge
                                                status="sales"
                                                label="Default jual"
                                                tone="info"
                                            />
                                        )}
                                        {u.is_default_purchase && (
                                            <StatusBadge
                                                status="purchase"
                                                label="Default beli"
                                                tone="info"
                                            />
                                        )}
                                        {!u.is_active && (
                                            <StatusBadge
                                                status="inactive"
                                                label="Nonaktif"
                                                tone="muted"
                                            />
                                        )}
                                    </div>
                                </div>
                                {canManage && (
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setBarcodeFor(u)}
                                        >
                                            <Barcode aria-hidden="true" />
                                            Tambah Barcode
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(u)}
                                            aria-label={`Ubah satuan ${u.unit}`}
                                        >
                                            <Pencil aria-hidden="true" />
                                            Ubah
                                        </Button>
                                    </div>
                                )}
                            </div>
                            {u.barcodes.length > 0 ? (
                                <ul
                                    className="flex flex-wrap gap-2"
                                    aria-label={`Barcode ${u.unit}`}
                                >
                                    {u.barcodes.map((b) => (
                                        <li
                                            key={b.id}
                                            className="inline-flex items-center gap-1.5 rounded-md border bg-muted/50 py-1 pr-1 pl-2.5 font-mono text-xs"
                                        >
                                            {b.barcode}
                                            {canManage && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setRemoving({
                                                            unit: u,
                                                            ...b,
                                                        })
                                                    }
                                                    className="grid size-6 place-items-center rounded text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    aria-label={`Hapus barcode ${b.barcode}`}
                                                >
                                                    <Trash2
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Belum ada barcode.
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            </DetailCard>

            {editing !== null && (
                <UnitDialog
                    product={product}
                    unit={editing === 'new' ? null : editing}
                    unitOptions={unitOptions.filter(
                        (o) => editing !== 'new' || !used.has(o.value),
                    )}
                    onClose={() => setEditing(null)}
                />
            )}
            {barcodeFor !== null && (
                <BarcodeDialog
                    product={product}
                    unit={barcodeFor}
                    onClose={() => setBarcodeFor(null)}
                />
            )}
            <RemoveBarcodeDialog
                product={product}
                target={removing}
                onClose={() => setRemoving(null)}
            />
        </>
    );
}

function UnitDialog({
    product,
    unit,
    unitOptions,
    onClose,
}: {
    product: Product;
    unit: UnitRow | null;
    unitOptions: Props['unitOptions'];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [form, setForm] = useState({
        unit_id: String(unit?.unit_id ?? unitOptions[0]?.value ?? ''),
        factor: unit ? unit.factor.replace(/\.?0+$/, '').replace('.', ',') : '',
        is_default_sales: unit?.is_default_sales ?? false,
        is_default_purchase: unit?.is_default_purchase ?? false,
        is_active: unit?.is_active ?? true,
    });
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const payload: Record<string, string | boolean> = {
            ...form,
            factor: unit?.is_base ? '1' : form.factor,
        };
        if (unit) {
            delete payload.unit_id;
        }
        const options = {
            ...SAVE_OPTIONS,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        };

        if (unit) {
            router.put(
                `/produk/${product.id}/satuan/${unit.id}`,
                payload,
                options,
            );
        } else {
            router.post(`/produk/${product.id}/satuan`, payload, options);
        }
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Ruler}
            title={unit ? `Ubah satuan · ${unit.unit}` : 'Tambah satuan'}
            description={`Faktor = berapa ${product.base_unit.toLowerCase()} dalam satu satuan ini. Maksimal 3 desimal.`}
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {unit ? 'Simpan Satuan' : 'Tambah Satuan'}
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.form} />
            {!unit && (
                <div className="grid gap-2">
                    <Label htmlFor="unit-id">Satuan</Label>
                    <NativeSelect
                        id="unit-id"
                        value={form.unit_id}
                        onChange={(e) =>
                            setForm((f) => ({ ...f, unit_id: e.target.value }))
                        }
                    >
                        {unitOptions.map((o) => (
                            <option key={o.value} value={String(o.value)}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.unit_id} />
                </div>
            )}
            <div className="grid gap-2">
                <Label htmlFor="unit-factor">
                    Faktor ke {product.base_unit.toLowerCase()}
                </Label>
                <Input
                    id="unit-factor"
                    inputMode="decimal"
                    value={unit?.is_base ? '1' : form.factor}
                    disabled={unit?.is_base}
                    onChange={(e) =>
                        setForm((f) => ({ ...f, factor: e.target.value }))
                    }
                    placeholder="mis. 24"
                    aria-invalid={!!errors.factor || undefined}
                />
                {unit?.is_base && (
                    <p className="text-xs text-muted-foreground">
                        Faktor satuan dasar selalu 1.
                    </p>
                )}
                <InputError message={errors.factor} />
            </div>
            <div className="grid gap-3">
                {(
                    [
                        ['is_default_sales', 'Default saat menjual'],
                        ['is_default_purchase', 'Default saat membeli'],
                        ['is_active', 'Aktif'],
                    ] as const
                ).map(([key, label]) => (
                    <div key={key} className="flex items-center gap-3">
                        <Checkbox
                            id={`unit-${key}`}
                            checked={form[key]}
                            disabled={key === 'is_active' && unit?.is_base}
                            onCheckedChange={(v) =>
                                setForm((f) => ({ ...f, [key]: v === true }))
                            }
                        />
                        <Label htmlFor={`unit-${key}`}>{label}</Label>
                    </div>
                ))}
                <p className="text-xs text-muted-foreground">
                    Hanya satu satuan default jual dan satu default beli;
                    memilih yang baru menggantikan yang lama.
                </p>
            </div>
        </FormDialog>
    );
}

function BarcodeDialog({
    product,
    unit,
    onClose,
}: {
    product: Product;
    unit: UnitRow;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [barcode, setBarcode] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            `/produk/${product.id}/barcode`,
            { product_unit_id: unit.id, barcode: barcode.trim() },
            {
                ...SAVE_OPTIONS,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Barcode}
            size="sm"
            title={`Tambah barcode · ${unit.unit}`}
            description="Scan atau ketik. Satu barcode hanya boleh dipakai satu produk."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        disabled={processing || barcode.trim() === ''}
                    >
                        Tambah Barcode
                    </Button>
                </>
            }
        >
            <div className="grid gap-2">
                <Label htmlFor="barcode-value">Barcode</Label>
                <Input
                    id="barcode-value"
                    value={barcode}
                    onChange={(e) => setBarcode(e.target.value)}
                    className="font-mono"
                    maxLength={64}
                    autoFocus
                    autoComplete="off"
                    aria-invalid={!!errors.barcode || undefined}
                />
                <InputError
                    message={errors.barcode ?? errors.product_unit_id}
                />
            </div>
        </FormDialog>
    );
}

function RemoveBarcodeDialog({
    product,
    target,
    onClose,
}: {
    product: Product;
    target: { unit: UnitRow; id: number; barcode: string } | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (!target) {
            return;
        }
        router.delete(`/produk/${product.id}/barcode/${target.id}`, {
            ...SAVE_OPTIONS,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open={target !== null}
            onOpenChange={(o) => !o && onClose()}
            icon={Trash2}
            tone="danger"
            size="sm"
            title={`Hapus barcode ${target?.barcode ?? ''}?`}
            description={`Barcode ini tidak lagi dikenali saat scan untuk ${target?.unit.unit.toLowerCase() ?? ''} ${product.name}.`}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={confirm}
                    >
                        Hapus Barcode
                    </Button>
                </>
            }
        >
            <span className="sr-only">Konfirmasi hapus barcode</span>
        </FormDialog>
    );
}

function HistoryTab({ rows }: { rows: HistoryRow[] }) {
    const timezone = usePage().props.timezone;

    if (rows.length === 0) {
        return (
            <EmptyPanel
                icon={History}
                title="Belum ada riwayat"
                text="Perubahan produk dan satuannya akan tercatat di sini."
            />
        );
    }

    return (
        <DetailCard title="Riwayat perubahan" icon={History}>
            <Timeline>
                {rows.map((r, i) => (
                    <TimelineItem
                        key={r.id}
                        active={i === 0}
                        last={i === rows.length - 1}
                    >
                        <div className="space-y-0.5">
                            <p className="text-sm font-medium">
                                {r.event_label}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {formatDateTimeIn(r.occurred_at, timezone)} oleh{' '}
                                {r.actor}
                            </p>
                            {r.reason && (
                                <p className="text-sm">Alasan: {r.reason}</p>
                            )}
                        </div>
                    </TimelineItem>
                ))}
            </Timeline>
        </DetailCard>
    );
}

/** Harga berlaku sekarang per daftar harga (T-075, hanya baca; ubah lewat menu Daftar Harga). */
function PricesTab({ rows }: { rows: PriceRow[] }) {
    if (rows.length === 0) {
        return (
            <EmptyPanel
                icon={Tag}
                title="Belum ada harga berlaku"
                text="Produk ini belum ada di versi daftar harga yang sedang berlaku. Isi lewat menu Penjualan › Daftar Harga."
            />
        );
    }

    return (
        <DetailCard title="Harga berlaku sekarang" icon={Tag}>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted-foreground">
                        <tr className="border-b">
                            <th className="py-2 pr-3 font-medium">
                                Daftar harga
                            </th>
                            <th className="py-2 pr-3 font-medium">Satuan</th>
                            <th className="py-2 pr-3 font-medium">
                                Mulai jumlah
                            </th>
                            <th className="py-2 pr-3 font-medium">Harga</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r, i) => (
                            <tr
                                key={`${r.version_id}-${i}`}
                                className="border-b last:border-0"
                            >
                                <td className="py-2.5 pr-3">
                                    <Link
                                        href={`/daftar-harga/versi/${r.version_id}/item`}
                                        className="font-medium hover:underline"
                                    >
                                        {r.list}
                                    </Link>
                                    <span className="block text-xs text-muted-foreground">
                                        {r.kind_label} · versi {r.version_no}
                                    </span>
                                </td>
                                <td className="py-2.5 pr-3">{r.unit}</td>
                                <td className="py-2.5 pr-3">
                                    ≥ {formatQty(r.min_qty)}
                                </td>
                                <td className="py-2.5 pr-3 font-medium tabular-nums">
                                    {formatRupiah(r.price)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </DetailCard>
    );
}

ProductShow.layout = {
    breadcrumbs: [{ title: 'Produk', href: '/produk' }],
};
