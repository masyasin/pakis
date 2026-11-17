import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Camera,
    Check,
    ClipboardCheck,
    PackagePlus,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

export type OpnameLineRow = {
    id: number;
    product_id: number;
    sku: string;
    name: string;
    unit: string;
    batch_no: string;
    expiry_date: string | null;
    bin: { id: number; code: string } | null;
    found: boolean;
    qty_counted_base: string | null;
    counted_by: string | null;
    counted_by_id: number | null;
    qty_recount_base: string | null;
    recounted_by: string | null;
    variance_reason_id: number | null;
    variance_reason: string | null;
    note: string | null;
    has_photo: boolean;
    /** Kunci di bawah TIDAK ada saat hitung buta & sedang dihitung (SEC-103). */
    qty_system_base?: string;
    qty_expected_base?: string;
    variance_base?: string | null;
    variance_value?: string | null;
    recount_required?: boolean;
};

type Props = ListingProps<OpnameLineRow> & {
    opname: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        location: { id: number; name: string };
        blind_count: boolean;
        hides_system: boolean;
    };
    reasons: Array<{ value: number; label: string }>;
    can: { count: boolean; finish: boolean };
    me: number;
};

/** "48.000" → "48" untuk isian awal (koma desimal Indonesia). */
const toInput = (q: string | null) =>
    q === null
        ? ''
        : q
              .replace(/\.0+$/, '')
              .replace(/(\.\d*?)0+$/, '$1')
              .replace('.', ',');

const hasVariance = (r: OpnameLineRow) =>
    r.variance_base !== undefined &&
    r.variance_base !== null &&
    Number(r.variance_base) !== 0;

/**
 * Simpan satu baris (PUT per baris, 15 §7.1 "simpan per baris + indikator tersimpan"). Halaman
 * tidak dimuat ulang penuh: hanya `rows` & `listing` yang diperbarui, posisi gulir tetap.
 */
function saveLine(
    opnameId: number,
    lineId: number,
    data: Record<string, string | number | File | null>,
    onDone: (ok: boolean, errors: Record<string, string>) => void,
) {
    router.post(
        `/opname/${opnameId}/baris/${lineId}`,
        { ...data, _method: 'put' },
        {
            preserveScroll: true,
            preserveState: true,
            forceFormData: data.photo instanceof File,
            only: ['rows', 'listing'],
            onSuccess: () => onDone(true, {}),
            onError: (errors) => onDone(false, errors),
        },
    );
}

function QtyCell({
    opnameId,
    row,
    mode,
    disabled,
}: {
    opnameId: number;
    row: OpnameLineRow;
    mode: 'count' | 'recount';
    disabled: boolean;
}) {
    const initial =
        mode === 'count' ? row.qty_counted_base : row.qty_recount_base;
    const [value, setValue] = useState(toInput(initial));
    const [photo, setPhoto] = useState<File | null>(null);
    const [state, setState] = useState<'idle' | 'saving' | 'saved'>(
        initial === null ? 'idle' : 'saved',
    );
    const [error, setError] = useState<string | undefined>();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setState('saving');
        saveLine(
            opnameId,
            row.id,
            { mode, qty: value, photo: mode === 'count' ? photo : null },
            (ok, errors) => {
                setState(ok ? 'saved' : 'idle');
                setError(
                    ok ? undefined : (errors.qty ?? Object.values(errors)[0]),
                );
                if (ok) {
                    setPhoto(null);
                }
            },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-1">
            <div className="flex items-center gap-1.5">
                <Input
                    aria-label={`${mode === 'count' ? 'Jumlah dihitung' : 'Hitung ulang'} ${row.sku} ${row.batch_no}`}
                    inputMode="decimal"
                    autoComplete="off"
                    className="h-11 w-24 text-right text-base tabular-nums"
                    value={value}
                    disabled={disabled}
                    onChange={(e) => {
                        setValue(e.target.value);
                        setState('idle');
                    }}
                />
                {mode === 'count' && !disabled && (
                    <label
                        className="inline-flex size-9 cursor-pointer items-center justify-center rounded-md border text-muted-foreground hover:bg-muted"
                        aria-label={`Foto ${row.batch_no} (opsional)`}
                    >
                        <Camera className="size-4" aria-hidden="true" />
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            capture="environment"
                            className="sr-only"
                            onChange={(e) => {
                                setPhoto(e.target.files?.[0] ?? null);
                                setState('idle');
                            }}
                        />
                    </label>
                )}
                <Button
                    type="submit"
                    size="sm"
                    variant={state === 'saved' ? 'outline' : 'default'}
                    disabled={disabled || state === 'saving' || value === ''}
                    aria-label={`Simpan ${row.batch_no}`}
                >
                    {state === 'saved' ? (
                        <Check aria-hidden="true" />
                    ) : (
                        'Simpan'
                    )}
                </Button>
            </div>
            {(photo || row.has_photo) && mode === 'count' && (
                <span className="text-xs text-muted-foreground">
                    {photo ? 'Foto siap dikirim' : 'Foto terlampir'}
                </span>
            )}
            <InputError message={error} />
        </form>
    );
}

