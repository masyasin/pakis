import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

const STEPS = ['Unggah', 'Pemetaan kolom', 'Validasi', 'Proses'] as const;

/**
 * Langkah wizard impor (07 §4; pegawai & impor master T-085). `complete` menandai langkah terakhir selesai (impor sudah
 * diproses), sehingga semua langkah tampil tercentang.
 */
export function ImportSteps({
    current,
    complete = false,
}: {
    current: 1 | 2 | 3 | 4;
    complete?: boolean;
}) {
    return (
        <ol className="grid grid-cols-4 gap-2" aria-label="Langkah impor">
            {STEPS.map((label, i) => {
                const step = i + 1;
                const done = step < current || complete;
                const active = step === current && !complete;

                return (
                    <li
                        key={label}
                        aria-current={active ? 'step' : undefined}
                        className="flex min-w-0 flex-col gap-1.5"
                    >
                        <span
                            className={cn(
                                'h-1 rounded-full',
                                done || active ? 'bg-primary' : 'bg-muted',
                            )}
                            aria-hidden="true"
                        />
                        <span className="flex min-w-0 items-center gap-1.5 text-xs sm:text-sm">
                            <span
                                className={cn(
                                    'grid size-5 shrink-0 place-items-center rounded-full text-[11px] font-semibold',
                                    done &&
                                        'bg-primary text-primary-foreground',
                                    active &&
                                        'bg-primary/15 text-primary ring-1 ring-primary',
                                    !done &&
                                        !active &&
                                        'bg-muted text-muted-foreground',
                                )}
                            >
                                {done ? (
                                    <Check
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    step
                                )}
                            </span>
                            <span
                                className={cn(
                                    'truncate',
                                    active
                                        ? 'font-semibold text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {label}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

export type ImportBatchSummary = {
    id: number;
    /** Kunci tipe impor, mis. `employees`, `products` (T-085). */
    type: string;
    type_label: string;
    original_name: string;
    status: string;
    status_label: string;
    header_row: number;
    total_rows: number;
    valid_rows: number;
    error_rows: number;
    created_rows: number;
    updated_rows: number;
    skipped_rows: number;
    update_existing: boolean;
    completed_at_local: string | null;
    failure: string | null;
    file_available: boolean;
    created_at: string | null;
    created_at_local: string | null;
};

export type ImportField = {
    key: string;
    label: string;
    required: boolean;
    hint: string;
};
