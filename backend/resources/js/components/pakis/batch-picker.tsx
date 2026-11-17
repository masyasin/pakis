import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { AsyncSelect } from '@/components/pakis/async-select';
import { FormDialog } from '@/components/pakis/form-dialog';
import { ExpiryBadge } from '@/components/pakis/status-badge';
import type { ExpiryState } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatCivilDate } from '@/lib/local-date';
import type { LookupOption } from '@/lib/lookup';
import { formatQty } from '@/lib/qty';

/** Opsi lookup `available_batches` (05 §4.11, T-078). */
export type BatchOption = LookupOption & {
    value: number;
    batch_no: string;
    expiry_date: string | null;
    days_to_expiry: number | null;
    expiry_state: ExpiryState;
    available_base: string;
    unit: string;
    bin: string | null;
    /** Posisi dalam urutan FEFO penuh; 1 = batch yang seharusnya keluar lebih dulu. */
    fefo_rank: number;
};

/** Nilai yang disimpan dokumen pemakai (baris mutasi/retur, T-081/T-082): `fefo_override` = BR-156. */
export type BatchPick = {
    batch: BatchOption;
    fefoOverride: boolean;
};

type Props = {
    productId: number | null;
    stockLocationId: number | null;
    value: BatchPick | null;
    onChange: (value: BatchPick | null) => void;
    disabled?: boolean;
    id?: string;
    'aria-label'?: string;
};

function isBatchOption(option: LookupOption): option is BatchOption {
    return (
        typeof (option as Partial<BatchOption>).fefo_rank === 'number' &&
        typeof (option as Partial<BatchOption>).available_base === 'string'
    );
}

function OptionHint({ option }: { option: LookupOption }) {
    if (!isBatchOption(option)) {
        return null;
    }

    return (
        <span className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
            {option.fefo_rank === 1 && (
                <span className="font-semibold text-primary">FEFO</span>
            )}
            <span className="truncate tabular-nums">
                {formatQty(option.available_base)} {option.unit}
            </span>
            <ExpiryBadge
                state={option.expiry_state}
                daysToExpiry={option.days_to_expiry}
            />
        </span>
    );
}

/**
 * Pemilih batch (15 §7.2, BR-102, BR-156) di atas `AsyncSelect` + lookup `available_batches`:
 * opsi urut FEFO dengan ED & qty tersedia. Memilih selain batch FEFO pertama memunculkan
 * peringatan dan konfirmasi; bila dikonfirmasi, nilai membawa `fefoOverride: true` yang wajib
 * disimpan dokumen pemakai sebagai `fefo_override` (laporan pelanggaran FEFO, T-087).
 *
 * Server tetap sumber kebenaran: pilihan ini hanya saran, eksekusi dokumen memvalidasi ulang batch
 * lewat StockLedger (alokasi bisa basi).
 */
export function BatchPicker({
    productId,
    stockLocationId,
    value,
    onChange,
    disabled = false,
    id,
    'aria-label': ariaLabel = 'Batch',
}: Props) {
    const [pending, setPending] = useState<BatchOption | null>(null);
    const ready = productId !== null && stockLocationId !== null;

    const pick = (option: LookupOption) => {
        if (!isBatchOption(option)) {
            return;
        }

        if (option.fefo_rank > 1) {
            setPending(option);

            return;
        }

        onChange({ batch: option, fefoOverride: false });
    };

    const selected = value?.batch ?? null;

    return (
        <div className="grid gap-1.5">
            <AsyncSelect
                source="available_batches"
                params={
                    ready
                        ? {
                              product_id: String(productId),
                              stock_location_id: String(stockLocationId),
                          }
                        : undefined
                }
                value={selected ? [selected.value] : []}
                // Pilihan diproses lewat onPick (butuh fefo_rank); onChange hanya untuk hapus.
                onChange={(next) => {
                    if (next.length === 0) {
                        onChange(null);
                    }
                }}
                onPick={pick}
                renderHint={(option) => <OptionHint option={option} />}
                disabled={disabled || !ready}
                id={id}
                aria-label={ariaLabel}
                placeholder={
                    ready ? 'Cari nomor batch…' : 'Pilih produk & lokasi dulu'
                }
            />

            {selected && (
                <p className="flex flex-wrap items-center gap-2 text-[0.8rem] text-muted-foreground">
                    <ExpiryBadge
                        state={selected.expiry_state}
                        daysToExpiry={selected.days_to_expiry}
                    />
                    <span>
                        {selected.expiry_date
                            ? `ED ${formatCivilDate(selected.expiry_date)}`
                            : 'Tanpa ED'}
                        {' · '}tersedia{' '}
                        <span className="tabular-nums">
                            {formatQty(selected.available_base)}
                        </span>{' '}
                        {selected.unit}
                        {selected.bin ? ` · rak ${selected.bin}` : ''}
                    </span>
                    {value?.fefoOverride && (
                        <span
                            role="status"
                            className="inline-flex items-center gap-1 font-medium text-status-warning"
                        >
                            <TriangleAlert
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            Bukan batch FEFO pertama
                        </span>
                    )}
                </p>
            )}

            <FormDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                icon={TriangleAlert}
                title="Pilih batch selain urutan FEFO?"
                description={
                    pending
                        ? `Batch ${pending.batch_no} berada di urutan ke-${pending.fefo_rank}. Ada batch dengan ED lebih dekat yang seharusnya keluar lebih dulu.`
                        : undefined
                }
                footer={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPending(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                if (pending) {
                                    onChange({
                                        batch: pending,
                                        fefoOverride: true,
                                    });
                                }
                                setPending(null);
                            }}
                        >
                            Tetap pilih
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-muted-foreground">
                    Pilihan ini dicatat sebagai pengecualian FEFO pada dokumen.
                    Lanjutkan hanya bila ada alasan, mis. permintaan kios atau
                    batch lebih tua di rak yang sama.
                </p>
            </FormDialog>
        </div>
    );
}
