import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CopyPlus, Layers, ListOrdered, Send } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DetailCard, EmptyPanel } from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import { StatusBadge } from '@/components/pakis/status-badge';
import {
    SubmitVersionDialog,
    VersionBadge,
    VersionPeriod,
} from '@/components/pricing/version-ui';
import type { PriceVersion } from '@/components/pricing/version-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PriceListRow } from './index';

type Props = {
    list: PriceListRow;
    currentVersionId: number | null;
    versions: PriceVersion[];
    nowLocal: string;
    can: { manage: boolean; publish: boolean; backdate: boolean };
};

/** Detail daftar harga (15 §5): versi berlaku, riwayat versi, buat versi baru, ajukan/terbitkan. */
export default function PriceListShow({
    list,
    currentVersionId,
    versions,
    nowLocal,
    can,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [submitting, setSubmitting] = useState<PriceVersion | null>(null);
    const current = versions.find((v) => v.id === currentVersionId) ?? null;

    return (
        <>
            <Head title={list.name} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <Link
                    href="/daftar-harga"
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Daftar harga
                </Link>

                <header className="flex flex-wrap items-start gap-4">
                    <div className="min-w-0 flex-1 space-y-1.5">
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            {list.name}
                        </h1>
                        <p className="font-mono text-sm text-muted-foreground">
                            {list.code}
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            <StatusBadge
                                status={list.kind}
                                label={
                                    list.target
                                        ? `${list.kind_label} · ${list.target}`
                                        : list.kind_label
                                }
                                tone="info"
                            />
                            {!list.is_active && (
                                <StatusBadge
                                    status="inactive"
                                    label="Nonaktif"
                                    tone="muted"
                                />
                            )}
                        </div>
                    </div>
                    {can.manage && (
                        <Button onClick={() => setCreating(true)}>
                            <CopyPlus aria-hidden="true" />
                            Versi Baru
                        </Button>
                    )}
                </header>

                <DetailCard title="Berlaku sekarang" icon={Layers}>
                    {current ? (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="space-y-1">
                                <p className="font-semibold">
                                    Versi {current.version_no}
                                </p>
                                <p className="text-sm">
                                    <VersionPeriod version={current} />
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {current.items_count} baris harga
                                </p>
                            </div>
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/daftar-harga/versi/${current.id}/item`}
                                >
                                    <ListOrdered aria-hidden="true" />
                                    Lihat Harga
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <EmptyPanel
                            icon={Layers}
                            title="Belum ada versi yang berlaku"
                            text="Buat versi, isi harganya, lalu ajukan atau terbitkan. Selama belum ada, harga diambil dari daftar harga berikutnya (area atau bawaan)."
                        />
                    )}
                </DetailCard>

                <DetailCard title="Riwayat versi" icon={ListOrdered}>
                    {versions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada versi.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {versions.map((v) => (
                                <li
                                    key={v.id}
                                    className="flex flex-wrap items-start justify-between gap-3 p-3 sm:p-4"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <p className="flex flex-wrap items-center gap-2 font-semibold">
                                            Versi {v.version_no}
                                            <VersionBadge
                                                version={v}
                                                current={
                                                    v.id === currentVersionId
                                                }
                                            />
                                        </p>
                                        <p className="text-sm">
                                            <VersionPeriod version={v} />
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {v.items_count} baris harga
                                            {v.note && ` · ${v.note}`}
                                        </p>
                                        {v.approval_request_id &&
                                            v.status === 'pending_approval' && (
                                                <Link
                                                    href={`/persetujuan/${v.approval_request_id}`}
                                                    className="text-xs text-primary hover:underline"
                                                >
                                                    Lihat permintaan persetujuan
                                                </Link>
                                            )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link
                                                href={`/daftar-harga/versi/${v.id}/item`}
                                            >
                                                {v.status === 'draft' &&
                                                can.manage
                                                    ? 'Isi Harga'
                                                    : 'Lihat Harga'}
                                            </Link>
                                        </Button>
                                        {v.status === 'draft' && can.manage && (
                                            <Button
                                                size="sm"
                                                onClick={() => setSubmitting(v)}
                                            >
                                                <Send aria-hidden="true" />
                                                {can.publish
                                                    ? 'Terbitkan'
                                                    : 'Ajukan'}
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </DetailCard>
            </div>

            {creating && (
                <NewVersionDialog
                    list={list}
                    versions={versions}
                    nowLocal={nowLocal}
                    onClose={() => setCreating(false)}
                />
            )}
            {submitting && (
                <SubmitVersionDialog
                    version={submitting}
                    canPublish={can.publish}
                    canBackdate={can.backdate}
                    onClose={() => setSubmitting(null)}
                />
            )}
        </>
    );
}

function NewVersionDialog({
    list,
    versions,
    nowLocal,
    onClose,
}: {
    list: PriceListRow;
    versions: PriceVersion[];
    nowLocal: string;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const latest =
        versions.find(
            (v) => v.status === 'published' || v.status === 'superseded',
        ) ?? versions[0];
    const [form, setForm] = useState({
        mode: latest ? 'copy' : 'empty',
        source_version_id: latest ? String(latest.id) : '',
        percent: '',
        effective_from: nowLocal,
        note: '',
    });
    const [processing, setProcessing] = useState(false);
    const set = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((f) => ({ ...f, [key]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            `/daftar-harga/${list.id}/versi`,
            {
                ...form,
                source_version_id:
                    form.mode === 'empty'
                        ? null
                        : Number(form.source_version_id),
                percent: form.mode === 'copy_percent' ? form.percent : null,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={CopyPlus}
            size="lg"
            title={`Versi baru · ${list.name}`}
            description="Versi baru selalu dibuat sebagai draf. Isi atau ubah harganya, lalu ajukan/terbitkan."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Buat Versi
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="pv-mode">Isi awal</Label>
                    <NativeSelect
                        id="pv-mode"
                        value={form.mode}
                        onChange={(e) => set('mode', e.target.value)}
                    >
                        <option value="empty">Kosong</option>
                        <option value="copy" disabled={versions.length === 0}>
                            Salin versi lain
                        </option>
                        <option
                            value="copy_percent"
                            disabled={versions.length === 0}
                        >
                            Salin dengan naik/turun %
                        </option>
                    </NativeSelect>
                </div>
                {form.mode !== 'empty' && (
                    <div className="grid gap-2">
                        <Label htmlFor="pv-source">Versi sumber</Label>
                        <NativeSelect
                            id="pv-source"
                            value={form.source_version_id}
                            onChange={(e) =>
                                set('source_version_id', e.target.value)
                            }
                        >
                            {versions.map((v) => (
                                <option key={v.id} value={String(v.id)}>
                                    Versi {v.version_no} · {v.status_label} ·{' '}
                                    {v.items_count} baris
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.source_version_id} />
                    </div>
                )}
                {form.mode === 'copy_percent' && (
                    <div className="grid gap-2">
                        <Label htmlFor="pv-percent">Naik/turun (%)</Label>
                        <Input
                            id="pv-percent"
                            inputMode="decimal"
                            value={form.percent}
                            onChange={(e) => set('percent', e.target.value)}
                            placeholder="mis. 5 atau -2,5"
                            aria-invalid={!!errors.percent || undefined}
                        />
                        <p className="text-xs text-muted-foreground">
                            Hasil dibulatkan ke atas sesuai pengaturan
                            pembulatan harga.
                        </p>
                        <InputError message={errors.percent} />
                    </div>
                )}
                <div className="grid gap-2">
                    <Label htmlFor="pv-from">Berlaku mulai</Label>
                    <Input
                        id="pv-from"
                        type="datetime-local"
                        value={form.effective_from}
                        onChange={(e) => set('effective_from', e.target.value)}
                        aria-invalid={!!errors.effective_from || undefined}
                    />
                    <InputError message={errors.effective_from} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="pv-note">Catatan (opsional)</Label>
                    <Input
                        id="pv-note"
                        value={form.note}
                        onChange={(e) => set('note', e.target.value)}
                        maxLength={255}
                        placeholder="mis. Kenaikan harga prinsipal Desember"
                    />
                </div>
            </div>
        </FormDialog>
    );
}

PriceListShow.layout = {
    breadcrumbs: [{ title: 'Daftar Harga', href: '/daftar-harga' }],
};
