import { DetailTabs, detailTabLabel } from '@/components/pakis/detail-tabs';
import type { DetailTabGroup } from '@/components/pakis/detail-tabs';

export type EmployeeTab =
    | 'profil'
    | 'alamat'
    | 'penugasan'
    | 'jadwal'
    | 'presensi'
    | 'tugas'
    | 'akun'
    | 'riwayat';

export type TabGroup = DetailTabGroup<EmployeeTab>;

/**
 * Tab detail pegawai dikelompokkan: grup di baris pertama (chip), sub-tab di baris kedua
 * (segmented control; dropdown di HP) lewat `DetailTabs` bersama. Modul riwayat kepegawaian berikutnya (Fase HR, `docs/13`)
 * cukup menambah sub-tab di grupnya; grup tanpa sub-tab tidak ditampilkan.
 */
export const TAB_GROUPS: TabGroup[] = [
    {
        key: 'data-diri',
        label: 'Data Diri',
        tabs: [
            { key: 'profil', label: 'Profil' },
            { key: 'alamat', label: 'Alamat' },
        ],
    },
    {
        key: 'kepegawaian',
        label: 'Kepegawaian',
        tabs: [{ key: 'penugasan', label: 'Penugasan & Jabatan' }],
    },
    {
        key: 'kehadiran',
        label: 'Kehadiran',
        tabs: [
            { key: 'jadwal', label: 'Jadwal' },
            { key: 'presensi', label: 'Presensi' },
            { key: 'tugas', label: 'Tugas Luar Kota' },
        ],
    },
    {
        key: 'akun',
        label: 'Akun & Perangkat',
        tabs: [{ key: 'akun', label: 'Akun & Perangkat' }],
    },
    {
        key: 'riwayat',
        label: 'Riwayat Perubahan',
        tabs: [{ key: 'riwayat', label: 'Riwayat Perubahan' }],
    },
];

export function EmployeeTabs({
    active,
    hrefFor,
}: {
    active: EmployeeTab;
    hrefFor: (tab: EmployeeTab) => string;
}) {
    return (
        <DetailTabs
            groups={TAB_GROUPS}
            active={active}
            hrefFor={hrefFor}
            label="Kelompok data pegawai"
            selectId="employee-subtab"
        />
    );
}

/** Label sub-tab aktif (untuk `aria-label` bagian konten). */
export function tabLabel(tab: EmployeeTab): string {
    return detailTabLabel(TAB_GROUPS, tab);
}
