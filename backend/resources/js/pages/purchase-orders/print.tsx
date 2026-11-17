import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { PurchaseOrderDetail } from './show';

type Props = { order: PurchaseOrderDetail; company: string };

/** "2.5000" → "2,5%", nol → "–". */
function percent(value: string): string {
    const trimmed = value.includes('.')
        ? value.replace(/0+$/, '').replace(/\.$/, '')
        : value;

    return trimmed === '0' ? '–' : `${trimmed.replace('.', ',')}%`;
}

/**
 * Cetak PO (15 §5 `admin.purchase-orders.pdf`). Sementara berupa halaman HTML siap cetak dari
 * browser (Ctrl+P / Simpan sebagai PDF) sampai generator PDF diputuskan (ADR-114, OQ-109).
 * Warna dipaku hitam di atas putih agar hasil cetak sama di tema terang maupun gelap.
 */
export default function PurchaseOrderPrint({ order, company }: Props) {
    const draft = order.status === 'draft' || order.status === 'submitted';

    return (
        <>
            <Head title={`Cetak PO ${order.number}`} />
            <div className="min-h-screen bg-neutral-100 py-6 text-neutral-900 print:bg-white print:py-0">
                <div className="mx-auto mb-4 flex max-w-[210mm] justify-between gap-2 px-4 print:hidden">
                    <Button variant="outline" asChild>
                        <Link href={`/pembelian/po/${order.id}`}>
                            <ArrowLeft aria-hidden="true" />
                            Kembali ke PO
                        </Link>
                    </Button>
                    <Button onClick={() => window.print()}>
                        <Printer aria-hidden="true" />
                        Cetak
                    </Button>
                </div>

                <article className="relative mx-auto max-w-[210mm] bg-white p-[14mm] text-[12px] leading-relaxed shadow print:shadow-none">
                    {draft && (
                        <p className="mb-4 rounded border border-neutral-400 px-3 py-1.5 text-center font-semibold">
                            {order.status === 'draft'
                                ? 'DRAF: belum diajukan, bukan pesanan resmi'
                                : 'Menunggu persetujuan: belum pesanan resmi'}
                        </p>
                    )}
                    <header className="flex items-start justify-between gap-6 border-b-2 border-neutral-900 pb-3">
                        <div>
                            <p className="text-[15px] font-bold">{company}</p>
                            <p className="text-neutral-600">
                                {order.stock_location.name}
                            </p>
                        </div>
                        <div className="text-right">
                            <h1 className="text-[18px] font-bold tracking-wide">
                                PURCHASE ORDER
                            </h1>
                            <p className="font-mono text-[13px] font-semibold">
                                {order.number}
                            </p>
                        </div>
                    </header>

                    <dl className="mt-4 grid grid-cols-2 gap-x-8 gap-y-1">
                        <div>
                            <dt className="text-neutral-600">Kepada</dt>
                            <dd className="font-semibold">
                                {order.supplier.name}
                            </dd>
                            <dd className="font-mono text-neutral-600">
                                {order.supplier.code}
                            </dd>
                        </div>
                        <div className="grid grid-cols-[auto_1fr] gap-x-3">
                            <dt className="text-neutral-600">Tanggal PO</dt>
                            <dd>{formatCivilDate(order.order_date)}</dd>
                            <dt className="text-neutral-600">Kirim ke</dt>
                            <dd>{order.stock_location.name}</dd>
                            <dt className="text-neutral-600">
                                Perkiraan datang
                            </dt>
                            <dd>
                                {order.expected_date
                                    ? formatCivilDate(order.expected_date)
                                    : '–'}
                            </dd>
                            <dt className="text-neutral-600">Tempo bayar</dt>
                            <dd>
                                {order.payment_term_days === 0
                                    ? 'Tunai'
                                    : `${order.payment_term_days} hari`}
                            </dd>
                        </div>
                    </dl>

                    <table className="mt-5 w-full border-collapse">
                        <thead>
                            <tr className="border-y border-neutral-900 text-left">
                                <th className="py-1.5 pr-2">No</th>
                                <th className="py-1.5 pr-2">Produk</th>
                                <th className="py-1.5 pr-2 text-right">Qty</th>
                                <th className="py-1.5 pr-2 text-right">
                                    Harga
                                </th>
                                <th className="py-1.5 pr-2 text-right">
                                    Diskon
                                </th>
                                <th className="py-1.5 text-right">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            {order.lines.map((l) => (
                                <tr
                                    key={l.id}
                                    className="border-b border-neutral-300 align-top"
                                >
                                    <td className="py-1.5 pr-2">{l.line_no}</td>
                                    <td className="py-1.5 pr-2">
                                        {l.name}
                                        <span className="block font-mono text-[10px] text-neutral-600">
                                            {l.sku}
                                        </span>
                                    </td>
                                    <td className="py-1.5 pr-2 text-right whitespace-nowrap tabular-nums">
                                        {formatQty(l.qty)}{' '}
                                        {l.unit.toLowerCase()}
                                    </td>
                                    <td className="py-1.5 pr-2 text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(l.unit_price)}
                                    </td>
                                    <td className="py-1.5 pr-2 text-right tabular-nums">
                                        {percent(l.discount_pct)}
                                    </td>
                                    <td className="py-1.5 text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(l.line_total)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <dl className="mt-3 ml-auto grid w-64 grid-cols-[1fr_auto] gap-x-6 gap-y-0.5">
                        <dt>Subtotal</dt>
                        <dd className="text-right tabular-nums">
                            {formatRupiah(order.subtotal)}
                        </dd>
                        <dt>Diskon</dt>
                        <dd className="text-right tabular-nums">
                            {formatRupiah(order.discount_total)}
                        </dd>
                        <dt>Pajak</dt>
                        <dd className="text-right tabular-nums">
                            {formatRupiah(order.tax_total)}
                        </dd>
                        <dt className="border-t border-neutral-900 pt-1 font-bold">
                            Total
                        </dt>
                        <dd className="border-t border-neutral-900 pt-1 text-right font-bold tabular-nums">
                            {formatRupiah(order.grand_total)}
                        </dd>
                    </dl>

                    {order.note && (
                        <p className="mt-4">
                            <span className="text-neutral-600">Catatan: </span>
                            {order.note}
                        </p>
                    )}

                    <div className="mt-12 grid grid-cols-2 gap-12 text-center">
                        <div>
                            <p>Dibuat oleh</p>
                            <p className="mt-16 border-t border-neutral-900 pt-1">
                                {order.created_by ?? ''}
                            </p>
                        </div>
                        <div>
                            <p>Disetujui oleh</p>
                            <p className="mt-16 border-t border-neutral-900 pt-1">
                                &nbsp;
                            </p>
                        </div>
                    </div>
                </article>
            </div>
        </>
    );
}
