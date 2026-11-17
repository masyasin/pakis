import { Link, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { NativeSelect } from '@/components/pakis/native-select';
import { PageTabs } from '@/components/pakis/page-tabs';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type DetailTab<K extends string = string> = {
    key: K;
    label: string;
    icon?: LucideIcon;
    /** Angka kecil di ujung tab; null/undefined = tanpa angka. */
    count?: number | null;
};

export type DetailTabGroup<K extends string = string> = {
    key: string;
    label: string;
    icon?: LucideIcon;
    tabs: DetailTab<K>[];
};

/**
 * Tab halaman detail (pola profil pegawai). Tab dibaca dari `?tab=` sehingga posisi bisa dibagikan
 * lewat URL. Dua bentuk:
 * - `tabs` datar: satu baris chip berikon (kios, produk, pengguna).
 * - `groups`: grup di baris pertama (chip), sub-tab di baris kedua (segmented control; dropdown di
 *   HP). Grup tanpa sub-tab tidak ditampilkan barisnya.
 */
export function DetailTabs<K extends string>({
    tabs,
    groups,
    active,
    hrefFor,
    label,
    selectId = 'detail-subtab',
}: {
    tabs?: DetailTab<K>[];
    groups?: DetailTabGroup<K>[];
    active: K;
    hrefFor: (tab: K) => string;
    /** `aria-label` navigasi, mis. "Bagian kios". */
    label: string;
    /** id dropdown sub-tab di HP (unik per halaman). */
    selectId?: string;
}) {
    const all: DetailTabGroup<K>[] =
        groups ??
        (tabs ?? []).map((t) => ({
            key: t.key,
            label: t.label,
            icon: t.icon,
            tabs: [t],
        }));
    const group =
        all.find((g) => g.tabs.some((t) => t.key === active)) ?? all[0];

    if (!group) {
        return null;
    }

    return (
        <div className="space-y-3">
            <PageTabs
                tabs={all.map((g) => ({
                    key: g.key,
                    label: g.label,
                    icon: g.icon,
                    count: g.tabs.length === 1 ? g.tabs[0].count : undefined,
                    href: hrefFor(g.tabs[0].key),
                }))}
                active={group.key}
                label={label}
            />
            {group.tabs.length > 1 && (
                <SubTabs
                    tabs={group.tabs}
                    active={active}
                    label={group.label}
                    hrefFor={hrefFor}
                    selectId={selectId}
                />
            )}
        </div>
    );
}

/**
 * Tab aktif dari `?tab=` URL; nilai tak dikenal (atau tab yang disembunyikan karena izin) jatuh ke
 * `fallback`.
 */
export function useQueryTab<K extends string>(
    keys: readonly K[],
    fallback: K,
): K {
    const url = usePage().url;
    const requested = new URLSearchParams(url.split('?')[1] ?? '').get('tab');

    return keys.includes(requested as K) ? (requested as K) : fallback;
}

/** Label tab dari daftar datar atau grup (untuk breadcrumb & `aria-label` konten). */
export function detailTabLabel<K extends string>(
    source: DetailTab<K>[] | DetailTabGroup<K>[],
    key: K,
): string {
    for (const item of source) {
        if ('tabs' in item) {
            const found = item.tabs.find((t) => t.key === key);
            if (found) {
                return found.label;
            }
        } else if (item.key === key) {
            return item.label;
        }
    }

    return '';
}

/**
 * Segmented control di layar lebar, dropdown di HP (picker bawaan OS). Keduanya mengubah `?tab=`.
 */
function SubTabs<K extends string>({
    tabs,
    active,
    label,
    hrefFor,
    selectId,
}: {
    tabs: DetailTab<K>[];
    active: K;
    label: string;
    hrefFor: (tab: K) => string;
    selectId: string;
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
                <Label htmlFor={selectId} className="sr-only">
                    Bagian {label}
                </Label>
                <NativeSelect
                    id={selectId}
                    value={active}
                    onChange={(e) =>
                        router.visit(hrefFor(e.target.value as K), {
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
