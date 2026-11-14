import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* Konten maks 1440 px (07 §1.2). Ruang bawah agar elemen terakhir tidak menempel tepi layar HP. */}
                <div className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col pb-[max(1rem,env(safe-area-inset-bottom))]">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
