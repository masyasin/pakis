import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type PageTab = {
    key: string;
    label: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
};

/**
 * Tab navigasi antar-halaman (Pengaturan, Profil & keamanan). Gaya segmented: tab aktif berupa
 * "kartu" di atas jalur abu, teks hijau. Selalu satu baris: bila tab tidak muat (HP, atau layar
 * sempit), jalur bisa digeser dan muncul tombol ‹ › dengan efek pudar di tepi. Tab aktif digulir
 * otomatis ke tampilan.
 */
export function PageTabs({
    tabs,
    active,
    label,
    className,
}: {
    tabs: PageTab[];
    active: string;
    label: string;
    className?: string;
}) {
    const scroller = useRef<HTMLDivElement>(null);
    const activeRef = useRef<HTMLAnchorElement>(null);
    const [edges, setEdges] = useState({ left: false, right: false });

    const measure = useCallback(() => {
        const el = scroller.current;
        if (!el) {
            return;
        }
        setEdges({
            left: el.scrollLeft > 2,
            right: el.scrollLeft + el.clientWidth < el.scrollWidth - 2,
        });
    }, []);

    useEffect(() => {
        // Tab aktif ke TENGAH jalur (bukan tepi), agar tidak tertutup efek pudar/tombol geser.
        // Menggulir jalurnya langsung, bukan scrollIntoView, supaya halaman tidak ikut bergeser.
        const el = scroller.current;
        const tab = activeRef.current;
        if (el && tab) {
            el.scrollLeft =
                tab.offsetLeft - (el.clientWidth - tab.offsetWidth) / 2;
        }
        measure();
    }, [active, measure]);

    useEffect(() => {
        const el = scroller.current;
        if (!el || typeof ResizeObserver === 'undefined') {
            return;
        }
        const observer = new ResizeObserver(measure);
        observer.observe(el);

        return () => observer.disconnect();
    }, [measure]);

    const scrollBy = (direction: 1 | -1) =>
        scroller.current?.scrollBy({
            left: direction * scroller.current.clientWidth * 0.6,
            behavior: 'smooth',
        });

    return (
        <div
            className={cn(
                'relative w-fit max-w-full rounded-xl border bg-muted/60',
                className,
            )}
        >
            <div
                ref={scroller}
                onScroll={measure}
                className="[scrollbar-width:none] overflow-x-auto [&::-webkit-scrollbar]:hidden"
            >
                <nav aria-label={label} className="flex w-max gap-1 p-1">
                    {tabs.map((tab) => {
                        const isActive = tab.key === active;
                        const Icon = tab.icon;

                        return (
                            <Link
                                key={tab.key}
                                ref={isActive ? activeRef : undefined}
                                href={tab.href}
                                preserveScroll
                                aria-current={isActive ? 'page' : undefined}
                                className={cn(
                                    'inline-flex h-9 shrink-0 items-center gap-2 rounded-lg px-3 text-sm font-medium whitespace-nowrap transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    isActive
                                        ? 'bg-card text-sidebar-accent-foreground shadow-sm ring-1 ring-sidebar-primary/25'
                                        : 'text-muted-foreground hover:bg-card/60 hover:text-foreground',
                                )}
                            >
                                {Icon && (
                                    <Icon
                                        className={cn(
                                            'size-4',
                                            isActive
                                                ? 'text-sidebar-accent-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                        aria-hidden="true"
                                    />
                                )}
                                {tab.label}
                            </Link>
                        );
                    })}
                </nav>
            </div>

            {/* Tombol geser hanya untuk mouse; di layar sentuh cukup digeser. aria-hidden karena
                semua tab tetap bisa dicapai lewat Tab keyboard. */}
            {edges.left && (
                <button
                    type="button"
                    tabIndex={-1}
                    aria-hidden="true"
                    onClick={() => scrollBy(-1)}
                    className="absolute inset-y-0 left-0 flex w-10 items-center justify-start rounded-l-xl bg-linear-to-r from-muted via-muted/90 to-transparent pl-1.5 text-muted-foreground hover:text-foreground"
                >
                    <ChevronLeft className="size-4" />
                </button>
            )}
            {edges.right && (
                <button
                    type="button"
                    tabIndex={-1}
                    aria-hidden="true"
                    onClick={() => scrollBy(1)}
                    className="absolute inset-y-0 right-0 flex w-10 items-center justify-end rounded-r-xl bg-linear-to-l from-muted via-muted/90 to-transparent pr-1.5 text-muted-foreground hover:text-foreground"
                >
                    <ChevronRight className="size-4" />
                </button>
            )}
        </div>
    );
}
