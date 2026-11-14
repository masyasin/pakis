import { Link, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { index, unreadCount } from '@/routes/admin/notifications';

/** Interval polling jumlah belum dibaca (07 §1.2, 05 §4.2). */
export const POLL_MS = 60_000;

export function badgeText(count: number): string {
    return count > 99 ? '99+' : String(count);
}

/**
 * Lonceng notifikasi di header: jumlah belum dibaca dipolling tiap 60 detik, hanya saat tab
 * terlihat (hemat request), dan disegarkan setelah setiap kunjungan Inertia (mis. sesudah "tandai dibaca").
 */
export function NotificationBell() {
    const [count, setCount] = useState(0);

    const refresh = useCallback(async () => {
        try {
            const response = await fetch(unreadCount.url(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }
            const data = (await response.json()) as { count?: unknown };
            if (typeof data.count === 'number') {
                setCount(data.count);
            }
        } catch {
            // Jaringan putus: biarkan angka terakhir, coba lagi di putaran berikutnya.
        }
    }, []);

    useEffect(() => {
        void refresh();

        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                void refresh();
            }
        }, POLL_MS);
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                void refresh();
            }
        };
        document.addEventListener('visibilitychange', onVisible);
        // 'success' (bukan 'navigate'): POST tandai dibaca kembali ke URL yang sama tanpa event navigate.
        const stopVisit = router.on('success', () => void refresh());

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            stopVisit();
        };
    }, [refresh]);

    const label =
        count > 0 ? `Notifikasi, ${count} belum dibaca` : 'Notifikasi';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    asChild
                    className="relative text-muted-foreground hover:text-foreground"
                >
                    <Link href={index()} aria-label={label}>
                        <Bell className="size-[18px]" />
                        {count > 0 && (
                            <span
                                aria-hidden="true"
                                data-testid="notification-badge"
                                className="absolute top-1 right-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] leading-none font-bold text-white tabular-nums"
                            >
                                {badgeText(count)}
                            </span>
                        )}
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>Notifikasi</TooltipContent>
        </Tooltip>
    );
}
