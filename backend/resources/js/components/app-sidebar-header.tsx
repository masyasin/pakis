import { Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { HeaderUserMenu } from '@/components/header-user-menu';
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
        // Lengket + blur tipis agar breadcrumb & aksi tetap terjangkau saat daftar panjang digulir.
        <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-background/85 px-4 backdrop-blur-md transition-[height] duration-200 ease-out group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6 lg:rounded-t-xl">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1.5 size-9 text-muted-foreground hover:text-foreground" />
                <Separator
                    orientation="vertical"
                    className="mr-1 data-[orientation=vertical]:h-5"
                />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            {/* Lonceng notifikasi (07 §1.2) menyusul bersama modul notifikasi. */}
            <div className="ml-auto flex items-center gap-1">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            asChild
                            className="text-muted-foreground hover:text-foreground"
                        >
                            <Link href={exportsIndex()} aria-label="Unduhan">
                                <Download className="size-[18px]" />
                            </Link>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Unduhan</TooltipContent>
                </Tooltip>
                <ThemeToggle />
                <Separator
                    orientation="vertical"
                    className="mx-1.5 data-[orientation=vertical]:h-5"
                />
                <HeaderUserMenu />
            </div>
        </header>
    );
}
