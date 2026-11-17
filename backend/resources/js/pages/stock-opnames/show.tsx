import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    ClipboardCheck,
    ClipboardList,
    EyeOff,
    History,
    PackageSearch,
    Play,
    Scale,
    Send,
    Stamp,
    Warehouse,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { ActionLabel, MoreActionsMenu } from '@/components/pakis/detail-hero';
import type {
    DetailHeroHighlight,
    DetailMenuAction,
} from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { DocumentTimeline } from '@/components/pakis/document-timeline';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatRupiah } from '@/lib/money';

type Opname = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    location: { id: number; code: string; name: string };
    scope: string;
    scope_label: string;
    scope_count: number;
    blind_count: boolean;
    frozen_at: string | null;
    approval_request_id: number | null;
    posted_by: string | null;
    posted_at: string | null;
    created_by: string | null;
    created_at: string | null;
    note: string | null;
};

type Summary = {
    lines: number;
    counted: number;
    found: number;
    /** Tidak dikirim saat hitung buta & sedang dihitung (SEC-103). */
    variance_lines?: number;
    /** Nilai selisih (HPP); null tanpa izin lihat biaya. */
    variance_value?: string | null;
};

type HistoryEntry = {
    id: number;
    occurred_at: string;
    actor: string;
    event_label: string;
    reason: string | null;
};

type Props = {
    opname: Opname;
    summary: Summary;
    history: HistoryEntry[];
    can: {
        start: boolean;
        count: boolean;
        finish: boolean;
        post: boolean;
        cancel: boolean;
    };
};

type Dialog = 'start' | 'finish' | 'post' | 'cancel' | null;

/**
 * Detail stock opname (15 §7.1 dokumen bergaris, BR-162). Alur: draf → Mulai Hitung (stok
 * dibekukan) → lembar hitung → Selesai Hitung → tinjau & isi alasan selisih → Posting (selisih
 * lewat persetujuan sesuai nilai). Aksi sesuai status & izin dari server.
 */
