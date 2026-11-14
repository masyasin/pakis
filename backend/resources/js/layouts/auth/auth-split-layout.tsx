import { usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { AppLogoMark } from '@/components/app-logo-mark';
import { AuthFieldArt } from '@/components/auth-field-art';
import type { AuthLayoutProps } from '@/types';

/**
 * Layout auth PAKIS ONE. Desktop: panel hijau tua (identitas) + area form.
 * HP: panel menyusut jadi pita header hijau seperti prototipe Pakis Pro,
 * kartu form menumpang di atasnya. Teks putih di panel ≥ 8.69:1 (warna kustom: dijaga
 * BrandTheme ≥ 4.5:1). Warna panel dari token `--brand-panel-*`.
 */
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name, branding } = usePage().props;
    const appName = name || 'PAKIS ONE';
    const company = branding?.company || 'CV Pakis Jaya Abadi';
    const tagline = branding?.loginTagline;

    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <aside className="relative isolate overflow-hidden bg-linear-160 from-brand-panel-from via-brand-panel-via to-brand-panel-to px-6 pt-8 pb-20 text-white sm:px-10 lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
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
                        <p className="text-sm text-brand-panel-muted">
                            {company}
                        </p>
                    </div>
                </div>

                <div className="mt-8 max-w-md lg:mt-0">
                    {tagline && (
                        <p className="text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-3xl lg:text-[2.75rem] lg:leading-[1.1]">
                            {tagline}
                        </p>
                    )}
                    <p className="mt-4 hidden max-w-sm text-base leading-relaxed text-brand-panel-muted lg:block">
                        Satu tempat untuk data organisasi, pegawai, dan
                        kehadiran tim di kantor maupun di lapangan.
                    </p>
                </div>

                <p className="hidden text-sm text-brand-panel-muted lg:block">
                    Kudus, Jawa Tengah
                </p>
            </aside>

            <main className="relative -mt-12 flex flex-col items-center px-4 pb-10 sm:px-6 lg:mt-0 lg:justify-center lg:px-12 lg:py-12">
                <div className="w-full max-w-[420px] animate-in duration-500 ease-out fade-in slide-in-from-bottom-2">
                    {/* Catatan akses jadi kaki kartu: kartu satu-satunya blok yang dipusatkan, dan semua
                        teks berbagi tepi kiri/tengah yang sama dengan isi kartu. */}
                    <div className="rounded-2xl border bg-card text-card-foreground shadow-lg">
                        {/* Isian setinggi tombol utama (size lg, 44 px) di semua halaman auth. */}
                        <div className="p-6 sm:p-9 [&_input[data-slot=input]]:h-11">
                            <header className="mb-7 space-y-2">
                                <h1 className="text-2xl leading-tight font-bold tracking-tight">
                                    {title}
                                </h1>
                                {description && (
                                    <p className="text-[15px] leading-relaxed text-pretty text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </header>
                            {children}
                        </div>
                        <p className="flex items-center justify-center gap-2 border-t px-6 py-4 text-center text-[13px] text-muted-foreground sm:px-9">
                            <ShieldCheck
                                className="size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            Akses khusus pegawai {company}.
                        </p>
                    </div>
                </div>
            </main>
        </div>
    );
}
