import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { useIsMobile } from '@/hooks/use-mobile';
import { SEARCH_DEBOUNCE_MS } from '@/hooks/use-listing';
import type { Listing } from '@/types/listing';

// Fungsi mock terpisah (bukan method objek): menghindari unbound-method & mudah diperiksa.
const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { get: routerGet, visit: vi.fn() },
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: vi.fn(() => false),
}));

type Row = { id: number; name: string; status: string };

const columns: DataTableColumn<Row>[] = [
    {
        key: 'name',
        header: 'Nama',
        sortKey: 'name',
        primary: true,
        cell: (r) => r.name,
    },
    { key: 'status', header: 'Status', cell: (r) => r.status },
];

function makeListing(
    overrides: Partial<Listing['state']> = {},
    page = 1,
): Listing {
    return {
        pagination: {
            page,
            per_page: 25,
            total: 60,
            last_page: 3,
            from: 1,
            to: 25,
        },
        state: {
            q: '',
            sort: 'name',
            filters: { status: 'active' },
            invalid_filters: {},
            ...overrides,
        },
        spec: {
            key: 'uji',
            search_placeholder: 'Cari nama',
            per_page_options: [10, 25, 50, 100],
            default_sort: 'name',
            sorts: [{ key: 'name', label: 'Nama' }],
            filters: [
                {
                    key: 'status',
                    type: 'select',
                    label: 'Status',
                    default: 'active',
                    options: [
                        { value: 'active', label: 'Aktif' },
                        { value: 'inactive', label: 'Nonaktif' },
                    ],
                },
            ],
            exportable: false,
        },
    };
}

const rows: Row[] = [
    { id: 1, name: 'Agus', status: 'active' },
    { id: 2, name: 'Budi', status: 'active' },
];

/** Parameter URL dari pemanggilan router.get terakhir. */
function lastParams(): Record<string, unknown> {
    const calls = routerGet.mock.calls;

    return calls[calls.length - 1][1] as Record<string, unknown>;
}

