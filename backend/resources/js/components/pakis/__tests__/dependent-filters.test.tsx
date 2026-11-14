import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { FilterField } from '@/components/pakis/filter-fields';
import { withDependentsCleared } from '@/lib/listing-query';
import type { FilterSpec, ListingSpec } from '@/types/listing';

/* T-012: filter wilayah berjenjang provinsi → kab/kota → kecamatan. */

const province: FilterSpec = {
    key: 'province',
    label: 'Provinsi',
    type: 'select',
    options_source: 'regions',
    options_params: { level: 'province' },
};
const regency: FilterSpec = {
    key: 'regency',
    label: 'Kab/Kota',
    type: 'select',
    options_source: 'regions',
    options_params: { level: 'regency' },
    depends_on: 'province',
};
const district: FilterSpec = {
    key: 'district',
    label: 'Kecamatan',
    type: 'select',
    options_source: 'regions',
    options_params: { level: 'district' },
    depends_on: 'regency',
};
const spec = { filters: [province, regency, district] } as ListingSpec;

describe('filter berjenjang', () => {
    it('kecamatan nonaktif sampai kab/kota dipilih', () => {
        const { rerender } = render(
            <FilterField
                filter={district}
                value={undefined}
                onChange={() => {}}
                parent={{ value: undefined, label: 'Kab/Kota' }}
            />,
        );

        const input = screen.getByRole('combobox');
        expect((input as HTMLInputElement).disabled).toBe(true);
        expect(input.getAttribute('placeholder')).toBe('Pilih kab/kota dulu');

        rerender(
            <FilterField
                filter={district}
                value={undefined}
                onChange={() => {}}
                parent={{ value: '33.19', label: 'Kab/Kota' }}
            />,
        );
        expect(
            (screen.getByRole('combobox') as HTMLInputElement).disabled,
        ).toBe(false);
    });

    it('ganti provinsi mengosongkan kab/kota DAN kecamatan (berantai)', () => {
        const draft = {
            province: '33',
            regency: '33.19',
            district: '33.19.04',
        };

        expect(withDependentsCleared(spec, draft, 'province', '34')).toEqual({
            province: '34',
        });
        expect(withDependentsCleared(spec, draft, 'regency', '33.20')).toEqual({
            province: '33',
            regency: '33.20',
        });
    });

    it('memilih nilai yang sama tidak mengosongkan anak', () => {
        const draft = { province: '33', regency: '33.19' };

        expect(withDependentsCleared(spec, draft, 'province', '33')).toEqual(
            draft,
        );
    });

    it('menghapus induk ikut menghapus anak', () => {
        const draft = {
            province: '33',
            regency: '33.19',
            district: '33.19.04',
        };

        expect(withDependentsCleared(spec, draft, 'province', null)).toEqual(
            {},
        );
    });
});
