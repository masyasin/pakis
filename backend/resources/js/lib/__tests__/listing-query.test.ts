import { describe, expect, it } from 'vitest';
import {
    countActiveFilters,
    defaultFilters,
    filterChips,
    formatDate,
    nextSort,
    toQueryParams,
} from '@/lib/listing-query';
import type { ListingSpec } from '@/types/listing';

const spec: ListingSpec = {
    key: 'uji',
    search_placeholder: 'Cari',
    per_page_options: [10, 25, 50, 100],
    default_sort: 'name',
    sorts: [
        { key: 'name', label: 'Nama' },
        { key: 'created_at', label: 'Dibuat' },
    ],
    filters: [
        {
            key: 'status',
            type: 'select',
            label: 'Status',
            options: [
                { value: 'active', label: 'Aktif' },
                { value: 'inactive', label: 'Nonaktif' },
            ],
            default: 'active',
        },
        {
            key: 'tags',
            type: 'multi_select',
            label: 'Label',
            options: [
                { value: 'a', label: 'Alfa' },
                { value: 'b', label: 'Beta' },
            ],
        },
        { key: 'verified', type: 'boolean', label: 'Terverifikasi' },
        { key: 'date', type: 'date_range', label: 'Tanggal' },
        { key: 'level', type: 'number_range', label: 'Level' },
        {
            key: 'location_id',
            type: 'select',
            label: 'Lokasi',
            options_source: 'locations',
        },
    ],
    exportable: false,
};

const base = { q: '', sort: 'name', filters: {}, page: 1, perPage: 25 };

describe('toQueryParams', () => {
    it('tidak menulis sort default dan halaman 1', () => {
        expect(toQueryParams(spec, base)).toEqual({
            per_page: 25,
            filter: { status: '' },
        });
    });

    it('menulis q, sort, page, dan filter terisi', () => {
        expect(
            toQueryParams(spec, {
                q: ' agus ',
                sort: '-created_at',
                page: 3,
                perPage: 50,
                filters: {
                    status: 'inactive',
                    tags: ['a', 'b'],
                    verified: false,
                    date: { from: '2026-11-01', to: '' },
                    level: { min: 2 },
                },
            }),
        ).toEqual({
            q: 'agus',
            sort: '-created_at',
            page: 3,
            per_page: 50,
            filter: {
                status: 'inactive',
                tags: ['a', 'b'],
                verified: '0',
                date: { from: '2026-11-01' },
                level: { min: 2 },
            },
        });
    });

    it('BR-003 filter ber-default yang dihapus dikirim kosong agar default tidak diterapkan lagi', () => {
        expect(toQueryParams(spec, base).filter).toEqual({ status: '' });
    });

    it('filter tanpa default yang kosong tidak ditulis', () => {
        const params = toQueryParams(spec, {
            ...base,
            filters: { status: 'active', tags: [] },
        });

        expect(params.filter).toEqual({ status: 'active' });
    });
});

describe('chip & bantuan', () => {
    it('label chip dalam Bahasa Indonesia per tipe', () => {
        const chips = filterChips(spec, {
            status: 'active',
            tags: ['a', 'b'],
            verified: true,
            date: { from: '2026-11-01', to: '2026-11-30' },
            level: { min: 1, max: 1250 },
            location_id: 7,
        });

        expect(chips.map((c) => c.label)).toEqual([
            'Status: Aktif',
            'Label: Alfa, Beta',
            'Terverifikasi: Ya',
            'Tanggal: 1 Nov 2026 – 30 Nov 2026',
            'Level: 1–1.250',
            'Lokasi: #7',
        ]);
    });

    it('tanggal format 07 §7', () => {
        expect(formatDate('2026-11-03')).toBe('3 Nov 2026');
    });

    it('menghitung filter aktif', () => {
        expect(countActiveFilters({ a: 'x', b: [], c: { from: '' } })).toBe(1);
    });

    it('nilai default dari spec untuk Reset', () => {
        expect(defaultFilters(spec)).toEqual({ status: 'active' });
    });

    it('klik header sort membalik arah', () => {
        expect(nextSort('name', 'name')).toBe('-name');
        expect(nextSort('-name', 'name')).toBe('name');
        expect(nextSort('name', 'created_at')).toBe('created_at');
    });
});
