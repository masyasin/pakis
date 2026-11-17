import { describe, expect, it } from 'vitest';
import {
    duplicateRequestBody,
    plausiblePhone,
} from '@/components/customer/duplicate-check';

const base = {
    store_name: 'Toko Tani Makmur',
    owner_phone: '',
    latitude: '',
    longitude: '',
    village_code: '',
    customer_id: null,
};

describe('cek duplikat kios (BR-166)', () => {
    it('HP dikirim hanya bila sudah berbentuk nomor Indonesia utuh', () => {
        expect(plausiblePhone('0812 3456 7890')).toBe('6281234567890');
        expect(plausiblePhone('+62 812-3456-7890')).toBe('6281234567890');
        expect(plausiblePhone('0812')).toBeNull();
        expect(plausiblePhone('')).toBeNull();
    });

    it('nama toko < 2 huruf belum diperiksa', () => {
        expect(duplicateRequestBody({ ...base, store_name: ' T ' })).toBeNull();
    });

    it('titik & desa hanya dikirim bila lengkap dan sah', () => {
        expect(
            duplicateRequestBody({
                ...base,
                owner_phone: '0812',
                latitude: '-6.85',
                longitude: '',
                village_code: '33.19',
            }),
        ).toEqual({
            store_name: 'Toko Tani Makmur',
            owner_phone: null,
            latitude: null,
            longitude: null,
            village_code: null,
            customer_id: null,
        });

        expect(
            duplicateRequestBody({
                ...base,
                latitude: '-6.85',
                longitude: '110.84',
                village_code: '33.19.01.2001',
                customer_id: 7,
            }),
        ).toMatchObject({
            latitude: '-6.85',
            longitude: '110.84',
            village_code: '33.19.01.2001',
            customer_id: 7,
        });
    });
});
