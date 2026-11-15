import { useHttp } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Nilai sensitif termasking (07 §2 `MaskedValue`), mis. NIK `3319********0001` (SEC-010).
 *
 * Nilai lengkap hanya diambil saat tombol "Lihat" ditekan (setiap pembukaan tercatat di server
 * sebagai `sensitive_data_viewed`) dan hanya hidup di state komponen ini: tidak disimpan di URL,
 * sesi, atau storage, dan hilang saat halaman ditinggalkan atau tombol "Sembunyikan" ditekan.
 */
export function MaskedValue({
    masked,
    reveal,
    label = 'NIK',
}: {
    /** Nilai termasking dari server, null = tidak diisi. */
    masked: string | null;
    /** Endpoint POST pembuka nilai lengkap; null = pengguna tidak berhak. */
    reveal: RouteDefinition<'post'> | null;
    label?: string;
}) {
    const [full, setFull] = useState<string | null>(null);
    const http = useHttp<Record<string, never>, { nik: string | null }>({});

    if (masked === null) {
        return <span className="text-muted-foreground">–</span>;
    }

    const open = () => {
        if (!reveal) {
            return;
        }
        http.submit(reveal, {
            onSuccess: (res) => setFull(res.nik),
            onHttpException: () => {
                toast.error(
                    `${label} tidak bisa dibuka. Muat ulang halaman lalu coba lagi.`,
                );
            },
            onNetworkError: () => {
                toast.error('Koneksi terputus. Coba lagi.');
            },
        }).catch(() => {
            // Ditangani callback di atas.
        });
    };

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <span className="font-mono tabular-nums">{full ?? masked}</span>
            {reveal &&
                (full === null ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7"
                        disabled={http.processing}
                        onClick={open}
                        aria-label={`Lihat ${label} lengkap`}
                    >
                        {http.processing ? (
                            <Spinner />
                        ) : (
                            <Eye aria-hidden="true" />
                        )}
                        Lihat
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7"
                        onClick={() => setFull(null)}
                        aria-label={`Sembunyikan ${label}`}
                    >
                        <EyeOff aria-hidden="true" />
                        Sembunyikan
                    </Button>
                ))}
        </span>
    );
}
