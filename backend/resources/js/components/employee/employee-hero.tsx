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
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/employee-format';

export type HeroSummary = { label: string; value: ReactNode }[];

/**
 * Kartu identitas di atas detail pegawai: pita gradien warna merek (token `brand-panel-*`, ikut
 * Pengaturan branding), avatar, nama, nomor, status, jabatan & lokasi aktif, aksi, dan ringkasan.
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
    editHref,
    onDelete,
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
    editHref: NonNullable<InertiaLinkProps['href']> | null;
    onDelete: (() => void) | null;
}) {
    const initials = name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04),0_8px_24px_-12px_rgb(0_0_0/0.12)]">
            <div
                aria-hidden="true"
                className="relative h-20 bg-gradient-to-r from-primary via-primary/85 to-primary-strong sm:h-24"
            >
                {/* Kilau halus agar pita tidak datar. */}
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_88%_-30%,rgb(255_255_255/0.22),transparent_50%),radial-gradient(circle_at_10%_130%,rgb(255_255_255/0.10),transparent_45%)]" />
            </div>

            <div className="px-4 pb-5 sm:px-6">
                <div className="relative z-10 -mt-10 flex flex-wrap items-end justify-between gap-3 sm:-mt-12">
                    {photoUrl ? (
                        <img
                            src={photoUrl}
                            alt={`Foto ${name}`}
                            className="size-20 rounded-full object-cover ring-4 ring-card sm:size-24"
                        />
                    ) : (
                        <span
                            aria-hidden="true"
                            className="grid size-20 place-items-center rounded-full bg-sidebar-accent text-2xl font-bold text-primary ring-4 ring-card sm:size-24 sm:text-3xl"
                        >
                            {initials}
                        </span>
                    )}

                    {(editHref || onDelete) && (
                        <div className="flex gap-2">
                            {editHref && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={editHref}
                                        aria-label={`Ubah data ${name}`}
                                    >
                                        <Pencil aria-hidden="true" />
                                        <span className="hidden sm:inline">
                                            Ubah
                                        </span>
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
                                    <span className="hidden sm:inline">
                                        Hapus
                                    </span>
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                <div className="mt-3 space-y-2">
                    <h1 className="text-[1.5rem] leading-tight font-bold break-words sm:text-[1.75rem]">
                        {name}
                    </h1>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-md border bg-muted px-2 py-0.5 font-mono text-xs tabular-nums">
                            {number}
                        </span>
                        <StatusBadge status={`employee.${status}`} />
                    </div>
                    <ul className="flex flex-wrap gap-x-5 gap-y-1.5 pt-1 text-sm text-muted-foreground">
                        <Meta icon={Briefcase} label="Jabatan">
                            {position}
                        </Meta>
                        <Meta icon={MapPin} label="Lokasi">
                            {location}
                        </Meta>
                        <Meta icon={Building2} label="Departemen">
                            {department}
                        </Meta>
                        <Meta icon={CalendarDays} label="Tanggal masuk">
                            Masuk {formatDate(joinDate)}
                        </Meta>
                    </ul>
                </div>
            </div>

            <dl className="grid grid-cols-2 border-t sm:grid-cols-4">
                {summary.map((s, i) => (
                    <div
                        key={s.label}
                        className={[
                            'min-w-0 px-4 py-3 sm:px-6',
                            i % 2 === 1 ? 'border-l' : '',
                            i >= 2 ? 'border-t sm:border-t-0' : '',
                            i === 2 ? 'sm:border-l' : '',
                        ].join(' ')}
                    >
                        <dt className="text-xs text-muted-foreground">
                            {s.label}
                        </dt>
                        <dd className="mt-0.5 truncate text-sm font-semibold">
                            {s.value}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

function Meta({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    if (children === null || children === undefined || children === '') {
        return null;
    }

    return (
        <li className="inline-flex items-center gap-1.5">
            <Icon className="size-4 shrink-0" aria-hidden="true" />
            <span className="sr-only">{label}: </span>
            <span className="text-foreground/80">{children}</span>
        </li>
    );
}
