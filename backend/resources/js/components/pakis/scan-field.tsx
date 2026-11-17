import { Camera, ScanBarcode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Bunyi & getar singkat (15 §7.1 "Pemindai"). Gagal diam-diam bila browser menolak (mis. belum ada
 * interaksi pengguna): umpan balik ini tambahan, bukan satu-satunya tanda.
 */
export function scanFeedback(ok: boolean): void {
    try {
        navigator.vibrate?.(ok ? 40 : [60, 40, 60]);
    } catch {
        // tidak didukung
    }
    try {
        const AudioCtx =
            window.AudioContext ??
            (window as unknown as { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;
        if (!AudioCtx) {
            return;
        }
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = ok ? 1200 : 300;
        gain.gain.value = 0.08;
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + (ok ? 0.08 : 0.25));
        osc.onended = () => void ctx.close();
    } catch {
        // tidak didukung
    }
}

type BarcodeDetectorLike = {
    detect: (source: ImageBitmap) => Promise<{ rawValue: string }[]>;
};

function barcodeDetector(): BarcodeDetectorLike | null {
    const Ctor = (
        window as unknown as {
            BarcodeDetector?: new () => BarcodeDetectorLike;
        }
    ).BarcodeDetector;

    return Ctor ? new Ctor() : null;
}

/** Hasil pindai dirapikan: spasi tepi & karakter kontrol (CR/LF/TAB dari scanner) dibuang. */
export function cleanScan(raw: string): string {
    // eslint-disable-next-line no-control-regex
    return raw.replace(/[\u0000-\u001f\u007f]/g, '').trim();
}

/**
 * Isian pemindai (15 §7.1, T-080): menerima scanner HID (keyboard wedge — mengetik kode lalu Enter),
 * ketikan manual + Enter, atau "Foto barcode" (input `capture`, dibaca `BarcodeDetector` bila browser
 * mendukung). Tanpa kamera live/getUserMedia, jadi `Permissions-Policy: camera=()` tidak berubah
 * (PA-65). `onScan` mengembalikan `true` bila kode diterima (bunyi berhasil) atau `false`/pesan galat.
 */
export function ScanField({
    id,
    label,
    onScan,
    placeholder = 'Pindai atau ketik lalu tekan Enter',
    autoFocus = false,
    hint,
    error,
    disabled = false,
}: {
    id: string;
    label: string;
    onScan: (code: string) => boolean | string;
    placeholder?: string;
    autoFocus?: boolean;
    hint?: string;
    error?: string;
    disabled?: boolean;
}) {
    const [value, setValue] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const file = useRef<HTMLInputElement>(null);
    const [canDetect, setCanDetect] = useState(false);

    useEffect(() => {
        setCanDetect(barcodeDetector() !== null);
    }, []);

    const submit = (raw: string) => {
        const code = cleanScan(raw);
        if (code === '') {
            return;
        }
        const result = onScan(code);
        const ok = result === true;
        scanFeedback(ok);
        setMessage(ok ? null : result === false ? 'Kode ditolak.' : result);
        if (ok) {
            setValue('');
        }
        input.current?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            // Enter dari scanner jangan mengirim form induk.
            event.preventDefault();
            submit(value);
        }
    };

    const fromPhoto = async (picked: File | undefined) => {
        const detector = barcodeDetector();
        if (!picked || !detector) {
            return;
        }
        setBusy(true);
        try {
            const bitmap = await createImageBitmap(picked);
            const codes = await detector.detect(bitmap);
            bitmap.close();
            if (codes.length === 0) {
                scanFeedback(false);
                setMessage(
                    'Barcode tidak terbaca. Dekatkan kamera atau ketik manual.',
                );
            } else {
                submit(codes[0].rawValue);
            }
        } catch {
            scanFeedback(false);
            setMessage('Foto tidak bisa dibaca. Ketik kodenya manual.');
        } finally {
            setBusy(false);
            if (file.current) {
                file.current.value = '';
            }
        }
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex gap-2">
                <div className="relative flex-1">
                    <ScanBarcode
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        ref={input}
                        id={id}
                        value={value}
                        autoFocus={autoFocus}
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        enterKeyHint="done"
                        disabled={disabled}
                        placeholder={placeholder}
                        className="pl-9 font-mono"
                        onChange={(e) => {
                            setValue(e.target.value);
                            setMessage(null);
                        }}
                        onKeyDown={onKeyDown}
                    />
                </div>
                {canDetect && (
                    <>
                        <input
                            ref={file}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            className="sr-only"
                            tabIndex={-1}
                            aria-hidden="true"
                            onChange={(e) =>
                                void fromPhoto(e.target.files?.[0])
                            }
                        />
                        <Button
                            type="button"
                            variant="outline"
                            disabled={disabled || busy}
                            onClick={() => file.current?.click()}
                            aria-label="Foto barcode"
                        >
                            <Camera aria-hidden="true" />
                            <span className="hidden sm:inline">
                                Foto barcode
                            </span>
                        </Button>
                    </>
                )}
            </div>
            {hint && !message && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            <InputError message={message ?? error} />
        </div>
    );
}
