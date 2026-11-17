import { cn } from '@/lib/utils';

/**
 * Motif daun pakis untuk pita kepala detail (DetailHero). Gambar orisinal yang DIHITUNG di sini
 * (batang lengkung kuadratik + anak daun berselang yang mengecil ke ujung + satu gulungan pucuk),
 * bukan salinan logo/aset: cukup sebagai tekstur halus yang mengingatkan nama "Pakis".
 *
 * Inline SVG (bukan berkas/CDN/data: URI) agar tidak bergantung pada CSP `img-src`, ikut warna
 * `currentColor`, dan dihitung sekali saat modul dimuat.
 */

type Point = [number, number];

const VIEW_W = 560;
const VIEW_H = 64;

const r1 = (n: number) => Math.round(n * 10) / 10;
const pt = ([x, y]: Point) => `${r1(x)} ${r1(y)}`;

function quad(p0: Point, c: Point, p1: Point, t: number): Point {
    const u = 1 - t;

    return [
        u * u * p0[0] + 2 * u * t * c[0] + t * t * p1[0],
        u * u * p0[1] + 2 * u * t * c[1] + t * t * p1[1],
    ];
}

function quadTangent(p0: Point, c: Point, p1: Point, t: number): Point {
    const dx = 2 * (1 - t) * (c[0] - p0[0]) + 2 * t * (p1[0] - c[0]);
    const dy = 2 * (1 - t) * (c[1] - p0[1]) + 2 * t * (p1[1] - c[1]);
    const len = Math.hypot(dx, dy) || 1;

    return [dx / len, dy / len];
}

type Frond = {
    base: Point;
    control: Point;
    tip: Point;
    /** Panjang anak daun terpanjang (satuan viewBox). */
    leaf: number;
    /** Jumlah anak daun per sisi. */
    pairs: number;
};

/** Satu pelepah: batang (stroke) + anak daun berselang kiri/kanan (fill). */
function frondPaths({ base, control, tip, leaf, pairs }: Frond): {
    stem: string;
    leaves: string;
} {
    const stem = `M${pt(base)} Q${pt(control)} ${pt(tip)}`;
    const parts: string[] = [];
    // Anak daun condong ±32° ke arah ujung, seperti pinnae pakis.
    const lean = (32 * Math.PI) / 180;

    for (let i = 0; i < pairs * 2; i++) {
        const side = i % 2 === 0 ? 1 : -1;
        const t = 0.1 + (0.86 * i) / (pairs * 2);
        const p = quad(base, control, tip, t);
        const [dx, dy] = quadTangent(base, control, tip, t);
        const nx = -dy * side;
        const ny = dx * side;
        const dirX = nx * Math.cos(lean) + dx * Math.sin(lean);
        const dirY = ny * Math.cos(lean) + dy * Math.sin(lean);
        // Terpanjang di sepertiga bawah, mengecil ke ujung; pangkal sedikit lebih pendek.
        const len =
            leaf * Math.pow(1 - t, 0.75) * (0.7 + 0.3 * Math.min(1, t / 0.25));
        const width = len * 0.3;
        const q: Point = [p[0] + dirX * len, p[1] + dirY * len];
        const mid: Point = [p[0] + dirX * len * 0.5, p[1] + dirY * len * 0.5];
        const c1: Point = [mid[0] - dirY * width, mid[1] + dirX * width];
        const c2: Point = [mid[0] + dirY * width, mid[1] - dirX * width];
        parts.push(`M${pt(p)} Q${pt(c1)} ${pt(q)} Q${pt(c2)} ${pt(p)}Z`);
    }

    return { stem, leaves: parts.join('') };
}

/** Gulungan pucuk pakis (koru): spiral logaritmik yang menyambung ke tangkai pendek. */
function koruPath(center: Point, turns: number, scale: number): string {
    const steps = 48;
    const points: string[] = [];

    for (let i = 0; i <= steps; i++) {
        const theta = (i / steps) * turns * 2 * Math.PI;
        const r = scale * Math.exp(0.22 * theta) * 0.18;
        points.push(
            pt([
                center[0] + r * Math.cos(theta),
                center[1] + r * Math.sin(theta),
            ]),
        );
    }

    const end = points[points.length - 1].split(' ').map(Number) as Point;

    return `M${points.join(' L')} Q${pt([end[0] + scale * 0.4, end[1] + scale * 3])} ${pt([end[0] + scale * 2.4, end[1] + scale * 6])}`;
}

// Tata letak untuk pita ±10:1: pelepah besar melengkung di atas, satu di bawah, satu tegak di
// tepi kanan, dan gulungan pucuk di ujung kiri motif.
const FRONDS = (
    [
        {
            base: [590, 72],
            control: [480, 0],
            tip: [300, 40],
            leaf: 30,
            pairs: 18,
        },
        {
            base: [440, 96],
            control: [380, 44],
            tip: [250, 58],
            leaf: 18,
            pairs: 12,
        },
        {
            base: [562, 92],
            control: [550, 40],
            tip: [506, 0],
            leaf: 16,
            pairs: 9,
        },
    ] satisfies Frond[]
).map(frondPaths);

const KORU = koruPath([228, 30], 2, 6);

export function FernMotif({ className }: { className?: string }) {
    return (
        <svg
            aria-hidden="true"
            focusable="false"
            viewBox={`0 0 ${VIEW_W} ${VIEW_H}`}
            preserveAspectRatio="xMaxYMid slice"
            className={cn('pointer-events-none select-none', className)}
            data-testid="fern-motif"
        >
            <g fill="currentColor" stroke="currentColor" strokeLinecap="round">
                {FRONDS.map((f, i) => (
                    <g key={i}>
                        <path d={f.stem} fill="none" strokeWidth={2.2} />
                        <path d={f.leaves} stroke="none" />
                    </g>
                ))}
                <path d={KORU} fill="none" strokeWidth={2.4} />
            </g>
        </svg>
    );
}
