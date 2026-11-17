import { Camera, RotateCcw, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/**
 * Ukuran & mutu hasil kompres dari spesifikasi komponen (15 §7.2 `PhotoCapture`: ≤ 1600 px, JPEG
 * 80%). Ini batas teknis unggahan, bukan parameter bisnis: server tetap memeriksa jenis & ukuran
 * dari isi berkas (SEC-109).
 */
export const PHOTO_MAX_SIDE = 1600;
export const PHOTO_JPEG_QUALITY = 0.8;

/**
 * Ukuran baru agar sisi terpanjang ≤ `maxSide` dengan rasio tetap. Foto yang sudah kecil tidak
 * diperbesar. Dibulatkan ke piksel bulat, minimal 1.
 */
export function fitWithin(
    width: number,
    height: number,
    maxSide: number = PHOTO_MAX_SIDE,
): { width: number; height: number } {
    if (width <= 0 || height <= 0) {
        return { width: 0, height: 0 };
    }
    const longest = Math.max(width, height);
    if (longest <= maxSide) {
        return { width: Math.round(width), height: Math.round(height) };
    }
    const scale = maxSide / longest;

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

type Decoded = {
    source: CanvasImageSource;
    width: number;
    height: number;
    release: () => void;
};

async function decode(file: File): Promise<Decoded> {
    // `createImageBitmap` menerapkan orientasi EXIF (foto HP tegak tidak jadi miring).
    if (typeof createImageBitmap === 'function') {
        const bitmap = await createImageBitmap(file);

        return {
            source: bitmap,
            width: bitmap.width,
            height: bitmap.height,
            release: () => bitmap.close(),
        };
    }

    const url = URL.createObjectURL(file);
    const image = new Image();
    await new Promise<void>((resolve, reject) => {
        image.onload = () => resolve();
        image.onerror = () => reject(new Error('decode'));
        image.src = url;
    });

    return {
        source: image,
        width: image.naturalWidth,
        height: image.naturalHeight,
        release: () => URL.revokeObjectURL(url),
    };
}

/**
 * Foto → JPEG terkompres. Digambar ulang lewat canvas, sehingga metadata EXIF (termasuk GPS kamera)
 * ikut terbuang: titik toko dikirim lewat isian lokasi, bukan tertanam di foto.
 */
export async function compressPhoto(file: File): Promise<File> {
    const decoded = await decode(file);
    try {
        const size = fitWithin(decoded.width, decoded.height);
        const canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;
        const context = canvas.getContext('2d');
        if (!context || size.width === 0) {
            throw new Error('canvas');
        }
        context.drawImage(decoded.source, 0, 0, size.width, size.height);
        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', PHOTO_JPEG_QUALITY),
        );
        if (!blob) {
            throw new Error('encode');
        }
        const base = file.name.replace(/\.[^.]+$/, '') || 'foto';

        return new File([blob], `${base}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    } finally {
        decoded.release();
    }
}

type Props = {
    id: string;
    label: string;
    value: File | null;
    onChange: (file: File | null) => void;
    error?: string;
    hint?: string;
    disabled?: boolean;
};

/**
 * Ambil foto dari kamera belakang HP (15 §7.2 `PhotoCapture`, T-074). Hanya
 * `<input type="file" capture="environment">`, tanpa `getUserMedia`, jadi `Permissions-Policy:
 * camera=()` tidak perlu diubah. Di HP langsung membuka kamera; di komputer browser membuka pemilih
 * berkas (wajar untuk foto toko — larangan galeri hanya untuk selfie presensi). Foto dikompres di
 * klien sebelum dikirim agar unggahan dari sinyal lemah tetap cepat.
 */
export function PhotoCapture({
    id,
    label,
    value,
    onChange,
    error,
    hint,
    disabled,
}: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!value) {
            setPreview(null);

            return;
        }
        const url = URL.createObjectURL(value);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [value]);

    const pick = async (file: File | undefined) => {
        if (!file) {
            return;
        }
        setBusy(true);
        setMessage(null);
        try {
            onChange(await compressPhoto(file));
        } catch {
            setMessage(
                'Foto tidak bisa dibaca. Ambil ulang dengan kamera HP (format JPG/PNG).',
            );
        } finally {
            setBusy(false);
        }
    };

    const open = () => inputRef.current?.click();
    const describedBy = `${id}-hint`;

    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            <input
                ref={inputRef}
                id={id}
                type="file"
                accept="image/*"
                capture="environment"
                className="sr-only"
                disabled={disabled || busy}
                aria-describedby={describedBy}
                aria-invalid={error || message ? true : undefined}
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    // Kosongkan agar memilih foto yang sama lagi tetap memicu onChange.
                    e.target.value = '';
                    void pick(file);
                }}
            />
            {preview ? (
                <div className="space-y-2">
                    <img
                        src={preview}
                        alt="Pratinjau foto toko"
                        className="max-h-72 w-full rounded-lg border bg-muted object-contain"
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={disabled || busy}
                            onClick={open}
                        >
                            {busy ? (
                                <Spinner />
                            ) : (
                                <RotateCcw aria-hidden="true" />
                            )}
                            Ambil ulang
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={disabled || busy}
                            onClick={() => onChange(null)}
                        >
                            <X aria-hidden="true" />
                            Hapus foto
                        </Button>
                    </div>
                </div>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    className="h-24 w-full border-dashed"
                    disabled={disabled || busy}
                    onClick={open}
                >
                    {busy ? <Spinner /> : <Camera aria-hidden="true" />}
                    {busy ? 'Memproses foto…' : 'Ambil foto'}
                </Button>
            )}
            <p
                id={describedBy}
                className={
                    message
                        ? 'text-xs text-destructive'
                        : 'text-xs text-muted-foreground'
                }
                role={message ? 'alert' : undefined}
            >
                {message ?? hint}
            </p>
            <InputError message={error} />
        </div>
    );
}
