import { Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { HeaderUserMenu } from '@/components/header-user-menu';
import { NotificationBell } from '@/components/pakis/notification-bell';
import { ThemeToggle } from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { index as exportsIndex } from '@/routes/admin/exports';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        // Lengket + kaca buram agar breadcrumb & aksi tetap terjangkau saat daftar panjang digulir.
        // Garis bawah keras diganti garis rambut bergradasi + bayangan lembut; pendaran hijau merek
        // tipis di kanan memberi kedalaman tanpa mengganggu keterbacaan (terang & gelap).
        <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 bg-background/75 px-4 shadow-[0_12px_32px_-24px_rgb(0_0_0/0.35)] backdrop-blur-xl backdrop-saturate-150 transition-[height] duration-200 ease-out group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 after:pointer-events-none after:absolute after:inset-x-0 after:bottom-0 after:h-px after:bg-gradient-to-r after:from-transparent after:via-primary/30 after:to-transparent md:px-6 lg:rounded-t-xl">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 -z-10 overflow-hidden lg:rounded-t-xl"
            >
                <div className="absolute inset-0 bg-[radial-gradient(70%_160%_at_100%_0%,color-mix(in_oklab,var(--primary)_13%,transparent),transparent_62%)]" />
                <div className="absolute inset-0 bg-[radial-gradient(40%_140%_at_0%_100%,color-mix(in_oklab,var(--primary)_6%,transparent),transparent_70%)]" />
                <div className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/60 to-transparent dark:via-white/10" />
            </div>
            {/* min-w-0 + flex-1: judul halaman panjang dipotong (…) sebelum kapsul ikon, bukan
                tertimpa di HP. */}
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1.5 size-9 shrink-0 text-muted-foreground hover:text-foreground" />
                <Separator
                    orientation="vertical"
                    className="mr-1 shrink-0 data-[orientation=vertical]:h-5"
                />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <div className="ml-auto flex shrink-0 items-center gap-2">
                {/* Ikon aksi dikelompokkan dalam satu kapsul kaca agar terlihat rapi, bukan berserakan. */}
                <div className="flex items-center gap-0.5 rounded-full border border-border/60 bg-card/60 p-0.5 shadow-[0_1px_2px_rgb(0_0_0/0.04)] [&_a]:rounded-full [&_button]:rounded-full">
                    {/* Lonceng notifikasi (07 §1.2, T-029). */}
                    <NotificationBell />
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                asChild
                                className="text-muted-foreground hover:text-foreground"
                            >
                                <Link
                                    href={exportsIndex()}
                                    aria-label="Unduhan"
                                >
                                    <Download className="size-[18px]" />
                                </Link>
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Unduhan</TooltipContent>
                    </Tooltip>
                    <ThemeToggle />
                </div>
                <HeaderUserMenu />
            </div>
        </header>
    );
}
