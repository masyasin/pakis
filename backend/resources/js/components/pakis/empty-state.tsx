import { Inbox, SearchX } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

type Props =
    | { variant: 'no-data'; action?: ReactNode; description?: string }
    | { variant: 'no-results'; onReset: () => void };

/**
 * Dua jenis daftar kosong (07 §3.2.7): belum ada data sama sekali vs filter tidak menemukan apa pun.
 */
export function EmptyState(props: Props) {
    if (props.variant === 'no-results') {
        return (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                <SearchX className="size-8 text-muted-foreground" aria-hidden />
                <div>
                    <p className="font-medium">
                        Tidak ada hasil untuk filter ini
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Ubah kata kunci atau hapus sebagian filter.
                    </p>
                </div>
                <Button variant="outline" size="sm" onClick={props.onReset}>
                    Reset filter
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <Inbox className="size-8 text-muted-foreground" aria-hidden />
            <div>
                <p className="font-medium">Belum ada data</p>
                {props.description && (
                    <p className="text-sm text-muted-foreground">
                        {props.description}
                    </p>
                )}
            </div>
            {props.action}
        </div>
    );
}
