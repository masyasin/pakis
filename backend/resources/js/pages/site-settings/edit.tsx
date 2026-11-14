import { Head, Link, router, usePage } from '@inertiajs/react';
import { Lock, Mail } from 'lucide-react';
import { useState } from 'react';
import { SettingField } from '@/components/pakis/setting-field';
import type {
    SettingFieldDef,
    SettingValue,
} from '@/components/pakis/setting-field';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Props = {
    groups: Array<{ key: string; label: string }>;
    group: string;
    sensitive: boolean;
    fields: SettingFieldDef[];
    canUpdate: boolean;
};

/**
 * Halaman Pengaturan (07 §4): tab per grup registri, form otomatis, simpan per tab.
 */
export default function SiteSettingsEdit({
    groups,
    group,
    sensitive,
    fields,
    canUpdate,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const initial = Object.fromEntries(fields.map((f) => [f.field, f.value]));
    const [values, setValues] = useState<Record<string, SettingValue>>(initial);
    const [files, setFiles] = useState<Record<string, File>>({});
    const [processing, setProcessing] = useState(false);

    const url = `/pengaturan/${group}`;
    const groupLabel = groups.find((g) => g.key === group)?.label ?? group;

    const submit = (reset: string[] = []) => {
        const payload: Record<string, unknown> = {
            _method: 'put',
            // Gambar hanya dikirim sebagai berkas; nilai teksnya (path) tidak boleh diubah dari form.
            values: Object.fromEntries(
                fields
                    .filter(
                        (f) => f.type !== 'image' && !reset.includes(f.field),
                    )
                    .map((f) => [f.field, values[f.field]]),
            ),
            reset,
            ...files,
        };

        router.post(url, payload as never, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setFiles({}),
        });
    };

    return (
        <>
            <Head title={`Pengaturan · ${groupLabel}`} />
            <div className="mx-auto grid w-full max-w-[1440px] gap-6 p-4 lg:grid-cols-[220px_1fr]">
                <nav
                    aria-label="Grup pengaturan"
                    className="flex gap-1 overflow-x-auto lg:flex-col"
                >
                    {groups.map((g) => (
                        <Link
                            key={g.key}
                            href={`/pengaturan/${g.key}`}
                            preserveScroll
                            className={cn(
                                'rounded-md px-3 py-2 text-sm whitespace-nowrap hover:bg-muted',
                                g.key === group && 'bg-muted font-medium',
                            )}
                            aria-current={g.key === group ? 'page' : undefined}
                        >
                            {g.label}
                        </Link>
                    ))}
                </nav>

                <section className="min-w-0 space-y-4">
                    <div>
                        <h1 className="text-xl font-semibold">{groupLabel}</h1>
                        <p className="text-sm text-muted-foreground">
                            Perubahan berlaku tanpa deploy dan tercatat di audit
                            log.
                        </p>
                        {sensitive && (
                            <p className="mt-2 inline-flex items-center gap-1.5 rounded-md bg-status-warning-bg px-2 py-1 text-xs text-status-warning">
                                <Lock className="size-3.5" aria-hidden />
                                Grup keamanan: perubahan dilindungi konfirmasi
                                password.
                            </p>
                        )}
                    </div>

                    <form
                        className="divide-y rounded-xl border bg-card px-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                    >
                        {fields.map((def) => (
                            <SettingField
                                key={def.field}
                                def={def}
                                value={values[def.field]}
                                error={errors[def.field]}
                                disabled={!canUpdate || processing}
                                onChange={(value) =>
                                    setValues((v) => ({
                                        ...v,
                                        [def.field]: value,
                                    }))
                                }
                                onFile={(file) =>
                                    setFiles((current) => {
                                        const next = { ...current };
                                        if (file) {
                                            next[def.field] = file;
                                        } else {
                                            delete next[def.field];
                                        }

                                        return next;
                                    })
                                }
                                onReset={() => submit([def.field])}
                            />
                        ))}

                        {canUpdate && (
                            <div className="flex flex-wrap items-center justify-between gap-3 py-4">
                                {group === 'notification' ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                        onClick={() =>
                                            router.post(
                                                '/pengaturan/uji-email',
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Mail className="size-4" aria-hidden />
                                        Kirim email uji
                                    </Button>
                                ) : (
                                    <span />
                                )}
                                <Button type="submit" disabled={processing}>
                                    Simpan perubahan
                                </Button>
                            </div>
                        )}
                    </form>
                </section>
            </div>
        </>
    );
}

SiteSettingsEdit.layout = {
    breadcrumbs: [{ title: 'Pengaturan', href: '/pengaturan' }],
};
