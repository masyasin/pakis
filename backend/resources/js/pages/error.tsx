import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    status: 403 | 404 | 500 | 503;
    requestId: string;
};

// Teks sengaja generik: tidak pernah menampilkan detail teknis (SEC-061).
const COPY: Record<Props['status'], { title: string; description: string }> = {
    403: {
        title: 'Akses ditolak',
        description:
            'Anda tidak punya akses ke halaman ini. Hubungi admin bila Anda merasa seharusnya bisa.',
    },
    404: {
        title: 'Halaman tidak ditemukan',
        description: 'Halaman yang Anda cari tidak ada atau sudah dipindahkan.',
    },
    500: {
        title: 'Terjadi kesalahan',
        description:
            'Ada masalah di server. Coba lagi sebentar lagi. Bila masih terjadi, kirim kode di bawah ke admin.',
    },
    503: {
        title: 'Sedang dalam perbaikan',
        description:
            'Sistem sedang diperbarui. Silakan coba lagi beberapa saat lagi.',
    },
};

export default function ErrorPage({ status, requestId }: Props) {
    const copy = COPY[status] ?? COPY[500];

    return (
        <>
            <Head title={copy.title} />

            <main className="flex min-h-svh items-center justify-center bg-background p-6">
                <div className="w-full max-w-md space-y-6 text-center">
                    <p className="text-sm font-medium text-muted-foreground">
                        {status}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        {copy.title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {copy.description}
                    </p>

                    <Button asChild>
                        <Link href="/">Kembali ke beranda</Link>
                    </Button>

                    <p className="text-xs text-muted-foreground">
                        Kode permintaan:{' '}
                        <code className="font-mono select-all">
                            {requestId}
                        </code>
                    </p>
                </div>
            </main>
        </>
    );
}
