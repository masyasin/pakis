import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

/**
 * Lencana logo. Bila admin mengunggah logo (Pengaturan → Tampilan, T-008), gambar itu yang
 * tampil (logo gelap = logo terang bila kosong). Tanpa logo: daun putih di atas gradien primer;
 * mode gelap memakai `--primary-strong` agar daun putih tetap kontras (8.0:1).
 */
export function AppLogoMark({
    className,
    iconClassName,
    variant = 'solid',
}: {
    className?: string;
    iconClassName?: string;
    /** `glass` untuk di atas panel hijau (login): kaca tipis, bukan isian hijau. */
    variant?: 'solid' | 'glass';
}) {
    const { name, branding } = usePage().props;
    const logoLight = branding?.logoLight ?? null;
    const logoDark = branding?.logoDark ?? logoLight;

    if (logoLight) {
        const alt = `Logo ${name || 'PAKIS ONE'}`;

        return (
            <span
                className={cn(
                    'relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-[10px]',
                    variant === 'glass' &&
                        'bg-white/90 p-1 ring-1 ring-white/25',
                    className,
                )}
            >
                <img
                    src={logoLight}
                    alt={alt}
                    className={cn(
                        'size-full object-contain',
                        logoDark !== logoLight && 'dark:hidden',
                    )}
                />
                {logoDark && logoDark !== logoLight && (
                    <img
                        src={logoDark}
                        alt={alt}
                        className="hidden size-full object-contain dark:block"
                    />
                )}
            </span>
        );
    }

    return (
        <span
            className={cn(
                'relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-[10px] text-white ring-1 ring-inset',
                variant === 'solid'
                    ? 'bg-linear-135 from-logo-from to-primary-strong shadow-sm ring-black/5 dark:ring-white/10'
                    : 'bg-white/12 ring-white/25 backdrop-blur-sm',
                className,
            )}
        >
            <AppLogoIcon className={cn('size-[18px]', iconClassName)} />
        </span>
    );
}
