import { usePage } from '@inertiajs/react';
import { AppLogoMark } from '@/components/app-logo-mark';
import { AuthFieldArt } from '@/components/auth-field-art';
import type { AuthLayoutProps } from '@/types';

/**
 * Layout auth PAKIS ONE. Desktop: panel hijau tua (identitas) + area form.
 * HP: panel menyusut jadi pita header hijau seperti prototipe Pakis Pro,
 * kartu form menumpang di atasnya. Teks putih di panel ≥ 8.69:1.
 */
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;
    const appName = name || 'PAKIS ONE';

    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <aside className="relative isolate overflow-hidden bg-linear-160 from-[#2a6036] via-[#24552f] to-[#163a20] px-6 pt-8 pb-20 text-white sm:px-10 lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16 dark:from-[#2c5a37] dark:via-[#1f4329] dark:to-[#11271a]">
                <div
                    aria-hidden="true"
                    className="absolute -top-32 -right-24 -z-10 size-96 rounded-full bg-[radial-gradient(circle,rgb(224_172_99/0.28),transparent_65%)]"
                />
                <AuthFieldArt className="absolute inset-0 -z-10 size-full text-white" />

                <div className="flex items-center gap-3">
                    <AppLogoMark
                        variant="glass"
                        className="size-10 rounded-xl"
                        iconClassName="size-5"
                    />
                    <div className="leading-tight">
                        <p className="text-lg font-semibold tracking-tight">
                            {appName}
                        </p>
                        <p className="text-sm text-[#cfe3d4]">
                            CV Pakis Jaya Abadi
                        </p>
                    </div>
                </div>

                <div className="mt-8 max-w-md lg:mt-0">
                    <p className="text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-3xl lg:text-[2.75rem] lg:leading-[1.1]">
                        Distribusi pestisida &amp; alat pertanian
                    </p>
                    <p className="mt-4 hidden max-w-sm text-base leading-relaxed text-[#cfe3d4] lg:block">
                        Satu tempat untuk data organisasi, pegawai, dan
                        kehadiran tim di kantor maupun di lapangan.
                    </p>
                </div>

                <p className="hidden text-sm text-[#cfe3d4] lg:block">
                    Kudus, Jawa Tengah
                </p>
            </aside>

            <main className="relative -mt-12 flex flex-col items-center px-4 pb-10 sm:px-6 lg:mt-0 lg:justify-center lg:px-12 lg:py-12">
                <div className="w-full max-w-[420px] animate-in duration-500 ease-out fade-in slide-in-from-bottom-2">
                    <div className="rounded-2xl border bg-card p-6 text-card-foreground shadow-lg sm:p-9">
                        <header className="mb-7 space-y-2">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            {description && (
                                <p className="text-[15px] leading-relaxed text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </header>
                        {children}
                    </div>

                    <p className="mt-8 text-center text-sm text-muted-foreground lg:text-left">
                        Akses khusus pegawai CV Pakis Jaya Abadi.
                    </p>
                </div>
            </main>
        </div>
    );
}
