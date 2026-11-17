import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    CalendarDays,
    ClipboardList,
    Flame,
    History,
    Plus,
    Printer,
    Send,
    Stamp,
    Trash2,
    Warehouse,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { ActionLabel, MoreActionsMenu } from '@/components/pakis/detail-hero';
import type { DetailMenuAction } from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { DocumentLines } from '@/components/pakis/document-lines';
import type { DocumentLineColumn } from '@/components/pakis/document-lines';
import { DocumentTimeline } from '@/components/pakis/document-timeline';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';

type Line = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    batch_no: string;
    expiry_date: string | null;
    batch_status: string;
    qty_base: string;
    /** Hanya ada bila `stock.view_cost` (SEC-107). */
    unit_cost?: string;
};

type Witness = { name: string; role: string; organization: string };

export type DisposalReportDetail = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    method: string;
    method_label: string;
    location: { id: number; code: string; name: string };
    reason: string;
    planned_date: string | null;
    executed_at: string | null;
    executed_location: string | null;
    witnesses: Witness[];
    has_document: boolean;
    photo_count: number;
    /** URL bertanda tangan 5 menit (SEC-108/109). */
    document_url: string | null;
    photo_urls: string[];
    /** Hanya ada bila `stock.view_cost` (SEC-107). */
    total_cost?: string;
    approval_request_id: number | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    lines: Line[];
};

type Props = {
    report: DisposalReportDetail;
    history: Array<{
        id: number;
        occurred_at: string;
        actor: string;
        event_label: string;
        reason: string | null;
    }>;
    purchaseReturn: { id: number; number: string } | null;
    returnReasons: Array<{ value: number; label: string }>;
    can: { submit: boolean; execute: boolean; cancel: boolean };
    canViewCost: boolean;
};

