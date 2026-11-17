import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import {
    Briefcase,
    Building2,
    CalendarDays,
    MapPin,
    Pencil,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { ActionLabel, DetailHero } from '@/components/pakis/detail-hero';
import type {
    DetailHeroHighlight,
    DetailHeroSummary,
} from '@/components/pakis/detail-hero';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/employee-format';

export type HeroSummary = DetailHeroSummary;

/**
 * Kartu identitas di atas detail pegawai: avatar, nama, nomor, status, jabatan & lokasi aktif, aksi,
 * dan ringkasan. Tampilannya dari `DetailHero` bersama (acuan semua halaman detail lain).
 */
export function EmployeeHero({
    name,
    number,
    status,
    photoUrl,
    position,
    location,
    department,
    joinDate,
    summary,
    highlights,
    editHref,
    onDelete,
    extraActions,
    notice,
}: {
    name: string;
    number: string;
    status: 'active' | 'inactive';
    photoUrl: string | null;
    position: string | null;
    location: string | null;
    department: string | null;
    joinDate: string;
    summary: HeroSummary;
    /** Sorotan status di pita kepala (masa kerja, status kerja). */
    highlights?: DetailHeroHighlight[];
    editHref: NonNullable<InertiaLinkProps['href']> | null;
    onDelete: (() => void) | null;
    /** Aksi tambahan (Nonaktifkan / Aktifkan kembali, T-026). */
    extraActions?: ReactNode;
    /** Pita info di bawah identitas (mis. "Nonaktif sejak …"). */
    notice?: ReactNode;
}) {
    const initials = name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
    const hasActions = Boolean(editHref || onDelete || extraActions);

    return (
        <DetailHero
            title={name}
            code={number}
            badges={<StatusBadge status={`employee.${status}`} />}
            avatar={{ photoUrl, photoAlt: `Foto ${name}`, initials }}
            // Pegawai nonaktif: pita merek diredupkan agar statusnya terbaca sekilas.
            muted={status === 'inactive'}
            meta={[
                { icon: Briefcase, label: 'Jabatan', value: position },
                { icon: MapPin, label: 'Lokasi', value: location },
                { icon: Building2, label: 'Departemen', value: department },
                {
                    icon: CalendarDays,
                    label: 'Tanggal masuk',
                    value: <>Masuk {formatDate(joinDate)}</>,
                },
            ]}
            notice={notice}
            summary={summary}
            highlights={highlights}
            actions={
                hasActions ? (
                    <>
                        {extraActions}
                        {editHref && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={editHref}
                                    aria-label={`Ubah data ${name}`}
                                >
                                    <Pencil aria-hidden="true" />
                                    <ActionLabel>Ubah</ActionLabel>
                                </Link>
                            </Button>
                        )}
                        {onDelete && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                onClick={onDelete}
                                aria-label={`Hapus ${name}`}
                            >
                                <Trash2 aria-hidden="true" />
                                <ActionLabel>Hapus</ActionLabel>
                            </Button>
                        )}
                    </>
                ) : undefined
            }
        />
    );
}
