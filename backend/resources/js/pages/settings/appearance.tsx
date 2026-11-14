import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import { SettingsSection } from '@/components/settings-section';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';

export default function Appearance() {
    return (
        <>
            <Head title="Tampilan" />

            <h1 className="sr-only">Tampilan</h1>

            <SettingsSection
                title="Tema"
                description="Pilih terang, gelap, atau ikuti pengaturan perangkat. Pilihan diingat di browser ini."
            >
                <AppearanceTabs />
            </SettingsSection>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        { title: 'Pengaturan akun', href: editProfile() },
        { title: 'Tampilan', href: editAppearance() },
    ],
};
