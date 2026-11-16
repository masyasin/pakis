import { ExternalLink } from 'lucide-react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import { diagramGeometry, osmLink } from '@/lib/location-diagram';
import type { DiagramArea, LatLng } from '@/lib/location-diagram';

type Props = {
    point: LatLng;
    area: DiagramArea | null;
    /** Jarak dari server (meter); bila ada dipakai untuk label agar sama dengan data. */
    distanceM?: number | null;
    withinRadius?: boolean;
    /** Nama titik di keterangan & legenda (default presensi; kios memakai "Titik kios"). */
    label?: string;
    /** Keterangan saat tanpa lokasi pembanding (default: kalimat presensi). */
    noAreaText?: string;
    /** Tautan "Buka di peta" pengganti OpenStreetMap (mis. Google Maps di form kios). */
    mapHref?: string;
    mapLabel?: string;
};

const formatMeters = (m: number) =>
    m >= 1000
        ? `${(m / 1000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} km`
        : `${Math.round(m).toLocaleString('id-ID')} m`;

/**
 * Diagram titik absen vs lokasi + lingkaran radius (07 §1, pengganti peta tile; keputusan Mas
 * 15 Nov 2026). Tidak memuat tile dari server luar: koordinat pegawai hanya keluar bila pengguna
 * mengeklik "Buka di peta".
 */
export function LocationDiagram({
    point,
    area,
    distanceM,
    withinRadius,
    label = 'Titik presensi',
    noAreaText,
    mapHref,
    mapLabel = 'Buka di peta',
}: Props) {
    const g = diagramGeometry(point, area);
    const titleId = useId();
    const distance = distanceM ?? g.distanceM;
    const outside = withinRadius === false;
    const pointColor = outside ? 'var(--status-danger)' : 'var(--primary)';

    const description = area
        ? `${label} ${formatMeters(distance)}${g.bearing ? ` ke arah ${g.bearing}` : ''} dari ${area.name}, radius ${formatMeters(area.radius_m)}: ${outside ? 'di luar' : 'di dalam'} radius.`
        : (noAreaText ?? `${label} (tanpa lokasi Pakis yang cocok).`);

    return (
        <figure className="space-y-2">
            <svg
                viewBox={`0 0 ${g.size} ${g.size}`}
                className="mx-auto block aspect-square w-full max-w-60 rounded-lg border bg-muted/30"
                role="img"
                aria-labelledby={titleId}
            >
                <title id={titleId}>{description}</title>
                {/* Kisi tipis sebagai rasa skala. */}
                {[0.25, 0.5, 0.75].map((f) => (
                    <g key={f} stroke="var(--border)" strokeWidth={0.5}>
                        <line
                            x1={g.size * f}
                            y1={0}
                            x2={g.size * f}
                            y2={g.size}
                        />
                        <line
                            x1={0}
                            y1={g.size * f}
                            x2={g.size}
                            y2={g.size * f}
                        />
                    </g>
                ))}
                {g.area && (
                    <>
                        <circle
                            cx={g.area.x}
                            cy={g.area.y}
                            r={g.area.r}
                            fill="var(--primary)"
                            fillOpacity={0.08}
                            stroke="var(--primary)"
                            strokeDasharray="4 3"
                        />
                        <line
                            x1={g.area.x}
                            y1={g.area.y}
                            x2={g.point.x}
                            y2={g.point.y}
                            stroke="var(--muted-foreground)"
                            strokeWidth={1}
                            strokeDasharray="2 2"
                        />
                        <rect
                            x={g.area.x - 4}
                            y={g.area.y - 4}
                            width={8}
                            height={8}
                            fill="var(--foreground)"
                        />
                    </>
                )}
                <circle
                    cx={g.point.x}
                    cy={g.point.y}
                    r={6}
                    fill={pointColor}
                    stroke="var(--background)"
                    strokeWidth={2}
                />
                {/* Penanda utara. */}
                <text
                    x={g.size - 14}
                    y={18}
                    fontSize={11}
                    fontWeight={600}
                    fill="var(--muted-foreground)"
                    textAnchor="middle"
                >
                    U
                </text>
                <path
                    d={`M ${g.size - 14} 22 l -4 8 l 8 0 z`}
                    fill="var(--muted-foreground)"
                />
            </svg>
            <figcaption className="space-y-2 text-center text-xs text-muted-foreground">
                <span className="block">{description}</span>
                <span className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
                    {area && (
                        <span className="inline-flex items-center gap-1">
                            <span
                                className="inline-block size-2 bg-foreground"
                                aria-hidden="true"
                            />
                            Lokasi
                        </span>
                    )}
                    <span className="inline-flex items-center gap-1">
                        <span
                            className="inline-block size-2 rounded-full"
                            style={{ background: pointColor }}
                            aria-hidden="true"
                        />
                        {label}
                    </span>
                </span>
                <Button variant="outline" size="sm" asChild>
                    <a
                        href={mapHref ?? osmLink(point)}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink aria-hidden="true" />
                        {mapLabel}
                    </a>
                </Button>
            </figcaption>
        </figure>
    );
}