function ReasonCell({
    opnameId,
    row,
    reasons,
    disabled,
}: {
    opnameId: number;
    row: OpnameLineRow;
    reasons: Props['reasons'];
    disabled: boolean;
}) {
    const [error, setError] = useState<string | undefined>();

    return (
        <div className="grid gap-1">
            <NativeSelect
                aria-label={`Alasan selisih ${row.batch_no}`}
                value={row.variance_reason_id ?? ''}
                disabled={disabled}
                onChange={(e) =>
                    saveLine(
                        opnameId,
                        row.id,
                        {
                            mode: 'reason',
                            variance_reason_id: Number(e.target.value),
                        },
                        (_, errors) => setError(Object.values(errors)[0]),
                    )
                }
            >
                <option value="">Pilih alasan</option>
                {reasons.map((r) => (
                    <option key={r.value} value={r.value}>
                        {r.label}
                    </option>
                ))}
            </NativeSelect>
            <InputError message={error} />
        </div>
    );
}

/**
 * Lembar hitung (15 §7.1, BR-162, SEC-103): baris besar untuk tablet, simpan per baris. Scanner
 * HID mengetik ke kolom cari (SKU/batch) sehingga baris yang dipindai langsung tersaring. Saat
 * hitung buta & sedang dihitung, kolom sistem/selisih tidak dikirim server sama sekali.
 */
