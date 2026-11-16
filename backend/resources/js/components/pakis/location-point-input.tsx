import { Crosshair, MapPin, X } from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { LocationDiagram } from '@/components/pakis/location-diagram';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    formatAccuracy,
    geolocationErrorMessage,
    googleMapsUrl,
    inIndonesia,
    MAPS_PARSE_MESSAGES,
    parseMapsLink,
    pointFromCoordinates,
} from '@/lib/maps-link';

export type LocationPointValue = {
    latitude: string;
    longitude: string;
    /** Akurasi GPS (meter) bila titik dari "Ambil lokasi HP"; null bila diketik/ditempel. */
    accuracyM: number | null;
};

type Props = {
    latitude: string;
    longitude: string;
    onChange: (value: LocationPointValue) => void;
    errors?: { latitude?: string; longitude?: string };
    /** Awalan id isian agar dua input di satu halaman tidak bentrok. */
    idPrefix?: string;
    /** Nama titik di diagram, mis. "Titik kios". */
    label?: string;
};

/**
 * Batas tunggu GPS (teknis perangkat, bukan parameter bisnis): lebih lama dari ini pengguna lebih
 * baik menempel tautan Google Maps daripada menunggu tanpa kabar.
 */
const GEOLOCATION_TIMEOUT_MS = 20_000;

function geolocationAvailable(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        // Dibaca sebagai nilai, bukan `in`: sebagian browser/lingkungan uji memasang properti kosong.
        Boolean((navigator as { geolocation?: Geolocation }).geolocation) &&
        // Browser hanya mengizinkan Geolocation di HTTPS/localhost.
        (typeof window === 'undefined' || window.isSecureContext !== false)
    );
}

/**
 * Input titik lokasi tanpa pustaka peta & tanpa tile (15 §7.2 `LocationPointInput`, PA-20; CSP
 * tidak berubah). Tiga cara mengisi: tempel tautan Google Maps (pola T-021), tombol "Ambil lokasi
 * HP" (Geolocation API, `Permissions-Policy: geolocation=(self)`), atau ketik angka. Titik tampil
 * di `LocationDiagram`; koordinat hanya keluar ke Google bila pengguna sendiri membuka tautannya.
 */
