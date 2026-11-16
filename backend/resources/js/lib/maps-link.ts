/**
 * Koordinat dari tautan Google Maps yang DITEMPEL pengguna (T-021). Parsing murni di browser,
 * tanpa request: tautan pendek (`maps.app.goo.gl`, `goo.gl/maps`) sengaja tidak di-resolve karena
 * butuh mengikuti redirect ke situs luar. Pengguna diminta membuka tautannya dulu lalu menyalin
 * alamat lengkap dari address bar.
 */
export type MapsParseResult =
    | { ok: true; latitude: string; longitude: string }
    | { ok: false; reason: 'short-link' | 'not-found' | 'outside-indonesia' };

/** Batas wilayah Indonesia (04 §2.8), sama dengan validasi server. */
export const INDONESIA_BOUNDS = {
    lat: [-11, 6],
    lng: [94, 142],
} as const;

const NUMBER = String.raw`-?\d{1,3}(?:\.\d+)?`;

// Urutan penting: `!3d…!4d…` = titik tempat yang dipilih, lebih tepat daripada `@…` (pusat layar peta).
const PATTERNS: RegExp[] = [
    new RegExp(String.raw`!3d(${NUMBER})!4d(${NUMBER})`),
    new RegExp(
        String.raw`[?&](?:q|query|ll|destination|daddr)=(${NUMBER}),\s*(${NUMBER})`,
    ),
    new RegExp(String.raw`@(${NUMBER}),(${NUMBER})`),
    // Teks koordinat polos ("-6.8123, 110.8456") juga diterima.
    new RegExp(String.raw`^\s*(${NUMBER})\s*,\s*(${NUMBER})\s*$`),
];

export function inIndonesia(latitude: number, longitude: number): boolean {
    return (
        latitude >= INDONESIA_BOUNDS.lat[0] &&
        latitude <= INDONESIA_BOUNDS.lat[1] &&
        longitude >= INDONESIA_BOUNDS.lng[0] &&
        longitude <= INDONESIA_BOUNDS.lng[1]
    );
}

/** Dibulatkan ke 7 desimal = presisi kolom DECIMAL(10,7). */
function round7(value: string): string {
    return String(Number(Number(value).toFixed(7)));
}

export function parseMapsLink(input: string): MapsParseResult {
    let text = input.trim();
    try {
        text = decodeURIComponent(text);
    } catch {
        // Bukan URL ter-encode: pakai apa adanya.
    }

    if (/\b(maps\.app\.goo\.gl|goo\.gl\/maps)\//i.test(text)) {
        return { ok: false, reason: 'short-link' };
    }

    for (const pattern of PATTERNS) {
        const match = text.match(pattern);
        if (!match) {
            continue;
        }

        const latitude = Number(match[1]);
        const longitude = Number(match[2]);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            continue;
        }

        if (!inIndonesia(latitude, longitude)) {
            return { ok: false, reason: 'outside-indonesia' };
        }

        return {
            ok: true,
            latitude: round7(match[1]),
            longitude: round7(match[2]),
        };
    }

    return { ok: false, reason: 'not-found' };
}

/**
 * Tautan biasa ke Google Maps (bukan Maps Platform/API): gratis, tanpa kunci, dibuka di tab baru.
 */
export function googleMapsUrl(latitude: string, longitude: string): string {
    return `https://www.google.com/maps?q=${encodeURIComponent(`${latitude},${longitude}`)}`;
}

export const MAPS_PARSE_MESSAGES: Record<
    Exclude<MapsParseResult, { ok: true }>['reason'],
    string
> = {
    'short-link':
        'Tautan pendek tidak bisa dibaca. Buka tautannya dulu, lalu salin alamat lengkap dari address bar (yang memuat angka koordinat).',
    'not-found':
        'Koordinat tidak ditemukan di tautan ini. Di Google Maps, klik kanan titik lokasi lalu salin angka koordinatnya.',
    'outside-indonesia': 'Koordinat di tautan ini berada di luar Indonesia.',
};

/**
 * Titik dari Geolocation API browser (tombol "Ambil lokasi HP", LocationPointInput T-073): dibulatkan
 * ke 7 desimal dan dibatasi wilayah Indonesia, sama dengan tautan yang ditempel. Akurasi (meter)
 * ikut dikembalikan karena verifikasi kios (BR-165, T-074) menolak titik yang terlalu kabur.
 */
export function pointFromCoordinates(
    latitude: number,
    longitude: number,
): MapsParseResult {
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return { ok: false, reason: 'not-found' };
    }
    if (!inIndonesia(latitude, longitude)) {
        return { ok: false, reason: 'outside-indonesia' };
    }

    return {
        ok: true,
        latitude: round7(String(latitude)),
        longitude: round7(String(longitude)),
    };
}

/** Kode galat `GeolocationPositionError` (1 izin ditolak, 2 tidak tersedia, 3 waktu habis). */
export function geolocationErrorMessage(code: number): string {
    switch (code) {
        case 1:
            return 'Izin lokasi ditolak. Izinkan akses lokasi untuk situs ini di pengaturan browser, lalu coba lagi.';
        case 2:
            return 'Lokasi HP tidak tersedia. Nyalakan GPS/lokasi lalu coba lagi.';
        case 3:
            return 'Lokasi HP belum didapat. Coba lagi di tempat terbuka.';
        default:
            return 'Lokasi HP tidak bisa diambil. Coba lagi atau tempel tautan Google Maps.';
    }
}

/** Akurasi GPS untuk tampilan: "± 12 m" / "± 1,2 km". */
export function formatAccuracy(meters: number): string {
    return meters >= 1000
        ? `± ${(meters / 1000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} km`
        : `± ${Math.round(meters).toLocaleString('id-ID')} m`;
}
