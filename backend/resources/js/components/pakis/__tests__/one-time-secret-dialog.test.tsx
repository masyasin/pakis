import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OneTimeSecretDialog } from '@/components/pakis/one-time-secret-dialog';

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('OneTimeSecretDialog (BR-014, SEC-006)', () => {
    it('tertutup bila secret null', () => {
        render(
            <OneTimeSecretDialog
                secret={null}
                title="Password sementara"
                description="d"
                onDone={() => {}}
            />,
        );

        expect(screen.queryByTestId('one-time-secret')).toBeNull();
    });

    it('menampilkan nilai, peringatan sekali lihat, dan menyalin ke clipboard', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });

        render(
            <OneTimeSecretDialog
                secret="Kebun7Jeruk2"
                title="Password sementara"
                description="d"
                onDone={() => {}}
            />,
        );

        expect(screen.getByTestId('one-time-secret').textContent).toBe(
            'Kebun7Jeruk2',
        );
        expect(document.body.textContent).toContain('tidak bisa dilihat lagi');

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: /salin/i }));
        });

        expect(writeText).toHaveBeenCalledWith('Kebun7Jeruk2');
        expect(screen.getByText('Tersalin')).toBeTruthy();
    });

    it('Esc tidak menutup dialog; tombol selesai memanggil onDone', () => {
        const onDone = vi.fn();
        render(
            <OneTimeSecretDialog
                secret="Kebun7Jeruk2"
                title="Password sementara"
                description="d"
                doneLabel="Selesai"
                onDone={onDone}
            />,
        );

        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });
        expect(onDone).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Selesai' }));
        expect(onDone).toHaveBeenCalledTimes(1);
    });
});