export function LocationPointInput({
    latitude,
    longitude,
    onChange,
    errors = {},
    idPrefix = 'point',
    label = 'Titik lokasi',
}: Props) {
    const helpId = useId();
    const [mapsLink, setMapsLink] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [locating, setLocating] = useState(false);
    const [accuracy, setAccuracy] = useState<number | null>(null);
    const canLocate = geolocationAvailable();

    const lat = Number(latitude);
    const lng = Number(longitude);
    const hasPoint =
        latitude.trim() !== '' &&
        longitude.trim() !== '' &&
        Number.isFinite(lat) &&
        Number.isFinite(lng) &&
        inIndonesia(lat, lng);

    const emit = (value: LocationPointValue) => {
        setAccuracy(value.accuracyM);
        onChange(value);
    };

    const applyMapsLink = () => {
        const result = parseMapsLink(mapsLink);
        if (!result.ok) {
            setMessage(MAPS_PARSE_MESSAGES[result.reason]);

            return;
        }
        emit({
            latitude: result.latitude,
            longitude: result.longitude,
            accuracyM: null,
        });
        setMessage(null);
        setMapsLink('');
    };

    const locate = () => {
        if (!canLocate) {
            return;
        }
        setLocating(true);
        setMessage(null);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setLocating(false);
                const result = pointFromCoordinates(
                    position.coords.latitude,
                    position.coords.longitude,
                );
                if (!result.ok) {
                    setMessage(MAPS_PARSE_MESSAGES[result.reason]);

                    return;
                }
                emit({
                    latitude: result.latitude,
                    longitude: result.longitude,
                    accuracyM: Number.isFinite(position.coords.accuracy)
                        ? Math.round(position.coords.accuracy)
                        : null,
                });
            },
            (error) => {
                setLocating(false);
                setMessage(geolocationErrorMessage(error.code));
            },
            // Titik kios harus titik saat ini, bukan cache lama dari tempat lain.
            {
                enableHighAccuracy: true,
                timeout: GEOLOCATION_TIMEOUT_MS,
                maximumAge: 0,
            },
        );
    };

    const typed = (key: 'latitude' | 'longitude', value: string) =>
        emit({
            latitude: key === 'latitude' ? value : latitude,
            longitude: key === 'longitude' ? value : longitude,
            accuracyM: null,
        });

    return (
        <div className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}-maps-link`}>
                    Tempel tautan Google Maps
                </Label>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Input
                        id={`${idPrefix}-maps-link`}
                        value={mapsLink}
                        placeholder="https://www.google.com/maps/place/…"
                        onChange={(e) => setMapsLink(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                applyMapsLink();
                            }
                        }}
                        aria-describedby={helpId}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={mapsLink.trim() === ''}
                        onClick={applyMapsLink}
                    >
                        <MapPin aria-hidden="true" />
                        Ambil koordinat
                    </Button>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs text-muted-foreground">atau</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canLocate || locating}
                        onClick={locate}
                    >
                        {locating ? (
                            <Spinner />
                        ) : (
                            <Crosshair aria-hidden="true" />
                        )}
                        {locating ? 'Mengambil lokasi…' : 'Ambil lokasi HP'}
                    </Button>
                    {!canLocate && (
                        <span className="text-xs text-muted-foreground">
                            Browser ini tidak memberi akses lokasi.
                        </span>
                    )}
                </div>
                <p
                    id={helpId}
                    className={
                        message
                            ? 'text-xs text-destructive'
                            : 'text-xs text-muted-foreground'
                    }
                    role={message ? 'alert' : undefined}
                >
                    {message ??
                        'Salin alamat lengkap dari address bar Google Maps, atau tekan "Ambil lokasi HP" saat berada di lokasi. Bisa juga menempel angka "-6.85, 110.84".'}
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor={`${idPrefix}-latitude`}>Lintang</Label>
                    <Input
                        id={`${idPrefix}-latitude`}
                        inputMode="decimal"
                        value={latitude}
                        placeholder="-6.8512345"
                        className="tabular-nums"
                        onChange={(e) => typed('latitude', e.target.value)}
                        aria-invalid={errors.latitude ? true : undefined}
                    />
                    <InputError message={errors.latitude} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={`${idPrefix}-longitude`}>Bujur</Label>
                    <Input
                        id={`${idPrefix}-longitude`}
                        inputMode="decimal"
                        value={longitude}
                        placeholder="110.8423456"
                        className="tabular-nums"
                        onChange={(e) => typed('longitude', e.target.value)}
                        aria-invalid={errors.longitude ? true : undefined}
                    />
                    <InputError message={errors.longitude} />
                </div>
            </div>

            {hasPoint ? (
                <div className="space-y-2">
                    {accuracy !== null && (
                        <p className="text-center text-xs text-muted-foreground">
                            Akurasi lokasi HP {formatAccuracy(accuracy)}
                        </p>
                    )}
                    <LocationDiagram
                        point={{ lat, lng }}
                        area={null}
                        label={label}
                        noAreaText={`${label}: ${latitude}, ${longitude}.`}
                        mapHref={googleMapsUrl(latitude, longitude)}
                        mapLabel="Lihat di Google Maps"
                    />
                    <div className="flex justify-center">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                emit({
                                    latitude: '',
                                    longitude: '',
                                    accuracyM: null,
                                })
                            }
                        >
                            <X aria-hidden="true" />
                            Hapus titik
                        </Button>
                    </div>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Belum ada titik. Titik bisa diisi nanti saat verifikasi
                    kios.
                </p>
            )}
        </div>
    );
}
