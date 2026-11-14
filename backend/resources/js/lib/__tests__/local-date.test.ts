import { describe, expect, it } from 'vitest';
import { formatLongDate } from '@/lib/local-date';

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
