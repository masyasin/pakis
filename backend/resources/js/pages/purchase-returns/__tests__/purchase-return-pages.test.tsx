import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import PurchaseReturnForm from '@/pages/purchase-returns/form';
import PurchaseReturnShow from '@/pages/purchase-returns/show';
import type {
    PurchaseReturnDetail,
    PurchaseReturnLine,
} from '@/pages/purchase-returns/show';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { timezone: 'Asia/Jakarta' } }),
    useForm: <T extends object>(initial: T) => ({
        data: initial,
        setData: vi.fn(),
        errors: {},
        processing: false,
        transform: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    }),
    router: { reload: vi.fn() },
}));

const line = (o: Partial<PurchaseReturnLine> = {}): PurchaseReturnLine => ({
    id: 21,
    stock_batch_id: 5,
    product_id: 3,
    sku: 'DM-INS-002',
    name: 'Insektisida Demo',
    tracks_serial: false,
    base_unit: 'Botol',
    batch_no: 'Q-1',
    expiry_date: '2027-01-31',
    batch_status: 'quarantine',
    bin: 'Q-01',
    qty_base: '4.000',
    available_base: '4.000',
    reservation_status: 'active',
    serial_options: [],
    ...o,
});

const detail = (
    o: Partial<PurchaseReturnDetail> = {},
): PurchaseReturnDetail => ({
    id: 1,
    number: 'RTB-2611-0001',
    supplier: { id: 4, code: 'SUP-1', name: 'PT Prinsipal Uji' },
    stock_location: { id: 6, code: 'GD-UJI', name: 'Gudang Uji' },
    goods_receipt: null,
    reason: { id: 9, name: 'Rusak' },
    status: 'approved',
    status_label: 'Disetujui',
    approval_request_id: 7,
    shipped_at: null,
    closed_at: null,
    note: null,
    created_by: 'Dwi',
    created_at: '2026-11-17T02:00:00+00:00',
    show_cost: false,
    total_cost: null,
    lines: [line()],
    ...o,
});

const can = {
    edit: false,
    submit: false,
    ship: true,
    close: false,
    cancel: true,
    view_receipt: false,
};

describe('purchase-returns/show (T-081)', () => {
    it('SEC-107 tanpa izin biaya: kolom biaya tidak tampil; batch karantina ditandai; aksi Kirim tersedia', () => {
        render(
            <PurchaseReturnShow
                purchaseReturn={detail()}
                history={[]}
                can={can}
            />,
        );

        expect(screen.getAllByText('RTB-2611-0001').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Karantina').length).toBeGreaterThan(0);
        expect(screen.queryByText('Biaya batch')).toBeNull();
        expect(
            screen.getByRole('button', { name: /Kirim retur/ }),
        ).toBeTruthy();
    });

    it('PA-68 dialog Kirim produk bernomor seri menampilkan pilihan nomor in_stock', () => {
        render(
            <PurchaseReturnShow
                purchaseReturn={detail({
                    show_cost: true,
                    total_cost: '2000.00',
                    lines: [
                        line({
                            tracks_serial: true,
                            qty_base: '2.000',
                            batch_status: 'available',
                            serial_options: ['SPR-1', 'SPR-2', 'SPR-3'],
                            unit_cost: '1000.0000',
                            line_value: '2000.00',
                        }),
                    ],
                })}
                history={[]}
                can={can}
            />,
        );

        expect(screen.getAllByText('Biaya batch').length).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('button', { name: /Kirim retur/ }));
        expect(screen.getByText(/pilih 2/)).toBeTruthy();
        expect(screen.getByLabelText('SPR-2')).toBeTruthy();
    });
});

describe('purchase-returns/form (T-081)', () => {
    it('dari penerimaan: supplier & lokasi terkunci, baris batch terisi', () => {
        render(
            <PurchaseReturnForm
                return={null}
                reasons={[{ value: 9, label: 'Rusak' }]}
                prefill={{
                    goods_receipt: { id: 3, number: 'GR-2611-0001' },
                    supplier: { id: 4, name: 'PT Prinsipal Uji' },
                    stock_location: { id: 6, name: 'Gudang Uji' },
                    lines: [
                        {
                            product_id: 3,
                            product_label: 'DM-INS-002 · Insektisida Demo',
                            stock_batch_id: 5,
                            batch_no: 'DM-B1',
                            expiry_date: '2028-01-31',
                            status: 'available',
                            bin: 'R1-01',
                            available_base: '24.000',
                            unit: 'Botol',
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText(/Dari penerimaan GR-2611-0001/)).toBeTruthy();
        expect(screen.getByPlaceholderText('maks 24')).toBeTruthy();
    });
});
