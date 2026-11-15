import { describe, expect, it } from 'vitest';
import {
    ageOn,
    formatDate,
    formatPhone,
    formatTenure,
    todayIn,
} from '@/lib/employee-format';

describe('formatTenure', () => {
    it('tahun & bulan penuh, hari belum genap tidak dihitung', () => {
        expect(formatTenure('2016-02-01', '2026-11-15')).toBe('10 th 9 bln');
        expect(formatTenure('2026-02-20', '2026-11-15')).toBe('8 bln');
        expect(formatTenure('2025-11-15', '2026-11-15')).toBe('1 th');
        expect(formatTenure('2026-11-01', '2026-11-15')).toBe(
            'Kurang dari sebulan',
        );
        expect(formatTenure('kemarin', '2026-11-15')).toBe('–');
    });
});

describe('ageOn', () => {
    it('umur penuh seperti di KTP', () => {
        expect(ageOn('1990-07-21', '2026-11-15')).toBe(36);
        expect(ageOn('1990-11-16', '2026-11-15')).toBe(35);
        expect(ageOn(null, '2026-11-15')).toBeNull();
    });
});

describe('todayIn', () => {
    it('memakai zona kantor, bukan zona perangkat', () => {
        // 18:00 UTC = 01:00 keesokan harinya di Jakarta.
        const at = new Date(Date.UTC(2026, 10, 15, 18, 0));
        expect(todayIn('Asia/Jakarta', at)).toBe('2026-11-16');
        expect(todayIn('UTC', at)).toBe('2026-11-15');
    });
});

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
