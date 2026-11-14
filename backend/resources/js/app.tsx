import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

/** Nama aplikasi dari Pengaturan, dicetak app.blade.php di <meta name="application-name"> (T-008). */
const appName =
    document
        .querySelector<HTMLMetaElement>('meta[name="application-name"]')
        ?.content.trim() || 'PAKIS ONE';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            // Halaman galat bisa tampil untuk tamu (tanpa auth.user), jadi tanpa layout aplikasi.
            case name === 'error':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        // Ikut warna utama dari Pengaturan (token CSS, terang & gelap).
        color: 'var(--primary)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
