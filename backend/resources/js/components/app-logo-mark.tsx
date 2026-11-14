import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

/**
 * Lencana logo: daun putih di atas hijau tua. Di mode gelap memakai hijau
 * `--primary-strong` agar daun putih tetap kontras (8.0:1), bukan hijau muda primer.
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
    return (
        <span
            className={cn(
                'relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-[10px] text-white ring-1 ring-inset',
                variant === 'solid'
                    ? 'bg-linear-135 from-primary to-primary-strong shadow-sm ring-black/5 dark:from-[#3b7a4a] dark:to-primary-strong dark:ring-white/10'
                    : 'bg-white/12 ring-white/25 backdrop-blur-sm',
                className,
            )}
        >
            <AppLogoIcon className={cn('size-[18px]', iconClassName)} />
        </span>
    );
}
