import { describe, expect, it } from 'vitest';
import { formatRelativeTime } from '@/lib/relative-time';

const now = new Date('2026-11-15T03:00:00Z');

describe('formatRelativeTime', () => {
    it('di bawah semenit → baru saja', () => {
        expect(formatRelativeTime('2026-11-15T02:59:30Z', now)).toBe(
            'baru saja',
        );
    });

    it('menit dan jam memakai bahasa Indonesia', () => {
        expect(formatRelativeTime('2026-11-15T02:55:00Z', now)).toBe(
            '5 menit yang lalu',
        );
        expect(formatRelativeTime('2026-11-15T01:00:00Z', now)).toBe(
            '2 jam yang lalu',
        );
    });

    it('lebih dari seminggu → tanggal & jam', () => {
        expect(formatRelativeTime('2026-11-01T00:00:00Z', now)).toMatch(
            /Nov 2026/,
        );
    });

    it('nilai tidak valid dikembalikan apa adanya', () => {
        expect(formatRelativeTime('bukan-tanggal', now)).toBe('bukan-tanggal');
    });
});
