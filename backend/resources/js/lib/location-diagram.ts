/**
 * Proyeksi diagram lokasi absen (T-038, pengganti peta tile 07 §1, keputusan Mas 15 Nov 2026).
 * Murni di browser, tanpa library & tanpa request ke server peta: koordinat pegawai tidak
 * terkirim ke pihak luar kecuali pengguna sendiri mengeklik "Buka di peta".
 *
 * Proyeksi lokal equirectangular di sekitar titik pusat: untuk jarak < puluhan km galatnya jauh di
 * bawah akurasi GPS HP, cukup untuk "titik absen vs radius lokasi".
 */
export type LatLng = { lat: number; lng: number };

export type DiagramArea = LatLng & { radius_m: number; name: string };

export type DiagramGeometry = {
    /** Sisi viewBox (persegi), satuan SVG. */
    size: number;
    /** Meter per satuan SVG. */
    metersPerUnit: number;
    center: { x: number; y: number };
    point: { x: number; y: number };
    area: { x: number; y: number; r: number } | null;
    distanceM: number;
    /** Arah dari pusat lokasi ke titik absen (8 mata angin), null bila tanpa lokasi atau berimpit. */
    bearing: string | null;
};

const SIZE = 240;
/** Margin agar titik & lingkaran tidak menempel tepi (proporsi setengah sisi). */
const FILL = 0.8;
const MIN_HALF_EXTENT_M = 50;
const EARTH_RADIUS_M = 6_371_000;

const COMPASS = [
    'Utara',
    'Timur Laut',
    'Timur',
    'Tenggara',
    'Selatan',
    'Barat Daya',
    'Barat',
    'Barat Laut',
] as const;

/** Meter timur & utara dari `origin` ke `p`. */
export function offsetMeters(
    origin: LatLng,
    p: LatLng,
): { east: number; north: number } {
    const rad = Math.PI / 180;
    const north = (p.lat - origin.lat) * rad * EARTH_RADIUS_M;
    const east =
        (p.lng - origin.lng) *
        rad *
        EARTH_RADIUS_M *
        Math.cos(origin.lat * rad);

    return { east, north };
}

export function bearingLabel(east: number, north: number): string | null {
    if (Math.hypot(east, north) < 1) {
        return null;
    }
    // 0° = utara, searah jarum jam.
    const degrees = (Math.atan2(east, north) * 180) / Math.PI;
    const index = Math.round((((degrees % 360) + 360) % 360) / 45) % 8;

    return COMPASS[index];
}

/**
 * Geometri diagram: pusat = titik lokasi (bila ada), skala otomatis agar lingkaran radius dan titik
 * absen sama-sama terlihat. Sumbu y SVG ke bawah, jadi utara = y negatif.
 */
export function diagramGeometry(
    point: LatLng,
    area: DiagramArea | null,
): DiagramGeometry {
    const origin = area ?? point;
    const { east, north } = offsetMeters(origin, point);
    const distanceM = Math.hypot(east, north);
    const halfExtent = Math.max(
        MIN_HALF_EXTENT_M,
        (area?.radius_m ?? 0) * 1.15,
        distanceM * 1.15,
    );
    const metersPerUnit = halfExtent / ((SIZE / 2) * FILL);
    const c = SIZE / 2;

    return {
        size: SIZE,
        metersPerUnit,
        center: { x: c, y: c },
        point: { x: c + east / metersPerUnit, y: c - north / metersPerUnit },
        area:
            area === null
                ? null
                : { x: c, y: c, r: area.radius_m / metersPerUnit },
        distanceM,
        bearing: area === null ? null : bearingLabel(east, north),
    };
}

/** Tautan OpenStreetMap ke titik itu (dibuka pengguna sendiri, tab baru). */
export function osmLink(p: LatLng): string {
    const lat = p.lat.toFixed(6);
    const lng = p.lng.toFixed(6);

    return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=18/${lat}/${lng}`;
}
