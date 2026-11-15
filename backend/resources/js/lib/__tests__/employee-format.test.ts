import { describe, expect, it } from 'vitest';
import { formatDate, formatPhone } from '@/lib/employee-format';

describe('formatDate', () => {
    it('menampilkan tanggal sipil tanpa bergeser zona', () => {
        expect(formatDate('2026-11-03')).toBe('3 Nov 2026');
        expect(formatDate('2026-01-31')).toBe('31 Jan 2026');
    });

    it('null → tanda hubung, nilai tidak sah apa adanya', () => {
        expect(formatDate(null)).toBe('–');
        expect(formatDate('kemarin')).toBe('kemarin');
    });
});

describe('formatPhone', () => {
    it('62… tersimpan → 08… terkelompok', () => {
        expect(formatPhone('6281234567890')).toBe('0812-3456-7890');
        expect(formatPhone('62291123456')).toBe('0291-1234-56');
    });

    it('kosong → tanda hubung', () => {
        expect(formatPhone(null)).toBe('–');
        expect(formatPhone('')).toBe('–');
    });
});
