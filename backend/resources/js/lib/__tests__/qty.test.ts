import { describe, expect, it } from 'vitest';
import { formatQty, isWholeQty, parseQtyInput, toBaseQty } from '@/lib/qty';

describe('qty (ADR-101, BR-150) — sama dengan Qty/UnitConverter di server', () => {
    it('membaca ketikan id-ID', () => {
        expect(parseQtyInput('1,5')).toBe('1.5');
        expect(parseQtyInput('1.234,5')).toBe('1234.5');
        expect(parseQtyInput('1.500')).toBe('1500');
        expect(parseQtyInput('2.5')).toBe('2.5');
        expect(parseQtyInput(' 12 ')).toBe('12');
        expect(parseQtyInput('dua')).toBeNull();
        expect(parseQtyInput('')).toBeNull();
    });

    it('qty × faktor ke satuan dasar, 3 desimal half-up, tanpa float', () => {
        expect(toBaseQty('2.5', '24')).toBe('60.000');
        expect(toBaseQty('10', '24')).toBe('240.000');
        expect(toBaseQty('1.333', '1.5')).toBe('2.000');
        expect(toBaseQty('0.1', '3')).toBe('0.300');
        // 0.1 + 0.2 klasik: aritmetika float akan memberi 0.30000000000000004.
        expect(toBaseQty('0.3', '1')).toBe('0.300');
    });

    it('bilangan bulat untuk satuan integer_only', () => {
        expect(isWholeQty('3')).toBe(true);
        expect(isWholeQty('3.000')).toBe(true);
        expect(isWholeQty('1.5')).toBe(false);
    });

    it('format tampilan id-ID', () => {
        expect(formatQty('240.000')).toBe('240');
        expect(formatQty('1234.500')).toBe('1.234,5');
        expect(formatQty('-0.250')).toBe('-0,25');
    });
});
