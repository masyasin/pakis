import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { cleanScan, ScanField } from '@/components/pakis/scan-field';

describe('cleanScan (15 §7.1 pemindai)', () => {
    it('membuang CR/LF/TAB dari scanner dan spasi tepi', () => {
        expect(cleanScan('  SPR-001\r\n')).toBe('SPR-001');
        expect(cleanScan('\tAB 12\t')).toBe('AB 12');
        expect(cleanScan('   ')).toBe('');
    });
});

describe('ScanField', () => {
    it('Enter mengirim kode (tidak mengirim form induk) dan mengosongkan isian bila diterima', () => {
        const onScan = vi.fn(() => true);
        const onSubmit = vi.fn((e: Event) => e.preventDefault());
        render(
            <form onSubmit={(e) => onSubmit(e.nativeEvent)}>
                <ScanField id="scan" label="Pindai" onScan={onScan} />
            </form>,
        );
        const input = screen.getByLabelText('Pindai') as HTMLInputElement;

        fireEvent.change(input, { target: { value: ' SN-01 ' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(onScan).toHaveBeenCalledWith('SN-01');
        expect(onSubmit).not.toHaveBeenCalled();
        expect(input.value).toBe('');
    });

    it('kode ditolak: pesan tampil dan isian dipertahankan', () => {
        render(
            <ScanField
                id="scan"
                label="Pindai"
                onScan={() => 'SN-01 sudah ada di daftar.'}
            />,
        );
        const input = screen.getByLabelText('Pindai') as HTMLInputElement;

        fireEvent.change(input, { target: { value: 'SN-01' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(screen.getByText('SN-01 sudah ada di daftar.')).toBeTruthy();
        expect(input.value).toBe('SN-01');
    });

    it('tanpa BarcodeDetector tombol "Foto barcode" tidak tampil (tanpa kamera live)', () => {
        render(<ScanField id="scan" label="Pindai" onScan={() => true} />);

        expect(
            screen.queryByRole('button', { name: 'Foto barcode' }),
        ).toBeNull();
    });
});
