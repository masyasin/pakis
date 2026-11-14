import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * Satu tombol ikon: terang ↔ gelap. Pilihan "Ikuti sistem" tetap ada di
 * Pengaturan akun → Tampilan. Pilihan diingat lewat useAppearance
 * (localStorage + cookie) dan diterapkan sebelum render oleh app.blade.php.
 */
export function ThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';
    const label = isDark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={label}
                    onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
                    data-test="theme-toggle"
                    className="text-muted-foreground hover:text-foreground"
                >
                    {isDark ? (
                        <Sun className="size-[18px]" />
                    ) : (
                        <Moon className="size-[18px]" />
                    )}
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
