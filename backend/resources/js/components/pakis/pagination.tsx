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

    const isDisabled = (target: number) =>
        disabled || target < 1 || target > lastPage || target === page;

    // Pertama/terakhir: ikon saja, hanya saat konten lebar (container query).
    const edge = (target: number, label: string, icon: React.ReactNode) => (
        <Button
            type="button"
            variant="outline"
            size="icon"
            className="hidden size-9 shrink-0 shadow-none @lg:inline-flex"
            aria-label={label}
            disabled={isDisabled(target)}
            onClick={() => onChange(target)}
        >
            {icon}
        </Button>
    );

    // Sebelumnya/berikutnya: berlabel & 40 px saat sempit (target sentuh), ikon 36 px saat lebar.
    const step = (
        target: number,
        label: string,
        text: string,
        icon: React.ReactNode,
        iconSide: 'left' | 'right',
    ) => (
        <Button
            type="button"
            variant="outline"
            className="h-10 shrink-0 gap-1 px-3 shadow-none @lg:size-9 @lg:px-0"
            aria-label={label}
            disabled={isDisabled(target)}
            onClick={() => onChange(target)}
        >
            {iconSide === 'left' && icon}
            <span className="@lg:sr-only">{text}</span>
            {iconSide === 'right' && icon}
        </Button>
    );

    return (
        <nav
            aria-label="Navigasi halaman"
            className="flex w-full items-center gap-1.5 @lg:justify-center @3xl:w-auto @3xl:justify-end"
        >
            {edge(1, 'Halaman pertama', <ChevronsLeft className="size-4" />)}
            {step(
                page - 1,
                'Halaman sebelumnya',
                'Sebelumnya',
                <ChevronLeft className="size-4" />,
                'left',
            )}
            {/* Konten sempit: nomor halaman diganti posisi ringkas di tengah. */}
            <span className="flex-1 text-center text-sm text-muted-foreground tabular-nums @lg:hidden">
                <span className="font-semibold text-foreground">{page}</span>
                {' / '}
                {lastPage}
            </span>
            {pageWindow(page, lastPage).map((p, i) =>
                p === 'gap' ? (
                    <span
                        key={`gap-${i}`}
                        className="hidden w-6 text-center text-muted-foreground @lg:inline"
                        aria-hidden
                    >
                        …
                    </span>
                ) : (
                    <Button
                        key={p}
                        type="button"
                        variant={p === page ? 'default' : 'ghost'}
                        size="sm"
                        className="hidden h-9 min-w-9 px-2.5 tabular-nums shadow-none @lg:inline-flex"
                        aria-label={`Halaman ${p}`}
                        aria-current={p === page ? 'page' : undefined}
                        disabled={disabled}
                        onClick={() => p !== page && onChange(p)}
                    >
                        {p}
                    </Button>
                ),
            )}
            {step(
                page + 1,
                'Halaman berikutnya',
                'Berikutnya',
                <ChevronRight className="size-4" />,
                'right',
            )}
            {edge(
                lastPage,
                'Halaman terakhir',
                <ChevronsRight className="size-4" />,
            )}
        </nav>
    );
}
