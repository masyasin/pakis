import { usePage } from '@inertiajs/react';
import { AppLogoMark } from '@/components/app-logo-mark';

/**
 * Logo + nama aplikasi + perusahaan di kepala sidebar/header. Nama tebal dan nama perusahaan
 * berkontras cukup (bukan `muted` kecil) agar terbaca; saat sidebar diciutkan hanya lencana
 * yang tampil dengan ukuran ikon menu.
 */
export default function AppLogo() {
    const { name, branding } = usePage().props;

    return (
        <>
            <AppLogoMark
                className="size-10 rounded-xl group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:rounded-[10px]"
                iconClassName="size-[22px] group-data-[collapsible=icon]:size-[18px]"
            />
            <div className="ml-1.5 grid flex-1 text-left leading-tight">
                <span className="truncate text-[17px] font-extrabold tracking-tight">
                    {name || 'PAKIS ONE'}
                </span>
                <span className="truncate text-[13px] font-medium text-foreground/70">
                    {branding?.company || 'CV Pakis Jaya Abadi'}
                </span>
            </div>
        </>
    );
}
