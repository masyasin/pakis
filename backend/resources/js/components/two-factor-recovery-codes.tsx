import { Form } from '@inertiajs/react';
import { LockKeyhole, RefreshCw, TriangleAlert } from 'lucide-react';
import { useEffect, useRef } from 'react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

type Props = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
    /** Server mengizinkan kode dilihat SEKALI (tepat setelah dibuat). */
    viewable: boolean;
    /** Jumlah kode yang belum dipakai. */
    remaining?: number;
};

/**
 * Kode pemulihan MFA (BR-015): tampil SEKALI tepat setelah dibuat (aktivasi / buat ulang).
 * Sesudahnya hanya jumlah sisa kode yang terlihat; kode yang hilang diganti dengan "Buat ulang".
 */
export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
    viewable,
    remaining,
}: Props) {
    const fetched = useRef(false);

    useEffect(() => {
        // Satu kali saja: server hanya melayani permintaan pertama setelah kode dibuat.
        if (viewable && !fetched.current && recoveryCodesList.length === 0) {
            fetched.current = true;
            void fetchRecoveryCodes();
        }
    }, [viewable, recoveryCodesList.length, fetchRecoveryCodes]);

    const showing = recoveryCodesList.length > 0;

    return (
        // Berada di dalam kartu Pengaturan, jadi tanpa bayangan agar tidak bertumpuk.
        <Card className="w-full gap-4 bg-muted/50 py-5 shadow-none">
            <CardHeader>
                <CardTitle className="flex gap-3">
                    <LockKeyhole className="size-4" aria-hidden="true" />
                    Kode pemulihan
                </CardTitle>
                <CardDescription>
                    Dipakai untuk masuk bila HP autentikator hilang. Setiap kode
                    hanya bisa dipakai sekali.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {errors.length > 0 && <AlertError errors={errors} />}

                {showing ? (
                    <>
                        <div
                            role="alert"
                            className="flex items-start gap-2.5 rounded-lg bg-status-warning-bg px-3.5 py-3 text-sm text-status-warning"
                        >
                            <TriangleAlert
                                className="mt-px size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                Simpan sekarang (pengelola password atau kertas
                                di tempat aman).{' '}
                                <strong>
                                    Kode ini tidak bisa dilihat lagi
                                </strong>{' '}
                                setelah halaman ditutup.
                            </span>
                        </div>
                        <div
                            className="grid gap-x-6 gap-y-1 rounded-lg bg-card p-4 font-mono text-sm break-all select-text sm:grid-cols-2"
                            role="list"
                            aria-label="Kode pemulihan"
                        >
                            {recoveryCodesList.map((code) => (
                                <div key={code} role="listitem">
                                    {code}
                                </div>
                            ))}
                        </div>
                    </>
                ) : (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-muted-foreground">
                            {remaining !== undefined && (
                                <>
                                    <strong className="text-foreground tabular-nums">
                                        {remaining} kode
                                    </strong>{' '}
                                    tersisa.{' '}
                                </>
                            )}
                            Kode hanya ditampilkan sekali saat dibuat. Bila
                            hilang atau hampir habis, buat kode baru (kode lama
                            tidak berlaku lagi).
                        </p>
                        <Form
                            {...regenerateRecoveryCodes.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => {
                                fetched.current = true;
                                void fetchRecoveryCodes();
                            }}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="secondary"
                                    type="submit"
                                    disabled={processing}
                                    className="shrink-0"
                                >
                                    <RefreshCw aria-hidden="true" />
                                    Buat kode baru
                                </Button>
                            )}
                        </Form>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