export default function StockOpnameShow({
    opname,
    summary,
    history,
    can,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const [dialog, setDialog] = useState<Dialog>(null);
    const at = (iso: string | null) => iso && formatDateTimeIn(iso, timezone);
    const sheetHref = `/opname/${opname.id}/baris`;
    const hasSheet = opname.status !== 'draft';

    const highlights: DetailHeroHighlight[] = [
        {
            icon: ClipboardCheck,
            label: 'Dihitung',
            value: `${summary.counted} dari ${summary.lines} baris`,
            tone:
                summary.lines > 0 && summary.counted === summary.lines
                    ? 'success'
                    : 'default',
        },
        {
            icon: Scale,
            label: 'Selisih',
            value:
                summary.variance_lines === undefined
                    ? opname.blind_count && opname.status === 'counting'
                        ? 'Tersembunyi (hitung buta)'
                        : null
                    : summary.variance_value
                      ? `${summary.variance_lines} baris · ${formatRupiah(summary.variance_value)}`
                      : `${summary.variance_lines} baris`,
            tone: (summary.variance_lines ?? 0) > 0 ? 'warning' : 'default',
        },
        {
            icon: PackageSearch,
            label: 'Batch temuan',
            value: summary.found > 0 ? `${summary.found} batch` : null,
        },
    ];

    const menu: DetailMenuAction[] = [];
    if (can.cancel) {
        menu.push({
            key: 'cancel',
            label: 'Batalkan opname',
            icon: Ban,
            destructive: true,
            onSelect: () => setDialog('cancel'),
        });
    }

    return (
        <>
            <Head title={`Opname ${opname.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref="/opname"
                    backLabel="Daftar stock opname"
                    kind="Stock opname"
                    icon={ClipboardList}
                    number={opname.number}
                    status={`stock_opname.${opname.status}`}
                    statusLabel={opname.status_label}
                    muted={['posted', 'cancelled'].includes(opname.status)}
                    meta={[
                        {
                            icon: Warehouse,
                            label: 'Lokasi',
                            value: opname.location.name,
                        },
                        {
                            icon: EyeOff,
                            label: 'Hitung buta',
                            value: opname.blind_count ? 'Hitung buta' : null,
                        },
                    ]}
                    highlights={highlights}
                    actions={
                        <>
                            {menu.length > 0 && (
                                <MoreActionsMenu items={menu} />
                            )}
                            {hasSheet && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={sheetHref}
                                        aria-label={`Lembar hitung ${opname.number}`}
                                    >
                                        <ClipboardCheck aria-hidden="true" />
                                        <ActionLabel>Lembar Hitung</ActionLabel>
                                    </Link>
                                </Button>
                            )}
                            {can.start && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('start')}
                                >
                                    <Play aria-hidden="true" />
                                    <ActionLabel>Mulai Hitung</ActionLabel>
                                </Button>
                            )}
                            {can.finish && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('finish')}
                                >
                                    <ClipboardCheck aria-hidden="true" />
                                    <ActionLabel>Selesai Hitung</ActionLabel>
                                </Button>
                            )}
                            {can.post && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('post')}
                                >
                                    <Send aria-hidden="true" />
                                    <ActionLabel>Posting</ActionLabel>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <DetailCard title="Ringkasan" icon={ClipboardList}>
                        <FieldGrid>
                            <Field
                                label="Lokasi"
                                value={opname.location.name}
                                hint={opname.location.code}
                                wide
                            />
                            <Field
                                label="Cakupan"
                                value={
                                    opname.scope === 'full'
                                        ? opname.scope_label
                                        : `${opname.scope_label} · ${opname.scope_count} pilihan`
                                }
                            />
                            <Field
                                label="Hitung buta"
                                value={opname.blind_count ? 'Ya' : 'Tidak'}
                            />
                            <Field
                                label="Stok dibekukan"
                                value={at(opname.frozen_at)}
                            />
                            <Field
                                label="Diposting"
                                value={
                                    opname.posted_at &&
                                    `${at(opname.posted_at)}${opname.posted_by ? ` · ${opname.posted_by}` : ''}`
                                }
                            />
                            <Field
                                label="Dibuat oleh"
                                value={opname.created_by}
                            />
                            <Field label="Catatan" value={opname.note} wide />
                        </FieldGrid>
                        {opname.status === 'review' && (
                            <p className="mt-4 text-sm text-muted-foreground">
                                Buka lembar hitung untuk melihat selisih per
                                baris, menghitung ulang, dan mengisi alasan
                                selisih sebelum posting.
                            </p>
                        )}
                    </DetailCard>

                    <div className="space-y-5">
                        {opname.approval_request_id !== null && (
                            <DetailCard
                                title="Persetujuan selisih"
                                icon={Stamp}
                            >
                                <p className="text-sm text-muted-foreground">
                                    Selisih diposting setelah disetujui. Bila
                                    ditolak, baris selisih wajib dihitung ulang
                                    orang lain.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3"
                                    asChild
                                >
                                    <Link
                                        href={`/persetujuan/${opname.approval_request_id}`}
                                    >
                                        Lihat persetujuan
                                    </Link>
                                </Button>
                            </DetailCard>
                        )}
                        <DetailCard title="Riwayat" icon={History}>
                            <DocumentTimeline
                                label={`Riwayat opname ${opname.number}`}
                                items={history.map((h) => ({
                                    key: h.id,
                                    title: h.event_label,
                                    meta: `${h.actor} · ${formatDateTimeIn(h.occurred_at, timezone)}`,
                                    note: h.reason,
                                }))}
                            />
                        </DetailCard>
                    </div>
                </div>
            </div>

            {dialog !== null && (
                <ActionDialog
                    kind={dialog}
                    opname={opname}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

const ACTION = {
    start: {
        title: 'Mulai hitung?',
        description:
            'Jumlah sistem setiap batch di cakupan dicatat sekarang. Penjualan dan mutasi tetap boleh berjalan; sistem memperhitungkannya saat menghitung selisih.',
        button: 'Mulai Hitung',
        path: 'mulai',
        icon: Play,
        tone: 'default',
    },
    finish: {
        title: 'Selesai hitung?',
        description:
            'Lembar hitung ditutup dan selisih ditampilkan. Baris dengan selisih besar harus sudah dihitung ulang orang lain.',
        button: 'Selesai Hitung',
        path: 'ajukan',
        icon: ClipboardCheck,
        tone: 'default',
    },
    post: {
        title: 'Posting opname?',
        description:
            'Tanpa selisih, opname langsung diposting. Bila ada selisih, opname diajukan ke penyetuju sesuai nilainya dan stok disesuaikan setelah disetujui.',
        button: 'Posting',
        path: 'posting',
        icon: Send,
        tone: 'default',
    },
    cancel: {
        title: 'Batalkan opname?',
        description:
            'Hasil hitung tidak diposting dan batch bebas untuk opname lain. Nomornya tetap tercatat.',
        button: 'Batalkan opname',
        path: 'batal',
        icon: Ban,
        tone: 'danger',
    },
} as const;

function ActionDialog({
    kind,
    opname,
    onClose,
}: {
    kind: Exclude<Dialog, null>;
    opname: Opname;
    onClose: () => void;
}) {
    const action = ACTION[kind];
    const form = useForm({});

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/opname/${opname.id}/${action.path}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={action.icon}
            tone={action.tone}
            title={action.title}
            description={action.description}
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button
                        type="submit"
                        variant={
                            action.tone === 'danger' ? 'destructive' : 'default'
                        }
                        disabled={form.processing}
                    >
                        {action.button}
                    </Button>
                </>
            }
        >
            <p className="text-sm">
                <span className="font-mono font-medium">{opname.number}</span> ·{' '}
                {opname.location.name}
            </p>
            {/* Galat aksi dari server (wajib hitung ulang, alasan, batch terkunci); toast juga tampil. */}
            {Object.values(form.errors as Record<string, string>).map(
                (message) => (
                    <InputError key={message} message={message} />
                ),
            )}
        </FormDialog>
    );
}

StockOpnameShow.layout = {
    breadcrumbs: [{ title: 'Stock Opname', href: '/opname' }],
};
