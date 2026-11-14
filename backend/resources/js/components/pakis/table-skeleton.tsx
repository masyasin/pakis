import { Skeleton } from '@/components/ui/skeleton';

type Props = { columns: number; rows?: number };

/** Baris kerangka saat daftar pertama kali dimuat (07 §3.2.8). */
export function TableSkeleton({ columns, rows = 5 }: Props) {
    return (
        <div className="space-y-3 p-3" aria-hidden data-testid="table-skeleton">
            {Array.from({ length: rows }, (_, r) => (
                <div key={r} className="flex gap-3">
                    {Array.from({ length: columns }, (_, c) => (
                        <Skeleton key={c} className="h-5 flex-1" />
                    ))}
                </div>
            ))}
        </div>
    );
}
