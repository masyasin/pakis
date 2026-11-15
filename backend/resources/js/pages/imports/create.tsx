import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    FileSpreadsheet,
    UploadCloud,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent } from 'react';
import { ImportSteps } from '@/components/import/import-steps';
import type { ImportField } from '@/components/import/import-steps';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index, store, template } from '@/routes/admin/imports';

type Props = {
    limits: { max_file_mb: number; max_rows: number };
    fields: ImportField[];
};

const ACCEPT = '.xlsx,.csv';

function sizeLabel(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Impor pegawai langkah 1: unggah berkas (07 §4, BR-054, SEC-050). */
export default function ImportCreate({ limits, fields }: Props) {
    const form = useForm<{ file: File | null }>({ file: null });
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const pick = (file: File | undefined) => {
        if (file) {
            form.setData('file', file);
            form.clearErrors('file');
        }
    };
    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        setDragging(false);
        pick(e.dataTransfer.files[0]);
    };
    const submit = () =>
        form.post(store().url, { forceFormData: true, preserveScroll: true });

    const required = fields.filter((f) => f.required);
    const optional = fields.filter((f) => !f.required);

    return (
        <>
            <Head title="Impor Pegawai" />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <Link
                    href={index()}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Riwayat impor
                </Link>
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Impor Pegawai
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Unggah daftar pegawai dari Excel atau CSV. Kolom boleh
                        berurutan bebas dan judul tabel boleh ada di atasnya.
                    </p>
                </div>

                <ImportSteps current={1} />

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <section className="space-y-4 rounded-xl border bg-card p-4 shadow-[0_1px_2px_rgb(0_0_0/0.04),0_4px_12px_-6px_rgb(0_0_0/0.08)] sm:p-6">
                        <div
                            role="button"
                            tabIndex={0}
                            onClick={() => input.current?.click()}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    input.current?.click();
                                }
                            }}
                            onDragOver={(e) => {
                                e.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={onDrop}
                            aria-describedby="file-hint"
                            className={cn(
                                'grid cursor-pointer place-items-center gap-3 rounded-xl border-2 border-dashed px-4 py-10 text-center transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                dragging
                                    ? 'border-primary bg-primary/5'
                                    : 'border-border hover:border-primary/60 hover:bg-muted/40',
                                form.errors.file && 'border-destructive/60',
                            )}
                        >
                            <span className="grid size-12 place-items-center rounded-full bg-sidebar-accent text-primary">
                                <UploadCloud
                                    className="size-6"
                                    aria-hidden="true"
                                />
                            </span>
                            <span className="space-y-1">
                                <span className="block font-semibold">
                                    Tarik berkas ke sini atau klik untuk memilih
                                </span>
                                <span
                                    id="file-hint"
                                    className="block text-sm text-muted-foreground"
                                >
                                    .xlsx atau .csv · maks. {limits.max_file_mb}{' '}
                                    MB · maks.{' '}
                                    {limits.max_rows.toLocaleString('id-ID')}{' '}
                                    baris
                                </span>
                            </span>
                            <input
                                ref={input}
                                type="file"
                                accept={ACCEPT}
                                className="sr-only"
                                aria-label="Pilih berkas impor"
                                onChange={(e) => pick(e.target.files?.[0])}
                            />
                        </div>

                        {form.data.file && (
                            <div className="flex items-center gap-3 rounded-lg border bg-muted/40 px-3 py-2.5">
                                <FileSpreadsheet
                                    className="size-5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {form.data.file.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {sizeLabel(form.data.file.size)}
                                    </p>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Hapus pilihan berkas"
                                    onClick={() => {
                                        form.setData('file', null);
                                        if (input.current) {
                                            input.current.value = '';
                                        }
                                    }}
                                >
                                    <X aria-hidden="true" />
                                </Button>
                            </div>
                        )}
                        <InputError message={form.errors.file} />

                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button variant="outline" asChild>
                                <Link href={index()}>Batal</Link>
                            </Button>
                            <Button
                                onClick={submit}
                                disabled={!form.data.file || form.processing}
                            >
                                {form.processing && <Spinner />}
                                Unggah & lanjut
                            </Button>
                        </div>
                    </section>

                    <aside className="space-y-4 rounded-xl border bg-card p-4 shadow-[0_1px_2px_rgb(0_0_0/0.04),0_4px_12px_-6px_rgb(0_0_0/0.08)] sm:p-5">
                        <div className="space-y-2">
                            <h2 className="text-sm font-semibold">
                                Belum punya berkas?
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Unduh templat berisi kolom standar, contoh, dan
                                daftar kode lokasi/jabatan yang berlaku.
                            </p>
                            <Button
                                variant="outline"
                                className="w-full"
                                asChild
                            >
                                <a href={template().url}>
                                    <Download aria-hidden="true" />
                                    Unduh templat (.xlsx)
                                </a>
                            </Button>
                        </div>
                        <div className="space-y-2 border-t pt-4">
                            <h2 className="text-sm font-semibold">
                                Kolom wajib
                            </h2>
                            <ul className="flex flex-wrap gap-1.5">
                                {required.map((f) => (
                                    <li
                                        key={f.key}
                                        className="rounded-md bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                    >
                                        {f.label}
                                    </li>
                                ))}
                            </ul>
                            <h2 className="pt-1 text-sm font-semibold">
                                Opsional
                            </h2>
                            <ul className="flex flex-wrap gap-1.5">
                                {optional.map((f) => (
                                    <li
                                        key={f.key}
                                        className="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                    >
                                        {f.label}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <p className="border-t pt-4 text-xs text-muted-foreground">
                            Ketik NIK sebagai teks (bukan angka): Excel
                            membulatkan angka 16 digit sehingga NIK berubah.
                        </p>
                    </aside>
                </div>
            </div>
        </>
    );
}

ImportCreate.layout = {
    breadcrumbs: [
        { title: 'Impor Pegawai', href: index() },
        { title: 'Unggah', href: '#' },
    ],
};
