import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AsyncSelect } from '@/components/pakis/async-select';
import { filterChips } from '@/lib/listing-query';
import {
    clearLookupCache,
    LOOKUP_DEBOUNCE_MS,
    rememberLabels,
} from '@/lib/lookup';
import type { ListingSpec } from '@/types/listing';

const USERS = [
    { value: 1, label: 'Demo Admin', hint: 'demo@pakis-demo.test' },
    { value: 2, label: 'Siti Aminah', hint: 'siti@pakis-demo.test' },
];

const fetchMock = vi.fn();

beforeEach(() => {
    vi.useFakeTimers();
    clearLookupCache();
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve(USERS),
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

/** Lewati debounce lalu tunggu promise fetch selesai. */
async function flush() {
    await act(async () => {
        vi.advanceTimersByTime(LOOKUP_DEBOUNCE_MS);
        await Promise.resolve();
        await Promise.resolve();
    });
}

describe('AsyncSelect (T-011, BR-006)', () => {
    it('membuka daftar saat fokus dan mencari ke /lookup/{sumber}?q=', async () => {
        render(
            <AsyncSelect
                source="users"
                value={[]}
                onChange={() => {}}
                aria-label="Pelaku"
            />,
        );
        const input = screen.getByRole('combobox', { name: 'Pelaku' });

        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: 'siti' } });
        await flush();

        // Debounce: hanya satu permintaan untuk kata kunci terakhir.
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/lookup/users?q=siti');
        expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(
            [
                'Demo Admindemo@pakis-demo.test',
                'Siti Aminahsiti@pakis-demo.test',
            ],
        );
        expect(input.getAttribute('aria-expanded')).toBe('true');
    });

    it('↓ lalu Enter memilih opsi (mode tunggal menutup daftar)', async () => {
        const onChange = vi.fn();
        render(
            <AsyncSelect
                source="users"
                value={[]}
                onChange={onChange}
                aria-label="Pelaku"
            />,
        );
        const input = screen.getByRole('combobox');

        fireEvent.focus(input);
        await flush();
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(onChange).toHaveBeenCalledWith([2]);
        expect(input.getAttribute('aria-expanded')).toBe('false');
    });

    it('mode multi menambah pilihan; chip memakai label & bisa dihapus', async () => {
        const onChange = vi.fn();
        rememberLabels('users', USERS);
        render(
            <AsyncSelect
                source="users"
                multiple
                value={[1]}
                onChange={onChange}
                aria-label="Pelaku"
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Hapus pilihan Demo Admin' }),
        );
        expect(onChange).toHaveBeenLastCalledWith([]);

        const input = screen.getByRole('combobox');
        fireEvent.focus(input);
        await flush();
        fireEvent.mouseDown(
            screen.getByRole('option', { name: /Siti Aminah/ }),
        );
        expect(onChange).toHaveBeenLastCalledWith([1, 2]);
    });

    it('Backspace di isian kosong menghapus pilihan terakhir', () => {
        const onChange = vi.fn();
        render(
            <AsyncSelect
                source="users"
                multiple
                value={[1, 2]}
                onChange={onChange}
                aria-label="Pelaku"
            />,
        );

        fireEvent.keyDown(screen.getByRole('combobox'), { key: 'Backspace' });
        expect(onChange).toHaveBeenCalledWith([1]);
    });

    it('galat server tampil sebagai pesan, bukan daftar kosong diam-diam', async () => {
        fetchMock.mockResolvedValue({
            ok: false,
            status: 403,
            json: () => Promise.resolve({}),
        });
        render(
            <AsyncSelect
                source="users"
                value={[]}
                onChange={() => {}}
                aria-label="Pelaku"
            />,
        );

        fireEvent.focus(screen.getByRole('combobox'));
        await flush();

        expect(
            screen.getByText('Gagal memuat pilihan. Coba lagi.'),
        ).toBeTruthy();
    });

    it('exclude menyembunyikan nilai tertentu dari pilihan (kios sendiri saat gabung, T-074)', async () => {
        render(
            <AsyncSelect
                source="users"
                value={[]}
                exclude={[1]}
                onChange={() => {}}
                aria-label="Tujuan"
            />,
        );
        fireEvent.focus(screen.getByRole('combobox', { name: 'Tujuan' }));
        await flush();

        expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(
            ['Siti Aminahsiti@pakis-demo.test'],
        );
    });
});

describe('chip filter relasi', () => {
    const spec = {
        key: 'audit_logs',
        filters: [
            {
                key: 'user_id',
                label: 'Pelaku',
                type: 'select',
                options_source: 'users',
            },
        ],
    } as unknown as ListingSpec;

    it('memakai label dari cache lookup, ID bila belum dikenal', () => {
        expect(filterChips(spec, { user_id: 1 })[0].label).toBe('Pelaku: #1');

        rememberLabels('users', USERS);
        expect(filterChips(spec, { user_id: 1 })[0].label).toBe(
            'Pelaku: Demo Admin',
        );
    });
});
