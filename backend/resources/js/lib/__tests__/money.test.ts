import { describe, expect, it } from 'vitest';
import { formatRupiah, moneyInputValue } from '@/lib/money';

describe('formatRupiah (ADR-101, tanpa float)', () => {
    it('memisahkan ribuan dengan titik dan membuang ,00', () => {
        expect(formatRupiah('12500.00')).toBe('Rp12.500');
        expect(formatRupiah('1234567.00')).toBe('Rp1.234.567');
        expect(formatRupiah('0.00')).toBe('Rp0');
    });

    it('menampilkan sen dengan koma bila ada', () => {
        expect(formatRupiah('9999.50')).toBe('Rp9.999,50');
        expect(formatRupiah('10.5')).toBe('Rp10,50');
    });

    it('nilai kosong dan nilai rusak tidak meledak', () => {
        expect(formatRupiah(null)).toBe('–');
        expect(formatRupiah('abc')).toBe('abc');
    });

    it('nilai input tanpa awalan Rp', () => {
        expect(moneyInputValue('12500.00')).toBe('12.500');
    });
});
