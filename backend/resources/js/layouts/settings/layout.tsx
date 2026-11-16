import { usePage } from '@inertiajs/react';
import {
    MailCheck,
    MailWarning,
    ShieldCheck,
    ShieldOff,
    UserRound,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { PageTabs } from '@/components/pakis/page-tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { edit, security } from '@/routes/admin/profile';
import type { Auth } from '@/types';

/**
 * Profil & keamanan: kartu identitas (seragam dengan detail Pengguna/Pegawai) lalu dua tab
 * (permintaan Mas: navigasi berupa tab). Kartu memperlihatkan status yang paling sering dicek
 * pengguna sendiri: verifikasi dua langkah dan email.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();
    const { auth } = usePage<{ auth: Auth }>().props;
    const user = auth.user;
    const tabs = [
        { key: 'profil', label: 'Profil', href: edit(), icon: UserRound },
        {
            key: 'keamanan',
            label: 'Keamanan',
            href: security(),
            icon: ShieldCheck,
        },
    ];
    const active = isCurrentUrl(security()) ? 'keamanan' : 'profil';
    // Inisial sama dengan avatar di header & form foto (hook yang sama), bukan versi buatan sendiri.
    const initials = useInitials()(user.name);

    return (
        <div className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6">
            <section className="overflow-hidden rounded-xl border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04),0_8px_24px_-12px_rgb(0_0_0/0.12)]">
                <div
                    aria-hidden="true"
                    className="relative h-16 bg-gradient-to-r from-primary via-primary/85 to-primary-strong sm:h-20"
                >
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_88%_-30%,rgb(255_255_255/0.22),transparent_50%),radial-gradient(circle_at_10%_130%,rgb(255_255_255/0.10),transparent_45%)]" />
                </div>

                <div className="flex flex-col gap-3 px-4 pb-5 sm:flex-row sm:items-end sm:gap-4 sm:px-6">
                    {user.avatar ? (
                        <img
                            src={user.avatar}
                            alt={`Foto ${user.name}`}
                            className="relative z-10 -mt-9 size-18 shrink-0 rounded-full object-cover ring-4 ring-card sm:-mt-10 sm:size-20"
                        />
                    ) : (
                        <span
                            aria-hidden="true"
                            className="relative z-10 -mt-9 grid size-18 shrink-0 place-items-center rounded-full bg-sidebar-accent text-xl font-bold text-primary ring-4 ring-card sm:-mt-10 sm:size-20 sm:text-2xl"
                        >
                            {initials}
                        </span>
                    )}

                    <div className="min-w-0 flex-1 space-y-1.5 sm:pt-2">
                        <h1 className="text-[1.35rem] leading-tight font-bold break-words sm:text-[1.5rem]">
                            {user.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                            <span className="rounded-md border bg-muted px-2 py-0.5 font-mono text-xs">
                                {user.username}
                            </span>
                            {user.two_factor_enabled ? (
                                <span className="inline-flex items-center gap-1 text-status-success">
                                    <ShieldCheck
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Verifikasi dua langkah aktif
                                </span>
                            ) : (
                                <span className="inline-flex items-center gap-1 text-status-warning">
                                    <ShieldOff
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Verifikasi dua langkah belum aktif
                                </span>
                            )}
                            {user.email &&
                                (user.email_verified_at ? (
                                    <span className="inline-flex items-center gap-1 text-muted-foreground">
                                        <MailCheck
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Email terverifikasi
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-1 text-status-warning">
                                        <MailWarning
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Email belum diverifikasi
                                    </span>
                                ))}
                        </div>
                    </div>
                </div>
            </section>

            <PageTabs label="Profil & keamanan" active={active} tabs={tabs} />

            <section className="space-y-6">{children}</section>
        </div>
    );
}
