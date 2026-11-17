import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    groupRupiah,
    MoneyInput,
    parseRupiah,
    reformatRupiahInput,
} from '@/components/pakis/money-input';

describe('rupiah money-input (ADR-101, tanpa float)', () => {
    it('mengurai teks id-ID ke string kanonik', () => {
        expect(parseRupiah('20.000.000')).toBe('20000000');
        expect(parseRupiah('1.500,5')).toBe('1500.5');
        expect(parseRupiah('Rp 007')).toBe('7');
        expect(parseRupiah('')).toBe('');
        // Di atas batas presisi Number tetap utuh.
        expect(parseRupiah('9.999.999.999.999.999,99')).toBe(
            '9999999999999999.99',
        );
    });

    it('merapikan ketikan dan mengelompokkan ribuan', () => {
        expect(groupRupiah('20000000')).toBe('20.000.000');
        expect(reformatRupiahInput('5000000')).toBe('5.000.000');
        expect(reformatRupiahInput('5.000,')).toBe('5.000,');
        expect(reformatRupiahInput('abc')).toBe('');
    });
});

function Harness({ onChange }: { onChange: (v: string) => void }) {
    const [value, setValue] = useState('');

    return (
        <MoneyInput
            id="limit"
            value={value}
            onChange={(v) => {
                setValue(v);
                onChange(v);
            }}
        />
    );
}

describe('MoneyInput', () => {
    it('menampilkan format id-ID dan mengirim string kanonik ke induk', () => {
        const onChange = vi.fn();
        render(<Harness onChange={onChange} />);
        const input = screen.getByRole('textbox') as HTMLInputElement;

        fireEvent.change(input, { target: { value: '25000000' } });
        expect(input.value).toBe('25.000.000');
        expect(onChange).toHaveBeenLastCalledWith('25000000');

        fireEvent.change(input, { target: { value: '25.000.000,5' } });
        expect(input.value).toBe('25.000.000,5');
        expect(onChange).toHaveBeenLastCalledWith('25000000.5');
        expect(typeof onChange.mock.calls.at(-1)?.[0]).toBe('string');
    });

    it('nilai awal kanonik ditampilkan tanpa sen nol', () => {
        render(<MoneyInput id="x" value="12500000.00" onChange={vi.fn()} />);
        expect((screen.getByRole('textbox') as HTMLInputElement).value).toBe(
            '12.500.000',
        );
    });
});
