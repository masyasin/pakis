import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    LogOut,
    ScanLine,
    ShieldCheck,
    Smartphone,
} from 'lucide-react';
import ManageTwoFactor from '@/components/manage-two-factor';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { dashboard } from '@/routes/admin';

type Props = {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
    recoveryCodesViewable: boolean;
};

const STEPS = [
    {
        icon: Smartphone,
        text: 'Pasang aplikasi autentikator di HP (mis. Google Authenticator atau Microsoft Authenticator).',
    },
    {
        icon: ScanLine,
        text: 'Tekan "Aktifkan 2FA", lalu pindai kode QR dengan aplikasi itu.',
    },
    {
        icon: ShieldCheck,
        text: 'Masukkan kode 6 digit dari aplikasi, lalu simpan kode pemulihan.',
    },
];

/**
 * Wajib aktivasi MFA (BR-015, SEC-007): role pengguna mewajibkan verifikasi dua langkah. Menu
 * lain terkunci sampai aktif; setelah aktif, halaman ini menampilkan kode pemulihan SEKALI.
 */
export default function MfaSetupRequired({
    requiresConfirmation,
    twoFactorEnabled,
    recoveryCodesViewable,
}: Props) {
    return (
        <>
            <Head title="Aktifkan verifikasi dua langkah" />

            {!twoFactorEnabled && (
                <ol className="mb-5 space-y-3">
                    {STEPS.map(({ icon: Icon, text }, index) => (
                        <li key={index} className="flex gap-3 text-sm">
                            <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-sidebar-accent text-sidebar-accent-foreground">
                                <Icon className="size-4" aria-hidden="true" />
                            </span>
                            <span className="pt-1">{text}</span>
                        </li>
                    ))}
                </ol>
            )}

            <ManageTwoFactor
                canManageTwoFactor
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                mfaRequired
                recoveryCodesViewable={recoveryCodesViewable}
            />

            {twoFactorEnabled && (
                <Button asChild size="lg" className="mt-5 w-full">
                    <Link href={dashboard()}>
                        Sudah saya simpan, lanjut ke Dasbor
                        <ArrowRight aria-hidden="true" />
                    </Link>
                </Button>
            )}

            <Link
                href={logout()}
                as="button"
                className="mx-auto mt-6 flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <LogOut className="size-4" aria-hidden="true" />
                Keluar
            </Link>
        </>
    );
}

MfaSetupRequired.layout = {
    title: 'Aktifkan verifikasi dua langkah',
    description:
        'Role Anda mewajibkan verifikasi dua langkah (MFA). Menu lain terbuka setelah MFA aktif.',
};
