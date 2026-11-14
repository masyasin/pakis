import { ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { PageTabs } from '@/components/pakis/page-tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { edit, security } from '@/routes/admin/profile';

/** Profil & keamanan: dua tab di atas isi (permintaan Mas: navigasi berupa tab). */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();
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

    return (
        <div className="mx-auto w-full max-w-3xl px-4 py-6 md:px-6 md:py-8">
            <Heading
                title="Profil & keamanan"
                description="Data diri, password, verifikasi dua langkah, dan sesi yang sedang masuk."
            />

            <PageTabs
                label="Profil & keamanan"
                active={active}
                tabs={tabs}
                className="mb-6"
            />

            <section className="space-y-6">{children}</section>
        </div>
    );
}
