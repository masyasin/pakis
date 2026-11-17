import { fireEvent, render, screen, within } from '@testing-library/react';
import { Printer, ReceiptText, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MoreActionsMenu } from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { DocumentLines } from '@/components/pakis/document-lines';
import type { DocumentLineColumn } from '@/components/pakis/document-lines';
import { DocumentTimeline } from '@/components/pakis/document-timeline';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...props}>
            {children}
        </a>
    ),
}));

describe('DocumentHeader', () => {
    it('nomor dokumen jadi judul monospace, badge status, sorotan, dan tautan kembali', () => {
        render(
            <DocumentHeader
                backHref="/pembelian/po"
                backLabel="Daftar purchase order"
                kind="Purchase order"
                icon={ReceiptText}
                number="PO-2611-0001"
                status="purchase_order.draft"
                statusLabel="Draf"
                highlights={[
                    { icon: Wallet, label: 'Total', value: 'Rp. 1.746.000' },
                    { label: 'Perkiraan datang', value: null },
                ]}
                meta={[
                    { icon: Wallet, label: 'Tanggal PO', value: '17 Nov 2026' },
                ]}
            />,
        );

        const heading = screen.getByRole('heading', { level: 1 });
        expect(heading.textContent).toBe('PO-2611-0001');
        expect(heading.className).toContain('font-mono');
        expect(screen.getByText('Purchase order')).toBeTruthy();
        expect(screen.getByText('Draf')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Daftar purchase order' })
                .getAttribute('href'),
        ).toBe('/pembelian/po');

        for (const list of screen.getAllByRole('list', { name: 'Sorotan' })) {
            expect(
                within(list)
                    .getAllByRole('listitem')
                    .map((li) => li.textContent),
            ).toEqual(['Total: Rp. 1.746.000']);
        }
    });

    it('dengan judul teks, nomor tampil sebagai chip kode; tanpa status tidak ada badge', () => {
        render(
            <DocumentHeader
                backHref="/persetujuan"
                backLabel="Kotak persetujuan"
                title="Eko Demo"
                number="KOR-2611-0001"
            />,
        );

        const heading = screen.getByRole('heading', { level: 1 });
        expect(heading.textContent).toBe('Eko Demo');
        expect(heading.className).not.toContain('font-mono');
        expect(screen.getByText('KOR-2611-0001').className).toContain(
            'font-mono',
        );
    });
});

type Row = { id: number; name: string; total: string };

const columns: DocumentLineColumn<Row>[] = [
    { key: 'name', header: 'Produk', primary: true, cell: (r) => r.name },
    {
        key: 'total',
        header: 'Jumlah',
        numeric: true,
        emphasis: true,
        cell: (r) => r.total,
    },
];

describe('DocumentLines', () => {
    it('merender tabel (layar lebar), kartu (HP), catatan kaki, dan kotak total', () => {
        render(
            <DocumentLines
                rows={[{ id: 1, name: 'Produk Demo', total: 'Rp. 10.000' }]}
                rowKey={(r) => r.id}
                columns={columns}
                caption="Baris uji"
                footer="Sebagian barang sudah diterima di gudang."
                totals={[
                    { label: 'Subtotal', value: 'Rp. 10.000' },
                    { label: 'Total', value: 'Rp. 10.000', emphasis: true },
                ]}
            />,
        );

        const table = screen.getByRole('table');
        const header = within(table).getByRole('columnheader', {
            name: 'Jumlah',
        });
        expect(header.className).toContain('text-right');

        const cards = screen.getByRole('list', { name: 'Baris uji' });
        expect(within(cards).getAllByRole('listitem')).toHaveLength(1);
        expect(
            screen.getByText('Sebagian barang sudah diterima di gudang.'),
        ).toBeTruthy();

        const totals = screen.getByLabelText('Ringkasan total');
        const total = within(totals).getByText('Total');
        expect(total.parentElement?.className).toContain('border-t');
    });

    it('menampilkan pesan kosong tanpa tabel', () => {
        render(
            <DocumentLines
                rows={[]}
                rowKey={(r: Row) => r.id}
                columns={columns}
                empty="Belum ada barang."
            />,
        );

        expect(screen.getByText('Belum ada barang.')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });
});

describe('DocumentTimeline', () => {
    it('urut sesuai data dengan catatan; kosong menampilkan pesan', () => {
        const { rerender } = render(
            <DocumentTimeline
                label="Riwayat uji"
                items={[
                    { key: 2, title: 'Ditutup', note: 'Sisa tidak dikirim' },
                    { key: 1, title: 'PO dibuat', meta: 'sistem · 17 Nov' },
                ]}
            />,
        );

        const list = screen.getByRole('list', { name: 'Riwayat uji' });
        const items = within(list).getAllByRole('listitem');
        expect(items).toHaveLength(2);
        expect(items[0].textContent).toContain('Ditutup');
        expect(items[0].textContent).toContain('Sisa tidak dikirim');

        rerender(<DocumentTimeline items={[]} />);
        expect(screen.getByText('Belum ada riwayat.')).toBeTruthy();
    });
});

describe('MoreActionsMenu', () => {
    it('butir ber-href menjadi tautan sungguhan (tab baru untuk cetak)', () => {
        render(
            <MoreActionsMenu
                items={[
                    {
                        key: 'print',
                        label: 'Cetak',
                        icon: Printer,
                        href: '/pembelian/po/1/pdf',
                        newTab: true,
                    },
                ]}
            />,
        );

        fireEvent.keyDown(screen.getByRole('button', { name: 'Aksi lain' }), {
            key: 'Enter',
        });

        const item = screen.getByRole('menuitem', { name: 'Cetak' });
        expect(item.tagName).toBe('A');
        expect(item.getAttribute('href')).toBe('/pembelian/po/1/pdf');
        expect(item.getAttribute('target')).toBe('_blank');
    });
});
