import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import GoodsReceiptForm from '@/pages/goods-receipts/form';
import GoodsReceiptShow from '@/pages/goods-receipts/show';
import type {
    GoodsReceiptDetail,
    GoodsReceiptLine,
} from '@/pages/goods-receipts/show';

const post = vi.fn();

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
        clearErrors: vi.fn(),
        post,
        put: vi.fn(),
    }),
    router: { reload: vi.fn() },
}));

const line = (overrides: Partial<GoodsReceiptLine> = {}): GoodsReceiptLine => ({
    id: 11,
    purchase_order_line_id: 5,
    product_id: 3,
    sku: 'DM-INS-002',
    name: 'Insektisida Demo',
    tracks_batch: true,
    tracks_expiry: true,
    tracks_serial: false,
    requires_registration: true,
    product_unit_id: 9,
    unit: 'Botol',
    base_unit: 'Botol',
    factor: '1',
    qty_received: '36.000',
    qty_received_base: '36.000',
    qty_rejected_base: '0.000',
    reject_reason_id: null,
    reject_reason: null,
    batch_no: 'B-1',
    expiry_date: '2027-03-17',
    registration_no_seen: 'RI 01/2026',
    stock_bin_id: null,
    bin: null,
    shelf_life_months: '4.0',
    line_status: 'short_shelf_life',
    line_status_label: 'Sisa umur pendek',
    stock_batch_id: null,
    batch_status: null,
    serials: [],
    ...overrides,
});

const receipt = (
    overrides: Partial<GoodsReceiptDetail> = {},
): GoodsReceiptDetail => ({
    id: 1,
    number: 'GR-2611-0001',
    purchase_order: { id: 2, number: 'PO-2611-0003', status: 'approved' },
    supplier: { id: 4, code: 'SUP-1', name: 'CV Pemasok Uji' },
    stock_location: { id: 6, code: 'GD-UJI', name: 'Gudang Uji' },
    received_at: '2026-11-17T02:00:00+00:00',
    received_at_local: '2026-11-17T09:00',
    business_date: '2026-11-17',
    supplier_delivery_no: 'SJ-001',
    vehicle_plate: 'K1234AB',
    status: 'checked',
    status_label: 'Diperiksa',
    approval_request_id: null,
    checked_by: 'Slamet',
    checked_at: '2026-11-17T02:10:00+00:00',
    posted_by: null,
    posted_at: null,
    note: null,
    created_by: 'Slamet',
    created_at: '2026-11-17T02:05:00+00:00',
    show_cost: false,
    total_value: null,
    lines: [line()],
    ...overrides,
});

const can = {
    edit: true,
    check: false,
    post: true,
    post_blocked_same_person: false,
    serials: true,
    view_po: false,
};

describe('goods-receipts/show (T-080)', () => {
    it('SEC-107 tanpa izin biaya: kolom biaya & nilai tidak tampil; sisa umur pendek ditandai', () => {
        render(
            <GoodsReceiptShow
                receipt={receipt()}
                history={[]}
                twoPerson
                can={can}
            />,
        );

        expect(screen.getAllByText('GR-2611-0001').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Sisa umur pendek').length).toBeGreaterThan(
            0,
        );
        expect(screen.queryByText('Biaya / satuan dasar')).toBeNull();
        expect(
            screen.getByRole('button', { name: /Posting penerimaan/ }),
        ).toBeTruthy();
    });

    it('SEC-104 pemeriksa yang sama: tombol posting nonaktif dengan penjelasan', () => {
        render(
            <GoodsReceiptShow
                receipt={receipt()}
                history={[]}
                twoPerson
                can={{ ...can, post_blocked_same_person: true }}
            />,
        );

        const button = screen.getByRole('button', {
            name: /Posting penerimaan/,
        }) as HTMLButtonElement;
        expect(button.disabled).toBe(true);
        expect(
            screen.getByText(/Posting harus dilakukan rekan lain/),
        ).toBeTruthy();
    });

    it('BR-144 baris alat: tombol isi nomor seri membuka dialog dan pemindai menambah nomor', () => {
        render(
            <GoodsReceiptShow
                receipt={receipt({
                    status: 'draft',
                    show_cost: true,
                    total_value: '650000.00',
                    lines: [
                        line({
                            tracks_serial: true,
                            tracks_expiry: false,
                            requires_registration: false,
                            batch_no: '-',
                            qty_received: '2.000',
                            qty_received_base: '2.000',
                            line_status: 'ok',
                            unit_cost: '325000.0000',
                            line_value: '650000.00',
                        }),
                    ],
                })}
                history={[]}
                twoPerson
                can={can}
            />,
        );

        expect(
            screen.getAllByText('Biaya / satuan dasar').length,
        ).toBeGreaterThan(0);
        fireEvent.click(
            screen.getAllByRole('button', { name: /Isi nomor seri/ })[0],
        );
        const scan = screen.getByLabelText('Pindai nomor seri');
        fireEvent.change(scan, { target: { value: 'spr 001' } });
        fireEvent.keyDown(scan, { key: 'Enter' });

        expect(screen.getByText('SPR001')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Simpan 1 nomor' }),
        ).toBeTruthy();
    });
});

describe('goods-receipts/form (T-080)', () => {
    const order = {
        id: 2,
        number: 'PO-2611-0003',
        status: 'approved',
        status_label: 'Disetujui',
        open: true,
        supplier: { id: 4, name: 'CV Pemasok Uji' },
        stock_location: { id: 6, name: 'Gudang Uji' },
        lines: [
            {
                id: 5,
                line_no: 1,
                product_id: 3,
                sku: 'DM-INS-002',
                name: 'Insektisida Demo',
                tracks_batch: true,
                tracks_expiry: true,
                tracks_serial: false,
                requires_registration: true,
                product_unit_id: 9,
                unit: 'Dus',
                base_unit: 'Botol',
                factor: '24',
                qty: '2.000',
                qty_base: '48.000',
                qty_received_base: '24.000',
                remaining_base: '24.000',
                unit_cost: null,
            },
        ],
    };

    it('dari PO: baris sisa PO tampil dengan isian batch, ED, izin, dan tanpa isian biaya (SEC-104)', () => {
        render(
            <GoodsReceiptForm
                receipt={null}
                order={order}
                now="2026-11-17T09:00"
                allowWithoutPo={false}
                tolerancePct="0"
                minShelfLifeMonths={6}
                rejectReasons={[{ value: 1, label: 'Rusak / bocor' }]}
                bins={[{ value: 7, label: 'R1-01 · Rak pestisida' }]}
            />,
        );

        expect(screen.getByText('DM-INS-002 · Insektisida Demo')).toBeTruthy();
        expect(screen.getByText(/sisa PO 24 botol/)).toBeTruthy();
        expect(screen.getByLabelText('Nomor batch')).toBeTruthy();
        expect(screen.getByLabelText('No. izin di kemasan')).toBeTruthy();
        expect(screen.queryByLabelText('Biaya per satuan dasar')).toBeNull();
    });

    it('tanpa PO dan setting mati: arahkan ke detail PO', () => {
        render(
            <GoodsReceiptForm
                receipt={null}
                order={null}
                now="2026-11-17T09:00"
                allowWithoutPo={false}
                tolerancePct="0"
                minShelfLifeMonths={6}
                rejectReasons={[]}
                bins={[]}
            />,
        );

        expect(screen.getByText(/selalu dimulai dari PO/)).toBeTruthy();
    });
});
