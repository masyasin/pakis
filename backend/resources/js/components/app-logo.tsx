import { usePage } from '@inertiajs/react';
import { AppLogoMark } from '@/components/app-logo-mark';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoMark />
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-[15px] font-semibold tracking-tight">
                    {name || 'PAKIS ONE'}
                </span>
                <span className="truncate text-xs text-muted-foreground">
                    CV Pakis Jaya Abadi
                </span>
            </div>
        </>
    );
}
