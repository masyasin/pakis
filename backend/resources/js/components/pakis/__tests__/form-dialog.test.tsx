import { fireEvent, render, screen } from '@testing-library/react';
import { ListChecks } from 'lucide-react';
import type { FormEvent } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { FormDialog } from '@/components/pakis/form-dialog';

function content(): HTMLElement {
    const el = document.querySelector<HTMLElement>(
        '[data-slot="dialog-content"]',
    );
    if (!el) {
        throw new Error('dialog-content tidak dirender');
    }

    return el;
}

describe('FormDialog (popup baku)', () => {
    it('menampilkan judul, deskripsi, isi, dan kaki; ukuran bawaan kecil', () => {
        render(
            <FormDialog
                open
                onOpenChange={() => {}}
                title="Tambah item"
                description="Grup Tipe lokasi."
                icon={ListChecks}
                footer={<button type="button">Simpan Item</button>}
            >
                <p>Isi form</p>
            </FormDialog>,
        );

        const dialog = screen.getByRole('dialog');
        expect(dialog.getAttribute('aria-labelledby')).toBeTruthy();
        expect(screen.getByText('Tambah item').tagName).toBe('H2');
        expect(screen.getByText('Grup Tipe lokasi.')).toBeTruthy();
        expect(screen.getByText('Isi form')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Simpan Item' }),
        ).toBeTruthy();
        expect(content().className).toContain('sm:max-w-md');
        expect(content().className).toContain('bg-card');
        expect(content().className).not.toContain('sm:max-w-2xl');
    });

    it('ukuran lg memberi kelas sm:max-w-2xl', () => {
        render(
            <FormDialog
                open
                onOpenChange={() => {}}
                title="Tetapkan pola jadwal"
                icon={ListChecks}
                size="lg"
                footer={null}
            >
                <p>Isi</p>
            </FormDialog>,
        );

        expect(content().className).toContain('sm:max-w-2xl');
        expect(content().className).not.toContain('sm:max-w-md');
    });

    it('onSubmit membungkus badan & kaki dalam form', () => {
        const onSubmit = vi.fn((e: FormEvent) => e.preventDefault());
        render(
            <FormDialog
                open
                onOpenChange={() => {}}
                title="Ubah pola"
                icon={ListChecks}
                onSubmit={onSubmit}
                footer={<button type="submit">Simpan Pola</button>}
            >
                <input aria-label="Pola" />
            </FormDialog>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Simpan Pola' }));
        expect(onSubmit).toHaveBeenCalledTimes(1);
    });

    it('tertutup tidak merender apa pun', () => {
        render(
            <FormDialog
                open={false}
                onOpenChange={() => {}}
                title="Tersembunyi"
                icon={ListChecks}
                footer={null}
            >
                <p>Isi</p>
            </FormDialog>,
        );

        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
