import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

/**
 * Tiga pilihan tema sebagai kartu dengan pratinjau mini. Pratinjau memakai warna
 * literal (bukan token) karena harus menggambarkan tema lain dari yang sedang aktif.
 */
const OPTIONS: {
    value: Appearance;
    icon: LucideIcon;
    label: string;
    preview: string;
}[] = [
    {
        value: 'light',
        icon: Sun,
        label: 'Terang',
        preview: 'bg-[#f3f6f2] [--pv-card:#ffffff] [--pv-line:#d8e2da]',
    },
    {
        value: 'dark',
        icon: Moon,
        label: 'Gelap',
        preview: 'bg-[#121814] [--pv-card:#1a221d] [--pv-line:#2f3c33]',
    },
    {
        value: 'system',
        icon: Monitor,
        label: 'Ikuti sistem',
        preview:
            'bg-linear-to-r from-[#f3f6f2] from-50% to-[#121814] to-50% [--pv-card:#8a9a8f] [--pv-line:#8a9a8f]',
    },
];

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="radiogroup"
            aria-label="Tema"
            className={cn('grid grid-cols-3 gap-3', className)}
            {...props}
        >
            {OPTIONS.map(({ value, icon: Icon, label, preview }) => {
                const selected = appearance === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => updateAppearance(value)}
                        className={cn(
                            'group flex flex-col gap-2.5 rounded-xl border p-2 text-left transition-[border-color,box-shadow] duration-150 ease-out focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            selected
                                ? 'border-primary ring-1 ring-primary'
                                : 'hover:border-input',
                        )}
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'flex h-16 flex-col justify-end gap-1.5 overflow-hidden rounded-lg p-2.5 ring-1 ring-black/5 ring-inset',
                                preview,
                            )}
                        >
                            <span className="h-1.5 w-1/2 rounded-full bg-[#2f6b3b]" />
                            <span className="h-5 rounded-md border border-(--pv-line) bg-(--pv-card)" />
                        </span>
                        <span className="flex items-center gap-1.5 px-1 pb-0.5 text-sm leading-tight font-medium">
                            <Icon
                                className={cn(
                                    'size-4',
                                    selected
                                        ? 'text-primary'
                                        : 'text-muted-foreground',
                                )}
                            />
                            {label}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
