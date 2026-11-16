import { describe, expect, it } from 'vitest';
import { formatDateTimeIn, formatWaiting } from '@/lib/approval-format';

describe('approval-format (T-067)', () => {
    it('waktu UTC ditampilkan di zona kantor, bukan zona perangkat', () => {
        expect(formatDateTimeIn('2026-11-16T03:05:00Z', 'Asia/Jakarta')).toBe(
            '16 Nov 2026 10.05',
        );
        // Lewat tengah malam UTC tetapi masih hari yang sama di WIB.
        expect(formatDateTimeIn('2026-11-16T16:59:00Z', 'Asia/Jakarta')).toBe(
            '16 Nov 2026 23.59',
        );
        expect(formatDateTimeIn('bukan-waktu', 'Asia/Jakarta')).toBe(
            'bukan-waktu',
        );
    });

    it('lama menunggu: jam, hari + jam, ≥ 7 hari cukup hari', () => {
        const now = new Date('2026-11-16T10:00:00Z');
        expect(formatWaiting('2026-11-16T09:30:00Z', now)).toBe('< 1 jam');
        expect(formatWaiting('2026-11-16T05:00:00Z', now)).toBe('5 jam');
        expect(formatWaiting('2026-11-14T07:00:00Z', now)).toBe('2 hari 3 jam');
        expect(formatWaiting('2026-11-15T10:00:00Z', now)).toBe('1 hari');
        expect(formatWaiting('2026-11-06T09:00:00Z', now)).toBe('10 hari');
    });
});
