import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from 'lucide-react';
import { Button } from '@/components/ui/button';

type Props = {
    page: number;
    lastPage: number;
    onChange: (page: number) => void;
    disabled?: boolean;
};

/** Halaman di sekitar halaman aktif: 1 … 4 5 6 … 20 */
export function pageWindow(
    page: number,
    lastPage: number,
): Array<number | 'gap'> {
    const pages = new Set([1, lastPage, page - 1, page, page + 1]);
    const sorted = [...pages]
        .filter((p) => p >= 1 && p <= lastPage)
        .sort((a, b) => a - b);

    const result: Array<number | 'gap'> = [];
    sorted.forEach((p, i) => {
        if (i > 0 && p - sorted[i - 1] > 1) {
            result.push('gap');
        }
        result.push(p);
    });

    return result;
}

export function Pagination({ page, lastPage, onChange, disabled }: Props) {
    if (lastPage <= 1) {
        return null;
    }

    const nav = (target: number, label: string, icon: React.ReactNode) => (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-8"
            aria-label={label}
            disabled={
                disabled || target < 1 || target > lastPage || target === page
            }
            onClick={() => onChange(target)}
        >
            {icon}
        </Button>
    );

    return (
        <nav aria-label="Navigasi halaman" className="flex items-center gap-1">
            {nav(1, 'Halaman pertama', <ChevronsLeft className="size-4" />)}
            {nav(
                page - 1,
                'Halaman sebelumnya',
                <ChevronLeft className="size-4" />,
            )}
            {pageWindow(page, lastPage).map((p, i) =>
                p === 'gap' ? (
                    <span
                        key={`gap-${i}`}
                        className="px-1 text-muted-foreground"
                        aria-hidden
                    >
                        …
                    </span>
                ) : (
                    <Button
                        key={p}
                        type="button"
                        variant={p === page ? 'secondary' : 'ghost'}
                        size="sm"
                        className="hidden h-8 min-w-8 px-2 sm:inline-flex"
                        aria-label={`Halaman ${p}`}
                        aria-current={p === page ? 'page' : undefined}
                        disabled={disabled}
                        onClick={() => p !== page && onChange(p)}
                    >
                        {p}
                    </Button>
                ),
            )}
            {nav(
                page + 1,
                'Halaman berikutnya',
                <ChevronRight className="size-4" />,
            )}
            {nav(
                lastPage,
                'Halaman terakhir',
                <ChevronsRight className="size-4" />,
            )}
        </nav>
    );
}