const BASE_COLUMNS: DocumentLineColumn<Line>[] = [
    {
        key: 'product',
        header: 'Produk',
        primary: true,
        cell: (l) => (
            <span className="grid gap-0.5">
                <span className="font-medium break-words">{l.name}</span>
                <span className="font-mono text-xs text-muted-foreground">
                    {l.sku}
                </span>
            </span>
        ),
    },
    {
        key: 'batch',
        header: 'Batch',
        cell: (l) => (
            <span className="grid gap-0.5">
                <span className="font-mono text-sm">{l.batch_no}</span>
                {l.expiry_date && (
                    <span className="text-xs text-muted-foreground">
                        ED {formatCivilDate(l.expiry_date)}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'qty',
        header: 'Qty',
        numeric: true,
        cell: (l) => `${formatQty(l.qty_base)} ${l.unit.toLowerCase()}`,
    },
];

const VALUE_COLUMN: DocumentLineColumn<Line> = {
    key: 'value',
    header: 'Nilai',
    numeric: true,
    emphasis: true,
    cell: (l) =>
        formatRupiah(String(Number(l.qty_base) * Number(l.unit_cost ?? 0))),
};

/** Kolom nilai hanya untuk `stock.view_cost` (SEC-107); kepala gudang melihat qty saja. */
export function disposalColumns(
    canViewCost: boolean,
): DocumentLineColumn<Line>[] {
    return canViewCost ? [...BASE_COLUMNS, VALUE_COLUMN] : BASE_COLUMNS;
}

type Dialog = 'submit' | 'cancel' | 'execute' | null;

/**
 * Detail berita acara (15 §7.1, BR-163): ajukan → disetujui (batch ditahan) → cetak BA → laksanakan
 * dengan scan BA, foto, dan saksi. Metode retur ke prinsipal membuat retur draf saat dilaksanakan.
 */
export default function DisposalReportShow({
    report,
    history,
    purchaseReturn,
    returnReasons,
    can,
    canViewCost,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const [dialog, setDialog] = useState<Dialog>(null);

    const menu: DetailMenuAction[] = [
        {
            key: 'print',
            label: 'Cetak berita acara',
            icon: Printer,
            href: `/pemusnahan/${report.id}/cetak`,
            newTab: true,
        },
    ];
    if (can.cancel) {
        menu.push({
            key: 'cancel',
            label: 'Batalkan',
            icon: Ban,
            destructive: true,
            separatorBefore: true,
            onSelect: () => setDialog('cancel'),
        });
    }

    return (
        <>
            <Head title={`Berita Acara ${report.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref="/pemusnahan"
                    backLabel="Daftar berita acara"
                    kind="Berita acara"
                    icon={Flame}
                    number={report.number}
                    status={`disposal_report.${report.status}`}
                    statusLabel={report.status_label}
                    muted={['executed', 'cancelled', 'rejected'].includes(
                        report.status,
                    )}
                    meta={[
                        {
                            icon: Warehouse,
                            label: 'Lokasi',
                            value: report.location.name,
                        },
                        {
                            icon: CalendarDays,
                            label: 'Rencana',
                            value:
                                report.planned_date &&
                                formatCivilDate(report.planned_date),
                        },
                    ]}
                    highlights={[
                        {
                            icon: Flame,
                            label: 'Metode',
                            value: report.method_label,
                        },
                        ...(report.total_cost !== undefined
                            ? [
                                  {
                                      icon: ClipboardList,
                                      label: 'Nilai (HPP)',
                                      value: formatRupiah(report.total_cost),
                                  },
                              ]
                            : []),
                    ]}
                    actions={
                        <>
                            <MoreActionsMenu items={menu} />
                            {can.submit && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('submit')}
                                >
                                    <Send aria-hidden="true" />
                                    <ActionLabel>Ajukan</ActionLabel>
                                </Button>
                            )}
                            {can.execute && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('execute')}
                                >
                                    <Flame aria-hidden="true" />
                                    <ActionLabel>Laksanakan</ActionLabel>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <DetailCard title="Batch" icon={ClipboardList}>
                        <DocumentLines
                            rows={report.lines}
                            rowKey={(l) => l.id}
                            columns={disposalColumns(canViewCost)}
                            caption={`Batch berita acara ${report.number}`}
                            totals={
                                report.total_cost !== undefined
                                    ? [
                                          {
                                              label: 'Total nilai',
                                              value: formatRupiah(
                                                  report.total_cost,
                                              ),
                                              emphasis: true,
                                          },
                                      ]
                                    : undefined
                            }
                        />
                    </DetailCard>
                    <div className="space-y-5">
                        <DetailCard title="Ringkasan" icon={Flame}>
                            <FieldGrid>
                                <Field
                                    label="Lokasi"
                                    value={report.location.name}
                                    hint={report.location.code}
                                    wide
                                />
                                <Field label="Alasan" value={report.reason} />
                                <Field
                                    label="Metode"
                                    value={report.method_label}
                                />
                                <Field
                                    label="Dilaksanakan"
                                    value={
                                        report.executed_at &&
                                        formatDateTimeIn(
                                            report.executed_at,
                                            timezone,
                                        )
                                    }
                                />
                                <Field
                                    label="Tempat"
                                    value={report.executed_location}
                                />
                                <Field
                                    label="Saksi"
                                    value={
                                        report.witnesses.length > 0
                                            ? report.witnesses
                                                  .map(
                                                      (w) =>
                                                          `${w.name} (${w.role}, ${w.organization})`,
                                                  )
                                                  .join('; ')
                                            : null
                                    }
                                    wide
                                />
                                <Field
                                    label="Bukti"
                                    value={
                                        report.has_document ||
                                        report.photo_urls.length > 0 ? (
                                            <EvidenceLinks
                                                documentUrl={
                                                    report.document_url
                                                }
                                                photoUrls={report.photo_urls}
                                            />
                                        ) : null
                                    }
                                    wide
                                />
                                <Field
                                    label="Dibuat oleh"
                                    value={report.created_by}
                                />
                                <Field
                                    label="Catatan"
                                    value={report.note}
                                    wide
                                />
                            </FieldGrid>
                            {purchaseReturn && (
                                <p className="mt-4 text-sm">
                                    Retur dibuat:{' '}
                                    <Link
                                        href={`/pembelian/retur/${purchaseReturn.id}`}
                                        className="font-mono font-medium text-primary hover:underline"
                                    >
                                        {purchaseReturn.number}
                                    </Link>
                                </p>
                            )}
                        </DetailCard>
                        {report.approval_request_id !== null && (
                            <DetailCard title="Persetujuan" icon={Stamp}>
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/persetujuan/${report.approval_request_id}`}
                                    >
                                        Lihat persetujuan
                                    </Link>
                                </Button>
                            </DetailCard>
                        )}
                        <DetailCard title="Riwayat" icon={History}>
                            <DocumentTimeline
                                label={`Riwayat berita acara ${report.number}`}
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

            {(dialog === 'submit' || dialog === 'cancel') && (
                <ConfirmDialog
                    kind={dialog}
                    report={report}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'execute' && (
                <ExecuteDialog
                    report={report}
                    returnReasons={returnReasons}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

function ConfirmDialog({
    kind,
    report,
    onClose,
}: {
    kind: 'submit' | 'cancel';
    report: DisposalReportDetail;
    onClose: () => void;
}) {
    const form = useForm({});
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            `/pemusnahan/${report.id}/${kind === 'submit' ? 'ajukan' : 'batal'}`,
            { preserveScroll: true, onSuccess: () => onClose() },
        );
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={kind === 'submit' ? Send : Ban}
            tone={kind === 'submit' ? 'default' : 'danger'}
            title={
                kind === 'submit'
                    ? 'Ajukan berita acara?'
                    : 'Batalkan berita acara?'
            }
            description={
                kind === 'submit'
                    ? 'Berita acara dikirim ke penyetuju sesuai nilainya. Setelah disetujui, batch ditahan sampai dilaksanakan.'
                    : 'Batch yang ditahan dilepas. Nomor tetap tercatat.'
            }
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button
                        type="submit"
                        variant={kind === 'submit' ? 'default' : 'destructive'}
                        disabled={form.processing}
                    >
                        {kind === 'submit' ? 'Ajukan' : 'Batalkan'}
                    </Button>
                </>
            }
        >
            <p className="text-sm">
                <span className="font-mono font-medium">{report.number}</span>
                {report.total_cost !== undefined &&
                    ` · ${formatRupiah(report.total_cost)}`}
            </p>
            {Object.values(form.errors as Record<string, string>).map((m) => (
                <InputError key={m} message={m} />
            ))}
        </FormDialog>
    );
}

/**
 * Laksanakan (BR-163): scan BA bertanda tangan + ≥ 1 foto + ≥ 2 saksi (nama, jabatan, instansi;
 * TIDAK ada isian NIK). Metode retur ke prinsipal juga meminta supplier & alasan retur.
 */
function ExecuteDialog({
    report,
    returnReasons,
    onClose,
}: {
    report: DisposalReportDetail;
    returnReasons: Props['returnReasons'];
    onClose: () => void;
}) {
    const toPrincipal = report.method === 'return_to_principal';
    const form = useForm<{
        executed_location: string;
        witnesses: Witness[];
        document: File | null;
        photos: File[];
        supplier_id: string;
        return_reason_id: string;
    }>({
        executed_location: '',
        witnesses: [
            { name: '', role: '', organization: '' },
            { name: '', role: '', organization: '' },
        ],
        document: null,
        photos: [],
        supplier_id: '',
        return_reason_id: '',
    });
    const err = form.errors as Record<string, string | undefined>;
    const setWitness = (i: number, patch: Partial<Witness>) =>
        form.setData(
            'witnesses',
            form.data.witnesses.map((w, k) =>
                k === i ? { ...w, ...patch } : w,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            supplier_id: d.supplier_id === '' ? null : Number(d.supplier_id),
            return_reason_id:
                d.return_reason_id === '' ? null : Number(d.return_reason_id),
        }));
        form.post(`/pemusnahan/${report.id}/laksanakan`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Flame}
            size="lg"
            title={`Laksanakan ${report.number}`}
            description={
                toPrincipal
                    ? 'Batch diserahkan kembali ke prinsipal: sistem membuat retur draf berisi batch ini.'
                    : 'Stok batch dikeluarkan dan batch ditandai dimusnahkan.'
            }
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Laksanakan
                    </Button>
                </>
            }
        >
            <InputError message={err.form} />
            <div className="grid gap-2">
                <Label htmlFor="ex-location">Tempat pelaksanaan</Label>
                <Input
                    id="ex-location"
                    value={form.data.executed_location}
                    onChange={(e) =>
                        form.setData('executed_location', e.target.value)
                    }
                />
                <InputError message={err.executed_location} />
            </div>
            {toPrincipal && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="ex-supplier">
                            Supplier / prinsipal
                        </Label>
                        <AsyncSelect
                            id="ex-supplier"
                            source="suppliers"
                            value={
                                form.data.supplier_id === ''
                                    ? []
                                    : [form.data.supplier_id]
                            }
                            onChange={(v) =>
                                form.setData('supplier_id', String(v[0] ?? ''))
                            }
                        />
                        <InputError message={err.supplier_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="ex-return-reason">Alasan retur</Label>
                        <NativeSelect
                            id="ex-return-reason"
                            value={form.data.return_reason_id}
                            onChange={(e) =>
                                form.setData('return_reason_id', e.target.value)
                            }
                        >
                            <option value="">Pilih alasan</option>
                            {returnReasons.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={err.return_reason_id} />
                    </div>
                </div>
            )}
            <fieldset className="grid gap-3">
                <legend className="text-sm font-medium">
                    Saksi (minimal 2, tanpa NIK)
                </legend>
                {form.data.witnesses.map((w, i) => (
                    <div
                        key={i}
                        className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
                    >
                        <Input
                            aria-label={`Nama saksi ${i + 1}`}
                            placeholder="Nama"
                            value={w.name}
                            onChange={(e) =>
                                setWitness(i, { name: e.target.value })
                            }
                        />
                        <Input
                            aria-label={`Jabatan saksi ${i + 1}`}
                            placeholder="Jabatan"
                            value={w.role}
                            onChange={(e) =>
                                setWitness(i, { role: e.target.value })
                            }
                        />
                        <Input
                            aria-label={`Instansi saksi ${i + 1}`}
                            placeholder="Instansi"
                            value={w.organization}
                            onChange={(e) =>
                                setWitness(i, { organization: e.target.value })
                            }
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Hapus saksi ${i + 1}`}
                            disabled={form.data.witnesses.length <= 2}
                            onClick={() =>
                                form.setData(
                                    'witnesses',
                                    form.data.witnesses.filter(
                                        (_, k) => k !== i,
                                    ),
                                )
                            }
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                        <InputError
                            className="sm:col-span-4"
                            message={
                                err[`witnesses.${i}`] ??
                                err[`witnesses.${i}.name`] ??
                                err[`witnesses.${i}.role`] ??
                                err[`witnesses.${i}.organization`]
                            }
                        />
                    </div>
                ))}
                <InputError message={err.witnesses} />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="justify-self-start"
                    disabled={form.data.witnesses.length >= 10}
                    onClick={() =>
                        form.setData('witnesses', [
                            ...form.data.witnesses,
                            { name: '', role: '', organization: '' },
                        ])
                    }
                >
                    <Plus aria-hidden="true" />
                    Tambah Saksi
                </Button>
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="ex-document">
                        Scan berita acara bertanda tangan
                    </Label>
                    <Input
                        id="ex-document"
                        type="file"
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        onChange={(e) =>
                            form.setData(
                                'document',
                                e.target.files?.[0] ?? null,
                            )
                        }
                    />
                    <InputError message={err.document} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="ex-photos">Foto pelaksanaan</Label>
                    <Input
                        id="ex-photos"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp"
                        onChange={(e) =>
                            form.setData(
                                'photos',
                                Array.from(e.target.files ?? []),
                            )
                        }
                    />
                    <InputError message={err.photos ?? err['photos.0']} />
                </div>
            </div>
        </FormDialog>
    );
}

DisposalReportShow.layout = {
    breadcrumbs: [{ title: 'Pemusnahan', href: '/pemusnahan' }],
};

/**
 * Tautan scan BA & foto pemusnahan. URL bertanda tangan kedaluwarsa 5 menit (SEC-108): bila sudah
 * lewat, muat ulang halaman untuk tautan baru.
 */
function EvidenceLinks({
    documentUrl,
    photoUrls,
}: {
    documentUrl: string | null;
    photoUrls: string[];
}) {
    return (
        <span className="flex flex-wrap gap-x-3 gap-y-1">
            {documentUrl && (
                <a
                    href={documentUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="font-medium text-primary hover:underline"
                >
                    Scan BA
                </a>
            )}
            {photoUrls.map((url, i) => (
                <a
                    key={url}
                    href={url}
                    target="_blank"
                    rel="noreferrer"
                    className="font-medium text-primary hover:underline"
                >
                    Foto {i + 1}
                </a>
            ))}
        </span>
    );
}
