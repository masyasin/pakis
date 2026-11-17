import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { DisposalReportDetail } from './show';

type Props = { report: DisposalReportDetail; company: string };

const SIGNERS = ['Pelaksana', 'Saksi 1', 'Saksi 2', 'Kepala Gudang'];

/**
 * Cetak berita acara (BR-163): daftar batch, jumlah, nilai, dan kolom tanda tangan saksi. Template
 * generik sampai prosedur resmi dinas & prinsipal dikonfirmasi (OQ-115). Tanpa NIK.
 */
export default function DisposalReportPrint({ report, company }: Props) {
    return (
        <>
            <Head title={`Cetak ${report.number}`} />
            <div className="mx-auto max-w-[800px] space-y-6 bg-white p-8 text-sm text-black print:p-0">
                <div className="flex justify-end print:hidden">
                    <Button onClick={() => window.print()}>
                        <Printer aria-hidden="true" />
                        Cetak
                    </Button>
                </div>
                <header className="space-y-1 text-center">
                    <p className="font-semibold uppercase">{company}</p>
                    <h1 className="text-lg font-bold uppercase">
                        Berita Acara {report.method_label}
                    </h1>
                    <p className="font-mono">{report.number}</p>
                </header>
                <p>
                    Pada{' '}
                    {report.planned_date
                        ? formatCivilDate(report.planned_date)
                        : '……………………'}{' '}
                    bertempat di {report.location.name}, telah dilakukan{' '}
                    {report.method_label.toLowerCase()} atas barang berikut
                    dengan alasan {report.reason.toLowerCase()}:
                </p>
                <table className="w-full border-collapse">
                    <thead>
                        <tr>
                            {[
                                'No',
                                'Produk',
                                'Batch',
                                'ED',
                                'Qty',
                                'Nilai',
                            ].map((h) => (
                                <th
                                    key={h}
                                    className="border border-black px-2 py-1 text-left"
                                >
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {report.lines.map((l, i) => (
                            <tr key={l.id}>
                                <td className="border border-black px-2 py-1">
                                    {i + 1}
                                </td>
                                <td className="border border-black px-2 py-1">
                                    {l.sku} · {l.name}
                                </td>
                                <td className="border border-black px-2 py-1 font-mono">
                                    {l.batch_no}
                                </td>
                                <td className="border border-black px-2 py-1">
                                    {l.expiry_date
                                        ? formatCivilDate(l.expiry_date)
                                        : '–'}
                                </td>
                                <td className="border border-black px-2 py-1 text-right">
                                    {formatQty(l.qty_base)}{' '}
                                    {l.unit.toLowerCase()}
                                </td>
                                <td className="border border-black px-2 py-1 text-right">
                                    {formatRupiah(
                                        String(
                                            Number(l.qty_base) *
                                                Number(l.unit_cost),
                                        ),
                                    )}
                                </td>
                            </tr>
                        ))}
                        <tr>
                            <td
                                colSpan={5}
                                className="border border-black px-2 py-1 text-right font-semibold"
                            >
                                Total
                            </td>
                            <td className="border border-black px-2 py-1 text-right font-semibold">
                                {formatRupiah(report.total_cost)}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p>
                    Demikian berita acara ini dibuat dengan sebenarnya untuk
                    dipergunakan sebagaimana mestinya.
                </p>
                <div className="grid grid-cols-2 gap-8 pt-6 sm:grid-cols-4">
                    {SIGNERS.map((s) => (
                        <div key={s} className="space-y-16 text-center">
                            <p>{s}</p>
                            <p className="border-t border-black pt-1">
                                Nama / jabatan / instansi
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