describe('DataTable', () => {
    beforeEach(() => {
        routerGet.mockClear();
        vi.mocked(useIsMobile).mockReturnValue(false);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('pencarian dikirim setelah debounce, mengganti riwayat, dan kembali ke halaman 1', async () => {
        vi.useFakeTimers();
        render(
            <DataTable
                rows={rows}
                listing={makeListing({}, 3)}
                columns={columns}
                title="Uji"
            />,
        );

        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'agus' },
        });
        expect(routerGet).not.toHaveBeenCalled();

        await act(async () => {
            vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS);
        });

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(lastParams()).toMatchObject({ q: 'agus' });
        expect(lastParams()).not.toHaveProperty('page');
        expect(routerGet.mock.calls[0][2]).toMatchObject({
            replace: true,
            preserveState: true,
        });
    });

    it('pencarian 1 karakter tidak dikirim (BR-002)', async () => {
        vi.useFakeTimers();
        render(
            <DataTable
                rows={rows}
                listing={makeListing()}
                columns={columns}
                title="Uji"
            />,
        );

        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'a' },
        });
        await act(async () => {
            vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS * 2);
        });

        expect(routerGet).not.toHaveBeenCalled();
    });

    it('filter dari panel diterapkan ke URL dan kembali ke halaman 1', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing({}, 3)}
                columns={columns}
                title="Uji"
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Filter/ }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Status'), {
            target: { value: 'inactive' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Terapkan' }),
        );

        expect(lastParams()).toMatchObject({ filter: { status: 'inactive' } });
        expect(lastParams()).not.toHaveProperty('page');
        // Filter/sort boleh di-"Back": tidak mengganti entri riwayat.
        expect(routerGet.mock.calls[0][2]).toMatchObject({
            replace: false,
        });
    });

    it('klik header sort membalik arah dan aria-sort mengikuti state', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing()}
                columns={columns}
                title="Uji"
            />,
        );

        const header = screen.getByRole('columnheader', { name: /Nama/ });
        expect(header.getAttribute('aria-sort')).toBe('ascending');

        fireEvent.click(within(header).getByRole('button'));
        expect(lastParams()).toMatchObject({ sort: '-name' });
    });

    it('kolom tanpa sortKey tidak bisa di-sort', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing()}
                columns={columns}
                title="Uji"
            />,
        );

        const header = screen.getByRole('columnheader', { name: 'Status' });
        expect(header.hasAttribute('aria-sort')).toBe(false);
        expect(within(header).queryByRole('button')).toBeNull();
    });

    it('BR-003 menghapus chip filter default mengirim nilai kosong', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing()}
                columns={columns}
                title="Uji"
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Hapus filter Status: Aktif' }),
        );

        expect(lastParams()).toMatchObject({ filter: { status: '' } });
    });

    it('ganti per halaman mengirim per_page dan kembali ke halaman 1', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing({}, 2)}
                columns={columns}
                title="Uji"
            />,
        );

        fireEvent.change(screen.getByLabelText('per halaman'), {
            target: { value: '50' },
        });

        expect(lastParams()).toMatchObject({ per_page: 50 });
        expect(lastParams()).not.toHaveProperty('page');
    });

    it('pindah halaman lewat paging', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing()}
                columns={columns}
                title="Uji"
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Halaman berikutnya' }),
        );

        expect(lastParams()).toMatchObject({ page: 2 });
    });

    it('empty state "Belum ada data" saat tanpa pencarian & filter di luar default', () => {
        render(
            <DataTable
                rows={[]}
                listing={{
                    ...makeListing(),
                    pagination: {
                        page: 1,
                        per_page: 25,
                        total: 0,
                        last_page: 1,
                        from: null,
                        to: null,
                    },
                }}
                columns={columns}
                title="Uji"
            />,
        );

        expect(screen.getByText('Belum ada data')).toBeTruthy();
        expect(
            screen.queryByText('Tidak ada hasil untuk filter ini'),
        ).toBeNull();
    });

    it('empty state "Tidak ada hasil" saat ada pencarian, dengan tombol reset', () => {
        render(
            <DataTable
                rows={[]}
                listing={{
                    ...makeListing({ q: 'zz' }),
                    pagination: {
                        page: 1,
                        per_page: 25,
                        total: 0,
                        last_page: 1,
                        from: null,
                        to: null,
                    },
                }}
                columns={columns}
                title="Uji"
            />,
        );

        expect(
            screen.getByText('Tidak ada hasil untuk filter ini'),
        ).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Reset filter' }));
        expect(lastParams()).toMatchObject({ filter: { status: 'active' } });
        expect(lastParams()).not.toHaveProperty('q');
    });

    it('ringkasan jumlah memakai format Indonesia dan diumumkan aria-live', () => {
        render(
            <DataTable
                rows={rows}
                listing={{
                    ...makeListing(),
                    pagination: {
                        page: 2,
                        per_page: 25,
                        total: 1250,
                        last_page: 50,
                        from: 26,
                        to: 50,
                    },
                }}
                columns={columns}
                title="Uji"
            />,
        );

        const summary = screen.getByTestId('listing-summary');
        expect(summary.textContent).toContain('Menampilkan 26–50 dari 1.250');
        expect(summary.getAttribute('aria-live')).toBe('polite');
    });

    it('menampilkan peringatan untuk filter tidak valid', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing({
                    invalid_filters: { status: 'Pilihan tidak dikenal.' },
                })}
                columns={columns}
                title="Uji"
            />,
        );

        expect(screen.getByRole('status').textContent).toContain(
            'Status (Pilihan tidak dikenal.)',
        );
    });

    it('layar < 768 px memakai daftar kartu, bukan tabel', () => {
        vi.mocked(useIsMobile).mockReturnValue(true);
        render(
            <DataTable
                rows={rows}
                listing={makeListing()}
                columns={columns}
                title="Uji"
            />,
        );

        expect(screen.getByTestId('card-list')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });
    it('renderItem: kartu di semua lebar layar (kotak masuk persetujuan)', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing()}
                columns={columns}
                title="Uji"
                renderItem={(r) => <p>Kartu {r.name}</p>}
            />,
        );

        expect(screen.getByTestId('item-list')).toBeTruthy();
        expect(screen.getByText('Kartu Agus')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });

    it('hiddenFilters: filter halaman tidak jadi chip, dan tetap dipertahankan saat reset', () => {
        render(
            <DataTable
                rows={rows}
                listing={makeListing({
                    q: 'agus',
                    filters: { status: 'inactive' },
                })}
                columns={columns}
                title="Uji"
                hiddenFilters={['status']}
            />,
        );

        expect(screen.queryByText(/Nonaktif/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Reset' }));
        expect(lastParams()).not.toHaveProperty('q');
        expect(lastParams()).toMatchObject({
            filter: { status: 'inactive' },
        });
    });
});
