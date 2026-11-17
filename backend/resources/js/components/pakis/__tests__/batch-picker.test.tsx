import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BatchPicker } from '@/components/pakis/batch-picker';
import type { BatchOption } from '@/components/pakis/batch-picker';
import { expiryState } from '@/components/pakis/status-badge';
import { clearLookupCache, LOOKUP_DEBOUNCE_MS } from '@/lib/lookup';

const BATCHES: BatchOption[] = [
    {
        value: 11,
        label: 'B-001',
        hint: 'ED 20 Des 2026 · tersedia 12,5 Botol',
        batch_no: 'B-001',
        expiry_date: '2026-12-20',
        days_to_expiry: 33,
        expiry_state: 'near',
        available_base: '12.500',
        unit: 'Botol',
        bin: 'A-01',
        fefo_rank: 1,
    },
    {
        value: 12,
        label: 'B-002',
        hint: 'ED 1 Jun 2027 · tersedia 10 Botol',
        batch_no: 'B-002',
        expiry_date: '2027-06-01',
        days_to_expiry: 196,
        expiry_state: 'ok',
        available_base: '10.000',
        unit: 'Botol',
        bin: null,
        fefo_rank: 2,
    },
];

const fetchMock = vi.fn();

beforeEach(() => {
    vi.useFakeTimers();
    clearLookupCache();
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve(BATCHES),
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

async function openList() {
    fireEvent.focus(screen.getByRole('combobox', { name: 'Batch' }));
    await act(async () => {
        vi.advanceTimersByTime(LOOKUP_DEBOUNCE_MS);
        await Promise.resolve();
        await Promise.resolve();
    });
}

describe('BatchPicker (T-078, BR-102, BR-156)', () => {
    it('memuat lookup available_batches dengan produk + lokasi stok, urut FEFO dengan ED & qty', async () => {
        render(
            <BatchPicker
                productId={5}
                stockLocationId={7}
                value={null}
                onChange={() => {}}
            />,
        );
        await openList();

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/lookup/available_batches?product_id=5&stock_location_id=7',
        );
        const options = screen.getAllByRole('option');
        expect(options[0].textContent).toContain('B-001');
        expect(options[0].textContent).toContain('FEFO');
        expect(options[0].textContent).toContain('12,5 Botol');
        expect(options[0].textContent).toContain('ED 33 hari lagi');
        expect(
            options[0]
                .querySelector('[data-status="expiry.near"]')
                ?.getAttribute('data-status'),
        ).toBe('expiry.near');
        expect(options[1].textContent).not.toContain('FEFO');
    });

    it('memilih batch FEFO pertama langsung tersimpan tanpa konfirmasi (fefoOverride=false)', async () => {
        const onChange = vi.fn();
        render(
            <BatchPicker
                productId={5}
                stockLocationId={7}
                value={null}
                onChange={onChange}
            />,
        );
        await openList();
        fireEvent.mouseDown(screen.getAllByRole('option')[0]);

        expect(onChange).toHaveBeenCalledWith({
            batch: BATCHES[0],
            fefoOverride: false,
        });
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('memilih bukan batch FEFO pertama → peringatan; Batal tidak mengubah, Tetap pilih → fefoOverride=true', async () => {
        const onChange = vi.fn();
        render(
            <BatchPicker
                productId={5}
                stockLocationId={7}
                value={null}
                onChange={onChange}
            />,
        );
        await openList();
        fireEvent.mouseDown(screen.getAllByRole('option')[1]);

        expect(screen.getByRole('dialog').textContent).toContain('urutan ke-2');
        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(onChange).not.toHaveBeenCalled();

        await openList();
        fireEvent.mouseDown(screen.getAllByRole('option')[1]);
        fireEvent.click(screen.getByRole('button', { name: 'Tetap pilih' }));
        expect(onChange).toHaveBeenCalledWith({
            batch: BATCHES[1],
            fefoOverride: true,
        });
    });

    it('menampilkan penanda pengecualian FEFO untuk nilai terpilih dan nonaktif tanpa produk/lokasi', () => {
        const { rerender } = render(
            <BatchPicker
                productId={5}
                stockLocationId={7}
                value={{ batch: BATCHES[1], fefoOverride: true }}
                onChange={() => {}}
            />,
        );
        expect(screen.getByRole('status').textContent).toBe(
            'Bukan batch FEFO pertama',
        );

        rerender(
            <BatchPicker
                productId={null}
                stockLocationId={7}
                value={null}
                onChange={() => {}}
            />,
        );
        expect(
            (
                screen.getByRole('combobox', {
                    name: 'Batch',
                }) as HTMLInputElement
            ).disabled,
        ).toBe(true);
    });

    it('expiryState: merah < 0, oranye ≤ ambang terbesar, abu lainnya', () => {
        expect(expiryState(-1, 90)).toBe('expired');
        expect(expiryState(0, 90)).toBe('near');
        expect(expiryState(90, 90)).toBe('near');
        expect(expiryState(91, 90)).toBe('ok');
        expect(expiryState(null, 90)).toBe('none');
    });
});
