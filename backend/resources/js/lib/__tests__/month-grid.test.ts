import { describe, expect, it } from 'vitest';
import { monthGrid } from '@/lib/month-grid';

describe('monthGrid', () => {
    it('November 2026: mulai Minggu → 6 sel kosong di depan (minggu mulai Senin)', () => {
        const weeks = monthGrid('2026-11');
        expect(weeks[0].slice(0, 6)).toEqual([
            null,
            null,
            null,
            null,
            null,
            null,
        ]);
        expect(weeks[0][6]).toEqual({ date: '2026-11-01', day: 1 });
        expect(weeks.flat().filter(Boolean)).toHaveLength(30);
        expect(weeks.every((w) => w.length === 7)).toBe(true);
    });

    it('Februari 2027 (28 hari, mulai Senin) = tepat 4 minggu', () => {
        const weeks = monthGrid('2027-02');
        expect(weeks).toHaveLength(4);
        expect(weeks[0][0]).toEqual({ date: '2027-02-01', day: 1 });
        expect(weeks[3][6]).toEqual({ date: '2027-02-28', day: 28 });
    });

    it('tahun kabisat: Februari 2028 punya 29 hari', () => {
        expect(monthGrid('2028-02').flat().filter(Boolean)).toHaveLength(29);
    });
});
