import { describe, expect, it } from 'vitest';
import { poLineTotal, poTotals } from '../po-totals';

describe('poLineTotal (BR-160, sama dengan PurchaseOrderTotals)', () => {
    it('qty × harga tanpa diskon', () => {
        expect(
            poLineTotal({ qty: '10', unitPrice: '12500', discountPct: '' }),
        ).toBe('125000.00');
    });

    it('diskon persen dibulatkan half-up ke sen', () => {
        // 3 × 33.333,33 × 97,5% = 97.499,99… → 97.499,99 (half-up dari ,9902)
        expect(
            poLineTotal({
                qty: '3',
                unitPrice: '33333.33',
                discountPct: '2.5',
            }),
        ).toBe('97499.99');
        // 1 × 0,05 × 50% = 0,025 → 0,03
        expect(
            poLineTotal({ qty: '1', unitPrice: '0.05', discountPct: '50' }),
        ).toBe('0.03');
    });

    it('qty desimal dan nominal besar tanpa float', () => {
        expect(
            poLineTotal({
                qty: '2.5',
                unitPrice: '9999999999999.99',
                discountPct: '0',
            }),
        ).toBe('24999999999999.98');
    });

    it('isian belum sah → null', () => {
        expect(
            poLineTotal({ qty: '', unitPrice: '100', discountPct: '0' }),
        ).toBeNull();
        expect(
            poLineTotal({ qty: '1', unitPrice: '100', discountPct: '100' }),
        ).toBeNull();
        expect(
            poLineTotal({ qty: '1.2345', unitPrice: '100', discountPct: '0' }),
        ).toBeNull();
    });
});

describe('poTotals', () => {
    it('subtotal bruto, diskon = selisih, baris kosong dilewati', () => {
        expect(
            poTotals([
                { qty: '10', unitPrice: '12500', discountPct: '10' },
                { qty: '2', unitPrice: '50000', discountPct: '' },
                { qty: '', unitPrice: '', discountPct: '' },
            ]),
        ).toEqual({
            subtotal: '225000.00',
            discountTotal: '12500.00',
            grandTotal: '212500.00',
        });
    });
});
