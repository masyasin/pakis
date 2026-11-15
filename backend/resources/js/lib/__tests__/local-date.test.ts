import { describe, expect, it } from 'vitest';
import {
    civilDayCount,
    formatCivilDate,
    formatCivilRange,
    formatLongDate,
} from '@/lib/local-date';

describe('formatLongDate', () => {
    // 14 Nov 2026 23.30 UTC = 15 Nov 2026 06.30 WIB.
    const moment = new Date('2026-11-14T23:30:00Z');

    it('memakai zona tampilan, bukan zona perangkat', () => {
        expect(formatLongDate(moment, 'Asia/Jakarta')).toBe(
            'Minggu 15 November 2026',
        );
        expect(formatLongDate(moment, 'UTC')).toBe('Sabtu 14 November 2026');
    });

    it('zona WITA/WIT ikut bergeser', () => {
        expect(formatLongDate(moment, 'Asia/Jayapura')).toBe(
            'Minggu 15 November 2026',
        );
    });
});

describe('formatCivilDate', () => {
    it('tanggal sipil tidak bergeser zona (hari libur, work_date)', () => {
        expect(formatCivilDate('2026-08-17')).toBe('Sen, 17 Agu 2026');
        expect(formatCivilDate('2027-01-01')).toBe('Jum, 1 Jan 2027');
    });

    it('nilai tidak sah dikembalikan apa adanya', () => {
        expect(formatCivilDate('17-08-2026')).toBe('17-08-2026');
    });
});

describe('formatCivilRange', () => {
    it('satu hari memakai nama hari', () => {
        expect(formatCivilRange('2026-08-17', '2026-08-17')).toBe(
            'Sen, 17 Agu 2026',
        );
    });

    it('rentang setahun menulis tahun sekali; lintas tahun dua kali', () => {
        expect(formatCivilRange('2026-03-30', '2026-04-03')).toBe(
            '30 Mar – 3 Apr 2026',
        );
        expect(formatCivilRange('2026-12-31', '2027-01-02')).toBe(
            '31 Des 2026 – 2 Jan 2027',
        );
    });
});

describe('civilDayCount', () => {
    it('inklusif, termasuk lintas bulan & tahun kabisat', () => {
        expect(civilDayCount('2026-03-30', '2026-04-03')).toBe(5);
        expect(civilDayCount('2028-02-28', '2028-03-01')).toBe(3);
        expect(civilDayCount('2026-08-17', '2026-08-17')).toBe(1);
    });

    it('null bila selesai sebelum mulai atau tanggal belum lengkap', () => {
        expect(civilDayCount('2026-04-03', '2026-03-30')).toBeNull();
        expect(civilDayCount('', '2026-03-30')).toBeNull();
    });
});
