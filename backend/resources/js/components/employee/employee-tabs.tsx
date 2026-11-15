import { Link, router } from '@inertiajs/react';
import { NativeSelect } from '@/components/pakis/native-select';
import { PageTabs } from '@/components/pakis/page-tabs';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type EmployeeTab =
    | 'profil'
    | 'alamat'
    | 'penugasan'
    | 'jadwal'
    | 'presensi'
    | 'tugas'
    | 'akun'
    | 'riwayat';

export type TabGroup = {
    key: string;
    label: string;
    tabs: { key: EmployeeTab; label: string }[];
};

/**
 * Tab detail pegawai dikelompokkan: grup di baris pertama (chip), sub-tab di baris kedua
 * (segmented control; dropdown di HP). Modul riwayat kepegawaian berikutnya (Fase HR, `docs/13`)
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
    const group =
        TAB_GROUPS.find((g) => g.tabs.some((t) => t.key === active)) ??
        TAB_GROUPS[0];

    return (
        <div className="space-y-3">
            <PageTabs
                tabs={TAB_GROUPS.map((g) => ({
                    key: g.key,
                    label: g.label,
                    href: hrefFor(g.tabs[0].key),
                }))}
                active={group.key}
                label="Kelompok data pegawai"
            />
            {group.tabs.length > 1 && (
                <SubTabs
                    tabs={group.tabs}
                    active={active}
                    label={group.label}
                    hrefFor={hrefFor}
                />
            )}
        </div>
    );
}

/** Label sub-tab aktif (untuk `aria-label` bagian konten). */
export function tabLabel(tab: EmployeeTab): string {
    for (const g of TAB_GROUPS) {
        const found = g.tabs.find((t) => t.key === tab);
        if (found) {
            return found.label;
        }
    }

    return '';
}

/**
 * Segmented control di layar lebar, dropdown di HP (picker bawaan OS). Keduanya mengubah `?tab=`
 * sehingga posisi bisa dibagikan lewat URL.
 */
function SubTabs({
    tabs,
    active,
    label,
    hrefFor,
}: {
    tabs: { key: EmployeeTab; label: string }[];
    active: EmployeeTab;
    label: string;
    hrefFor: (tab: EmployeeTab) => string;
}) {
    return (
        <>
            <nav
                aria-label={`Bagian ${label}`}
                className="hidden w-fit gap-1 rounded-lg border bg-muted/60 p-1 sm:flex"
            >
                {tabs.map((t) => (
                    <Link
                        key={t.key}
                        href={hrefFor(t.key)}
                        preserveScroll
                        aria-current={t.key === active ? 'page' : undefined}
                        className={cn(
                            'rounded-md px-3.5 py-1.5 text-sm transition-colors duration-150',
                            t.key === active
                                ? 'bg-card font-semibold text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {t.label}
                    </Link>
                ))}
            </nav>
            <div className="sm:hidden">
                <Label htmlFor="employee-subtab" className="sr-only">
                    Bagian {label}
                </Label>
                <NativeSelect
                    id="employee-subtab"
                    value={active}
                    onChange={(e) =>
                        router.visit(hrefFor(e.target.value as EmployeeTab), {
                            preserveScroll: true,
                        })
                    }
                >
                    {tabs.map((t) => (
                        <option key={t.key} value={t.key}>
                            {t.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>
        </>
    );
}
