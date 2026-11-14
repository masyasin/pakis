import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * <select> native bergaya input shadcn. Dipilih untuk filter & per halaman karena memakai picker
 * bawaan OS di HP, aksesibel tanpa JS tambahan, dan bisa diuji di DOM tiruan.
 */
export function NativeSelect({
    className,
    ...props
}: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs transition-[border-color,box-shadow] duration-150 outline-none',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                'disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}
