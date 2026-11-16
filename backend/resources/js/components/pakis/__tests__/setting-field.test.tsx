import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    describeSetting,
    formatRupiah,
    parseRupiah,
    SettingField,
} from '@/components/pakis/setting-field';
import type { SettingFieldDef } from '@/components/pakis/setting-field';

function def(overrides: Partial<SettingFieldDef>): SettingFieldDef {
    return {
        field: 'approval__contoh',
        key: 'approval.contoh',
        type: 'bool',
        label: 'Contoh',
        help: '',
        value: true,
        default: true,
        customized: false,
        options: [],
        min: null,
        max: null,
        nullable: false,
        public: false,
        locked: false,
        one_way: false,
        image_url: null,
        ...overrides,
    };
}

const noop = () => {};

describe('rupiah setting (PA-19)', () => {
    it('memformat & mengurai rupiah id-ID tanpa float', () => {
        expect(formatRupiah('50000000')).toBe('50.000.000');
        expect(formatRupiah('1234567.5')).toBe('1.234.567,5');
        expect(parseRupiah('50.000.000,50')).toBe('50000000.50');
        expect(parseRupiah('Rp 1.500')).toBe('1500');
        expect(parseRupiah('0,999')).toBe('0.99');
        expect(parseRupiah('')).toBe('');
        // Di atas batas presisi Number tetap utuh.
        expect(parseRupiah('9.999.999.999.999.999,99')).toBe(
            '9999999999999999.99',
        );
        expect(describeSetting('50000000.00', 'money')).toBe('Rp 50.000.000');
        expect(describeSetting('0.8', 'decimal')).toBe('0,8');
    });

    it('input money menampilkan format id-ID dan mengirim nilai kanonik', () => {
        const onChange = vi.fn();
        render(
            <SettingField
                def={def({ type: 'money', value: '50000000.00' })}
                value="50000000.00"
                onChange={onChange}
                onFile={noop}
                onReset={noop}
            />,
        );

        const input = screen.getByRole<HTMLInputElement>('textbox');
        expect(input.value).toBe('50.000.000');

        fireEvent.change(input, { target: { value: '75.000.0001' } });
        expect(onChange).toHaveBeenLastCalledWith('750000001');
    });
});

describe('penanda locked & one_way (PA-19)', () => {
    it('field locked terkunci + penjelasan untuk selain super_admin', () => {
        render(
            <SettingField
                def={def({ locked: true })}
                value={true}
                onChange={noop}
                onFile={noop}
                onReset={noop}
            />,
        );

        expect(
            screen.getByRole('checkbox').hasAttribute('disabled'),
        ).toBeTruthy();
        expect(
            screen.getByText(/hanya Super Admin yang bisa mengubah/),
        ).toBeTruthy();
    });

    it('field locked bisa diubah super_admin', () => {
        render(
            <SettingField
                def={def({ locked: true })}
                value={true}
                canChangeLocked
                onChange={noop}
                onFile={noop}
                onReset={noop}
            />,
        );

        expect(
            screen.getByRole('checkbox').hasAttribute('disabled'),
        ).toBeFalsy();
        expect(screen.getByText(/butuh konfirmasi password/)).toBeTruthy();
    });

    it('one_way yang sudah dimatikan tidak bisa dinyalakan lagi', () => {
        render(
            <SettingField
                def={def({ one_way: true, value: false })}
                value={false}
                onChange={noop}
                onFile={noop}
                onReset={noop}
            />,
        );

        expect(
            screen.getByRole('checkbox').hasAttribute('disabled'),
        ).toBeTruthy();
        expect(screen.getByText(/tidak bisa diaktifkan lagi/)).toBeTruthy();
    });
});
