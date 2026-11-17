import { CircleDot } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type DocumentTimelineItem = {
    key: string | number;
    /** Nama kejadian (mis. "Diajukan"). */
    title: ReactNode;
    /** Pelaku · waktu. */
    meta?: ReactNode;
    /** Alasan/catatan kejadian. */
    note?: ReactNode;
    icon?: LucideIcon;
};

/**
 * Riwayat dokumen sebagai garis waktu vertikal (pola `ApprovalTimeline`): butir pertama = kejadian
 * terbaru, ditandai warna merek; butir lama diredupkan agar urutan terbaca sekilas.
 */
export function DocumentTimeline({
    items,
    label = 'Riwayat dokumen',
    empty = 'Belum ada riwayat.',
}: {
    items: DocumentTimelineItem[];
    label?: string;
    empty?: string;
}) {
    if (items.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <ol className="grid" aria-label={label}>
            {items.map((item, index) => {
                const Icon = item.icon ?? CircleDot;
                const latest = index === 0;
                const last = index === items.length - 1;

                return (
                    <li
                        key={item.key}
                        className="grid grid-cols-[1.5rem_minmax(0,1fr)] gap-x-3"
                    >
                        <span className="flex flex-col items-center">
                            <span
                                className={cn(
                                    'grid size-6 shrink-0 place-items-center rounded-full',
                                    latest
                                        ? 'bg-sidebar-accent text-primary'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                <Icon className="size-3.5" aria-hidden="true" />
                            </span>
                            {!last && (
                                <span
                                    className="my-1 w-px flex-1 bg-border"
                                    aria-hidden="true"
                                />
                            )}
                        </span>
                        <div
                            className={cn(
                                'grid min-w-0 gap-0.5 pt-0.5',
                                !last && 'pb-4',
                            )}
                        >
                            <span
                                className={cn(
                                    'text-sm font-medium break-words',
                                    !latest && 'text-foreground/85',
                                )}
                            >
                                {item.title}
                            </span>
                            {item.meta && (
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {item.meta}
                                </span>
                            )}
                            {item.note && (
                                <span className="mt-1 rounded-md bg-muted/60 px-2.5 py-1.5 text-xs break-words whitespace-pre-line">
                                    {item.note}
                                </span>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