export default function OpnameCountSheet({
    rows,
    listing,
    opname,
    reasons,
    can,
    me,
}: Props) {
    const { url } = usePage();
    const [found, setFound] = useState(false);
    const counting = opname.status === 'counting';
    const review = opname.status === 'review';

    const columns: DataTableColumn<OpnameLineRow>[] = [
        {
            key: 'product',
            header: 'Produk',
            sortKey: 'product',
            primary: true,
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="font-medium break-words">{r.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.sku}
                    </span>
                    {r.found && (
                        <StatusBadge
                            status="opname_line.found"
                            label="Batch temuan"
                            tone="info"
                        />
                    )}
                </span>
            ),
        },
        {
            key: 'batch',
            header: 'Batch',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="font-mono text-sm">{r.batch_no}</span>
                    {r.expiry_date && (
                        <span className="text-xs text-muted-foreground">
                            ED {formatCivilDate(r.expiry_date)}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'bin',
            header: 'Rak',
            sortKey: 'bin',
            cell: (r) => r.bin?.code ?? '–',
        },
        ...(opname.hides_system
            ? []
            : ([
                  {
                      key: 'expected',
                      header: 'Sistem',
                      className: 'text-right tabular-nums',
                      cell: (r) =>
                          r.qty_expected_base === undefined
                              ? '–'
                              : `${formatQty(r.qty_expected_base)} ${r.unit.toLowerCase()}`,
                  },
              ] satisfies DataTableColumn<OpnameLineRow>[])),
        {
            key: 'count',
            header: 'Dihitung',
            cell: (r) =>
                counting && can.count ? (
                    <QtyCell
                        opnameId={opname.id}
                        row={r}
                        mode="count"
                        disabled={false}
                    />
                ) : (
                    <span className="tabular-nums">
                        {r.qty_counted_base === null
                            ? '–'
                            : formatQty(r.qty_counted_base)}
                        {r.counted_by && (
                            <span className="block text-xs text-muted-foreground">
                                {r.counted_by}
                            </span>
                        )}
                    </span>
                ),
        },
        {
            key: 'recount',
            header: 'Hitung ulang',
            cell: (r) => {
                const allowed =
                    can.count &&
                    (counting || review) &&
                    r.qty_counted_base !== null &&
                    r.counted_by_id !== me;

                return allowed ? (
                    <QtyCell
                        opnameId={opname.id}
                        row={r}
                        mode="recount"
                        disabled={false}
                    />
                ) : (
                    <span className="tabular-nums">
                        {r.qty_recount_base === null
                            ? '–'
                            : formatQty(r.qty_recount_base)}
                        {r.recounted_by && (
                            <span className="block text-xs text-muted-foreground">
                                {r.recounted_by}
                            </span>
                        )}
                    </span>
                );
            },
        },
        ...(opname.hides_system
            ? []
            : ([
                  {
                      key: 'variance',
                      header: 'Selisih',
                      className: 'text-right tabular-nums',
                      cell: (r) =>
                          hasVariance(r) ? (
                              <span className="inline-grid justify-items-end gap-0.5">
                                  <span className="font-medium text-status-warning">
                                      {Number(r.variance_base) > 0 ? '+' : ''}
                                      {formatQty(r.variance_base ?? '0')}
                                  </span>
                                  {r.variance_value && (
                                      <span className="text-xs text-muted-foreground">
                                          {formatRupiah(r.variance_value)}
                                      </span>
                                  )}
                                  {r.recount_required &&
                                      r.qty_recount_base === null && (
                                          <span className="inline-flex items-center gap-1 text-xs text-status-danger">
                                              <TriangleAlert
                                                  className="size-3.5"
                                                  aria-hidden="true"
                                              />
                                              Wajib hitung ulang
                                          </span>
                                      )}
                              </span>
                          ) : (
                              '–'
                          ),
                  },
                  {
                      key: 'reason',
                      header: 'Alasan',
                      cell: (r) =>
                          review && hasVariance(r) && can.count ? (
                              <ReasonCell
                                  opnameId={opname.id}
                                  row={r}
                                  reasons={reasons}
                                  disabled={false}
                              />
                          ) : (
                              (r.variance_reason ?? '–')
                          ),
                  },
              ] satisfies DataTableColumn<OpnameLineRow>[])),
    ];

    return (
        <>
            <Head title={`Lembar Hitung ${opname.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <Link
                    href={`/opname/${opname.id}`}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Kembali ke opname
                </Link>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Lembar Hitung{' '}
                            <span className="font-mono">{opname.number}</span>
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {opname.location.name} · {opname.status_label}
                            {opname.hides_system &&
                                ' · hitung buta: jumlah sistem disembunyikan sampai selesai dihitung'}
                        </p>
                    </div>
                    <StatusBadge
                        status={`stock_opname.${opname.status}`}
                        label={opname.status_label}
                    />
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Baris lembar hitung"
                    toolbarActions={
                        <>
                            {counting && can.count && (
                                <Button
                                    variant="outline"
                                    onClick={() => setFound(true)}
                                >
                                    <PackagePlus aria-hidden="true" />
                                    Batch Temuan
                                </Button>
                            )}
                            {can.finish && (
                                <Button asChild>
                                    <Link href={`/opname/${opname.id}`}>
                                        <ClipboardCheck aria-hidden="true" />
                                        Selesai Hitung
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />
                <p className="text-xs text-muted-foreground">
                    Pakai pemindai barcode di kolom cari untuk langsung
                    menemukan baris berdasarkan SKU atau nomor batch.
                </p>
            </div>

            {found && (
                <FoundBatchDialog
                    opnameId={opname.id}
                    locationId={opname.location.id}
                    onClose={() => setFound(false)}
                    reloadUrl={url}
                />
            )}
        </>
    );
}

function FoundBatchDialog({
    opnameId,
    locationId,
    onClose,
    reloadUrl,
}: {
    opnameId: number;
    locationId: number;
    onClose: () => void;
    reloadUrl: string;
}) {
    const form = useForm({
        product_id: '',
        batch_no: '',
        expiry_date: '',
        stock_bin_id: '',
        qty: '',
        note: '',
    });
    const err = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            product_id: d.product_id === '' ? null : Number(d.product_id),
            batch_no: d.batch_no,
            expiry_date: d.expiry_date === '' ? null : d.expiry_date,
            stock_bin_id: d.stock_bin_id === '' ? null : Number(d.stock_bin_id),
            qty: d.qty,
            note: d.note,
        }));
        form.post(`/opname/${opnameId}/baris`, {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
                router.visit(reloadUrl, {
                    preserveScroll: true,
                    only: ['rows', 'listing'],
                });
            },
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={PackagePlus}
            title="Tambah batch temuan"
            description="Batch yang ada fisiknya tetapi tidak ada di lembar hitung. Batch dibuat saat opname diposting."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Tambahkan
                    </Button>
                </>
            }
        >
            <div className="grid gap-2">
                <Label htmlFor="fb-product">Produk</Label>
                <AsyncSelect
                    id="fb-product"
                    source="products"
                    value={
                        form.data.product_id === ''
                            ? []
                            : [form.data.product_id]
                    }
                    onChange={(v) =>
                        form.setData('product_id', String(v[0] ?? ''))
                    }
                />
                <InputError message={err.product_id} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="fb-batch">Nomor batch</Label>
                    <Input
                        id="fb-batch"
                        autoComplete="off"
                        value={form.data.batch_no}
                        onChange={(e) =>
                            form.setData('batch_no', e.target.value)
                        }
                    />
                    <InputError message={err.batch_no} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="fb-expiry">ED (opsional)</Label>
                    <Input
                        id="fb-expiry"
                        type="date"
                        value={form.data.expiry_date}
                        onChange={(e) =>
                            form.setData('expiry_date', e.target.value)
                        }
                    />
                    <InputError message={err.expiry_date} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="fb-bin">Rak</Label>
                    <AsyncSelect
                        id="fb-bin"
                        source="stock_bins"
                        params={{ parent: String(locationId) }}
                        value={
                            form.data.stock_bin_id === ''
                                ? []
                                : [form.data.stock_bin_id]
                        }
                        onChange={(v) =>
                            form.setData('stock_bin_id', String(v[0] ?? ''))
                        }
                        placeholder="Tanpa rak"
                    />
                    <InputError message={err.stock_bin_id} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="fb-qty">Jumlah dihitung</Label>
                    <Input
                        id="fb-qty"
                        inputMode="decimal"
                        autoComplete="off"
                        className="text-right tabular-nums"
                        value={form.data.qty}
                        onChange={(e) => form.setData('qty', e.target.value)}
                    />
                    <InputError message={err.qty} />
                </div>
            </div>
        </FormDialog>
    );
}

OpnameCountSheet.layout = {
    breadcrumbs: [{ title: 'Stock Opname', href: '/opname' }],
};
