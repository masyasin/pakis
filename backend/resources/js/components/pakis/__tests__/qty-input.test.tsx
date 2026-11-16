import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { QtyInput } from '@/components/pakis/qty-input';
import type { QtyUnit } from '@/components/pakis/qty-input';

const UNITS: QtyUnit[] = [
    { id: 1, label: 'Botol', factor: '1', integerOnly: true },
    { id: 2, label: 'Dus', factor: '24', integerOnly: true },
    { id: 3, label: 'Liter', factor: '1', integerOnly: false },
];

function renderQty(value: string, unitId: number, onChange = vi.fn()) {
    render(
        <QtyInput
            label="Jumlah"
            value={value}
            onChange={onChange}
            units={UNITS}
            unitId={unitId}
            onUnitChange={vi.fn()}
            baseUnitLabel="Botol"
        />,
    );

    return onChange;
}

describe('QtyInput (15 §7, BR-150)', () => {
    it('menampilkan konversi ke satuan dasar: 10 dus = 240 botol', () => {
        renderQty('10', 2);
        expect(screen.getByText('= 240 botol')).toBeTruthy();
    });

    it('satuan dasar tidak menampilkan konversi', () => {
        renderQty('5', 1);
        expect(screen.queryByText(/^=/)).toBeNull();
    });

    it('memperingatkan pecahan pada satuan integer_only', () => {
        renderQty('1,5', 2);
        expect(
            screen.getByText('Satuan dus hanya bilangan bulat.'),
        ).toBeTruthy();
        expect(
            screen.getByLabelText('Jumlah').getAttribute('aria-invalid'),
        ).toBe('true');
    });

    it('meneruskan ketikan apa adanya', () => {
        const onChange = renderQty('', 2);
        fireEvent.change(screen.getByLabelText('Jumlah'), {
            target: { value: '2,5' },
        });
        expect(onChange).toHaveBeenCalledWith('2,5');
    });
});
