import { fireEvent, render, screen, within } from '@testing-library/react';
import { ShieldCheck, Store } from 'lucide-react';
import { describe, expect, it } from 'vitest';
import { DetailHero } from '@/components/pakis/detail-hero';

describe('DetailHero', () => {
    it('menampilkan sorotan maks. 3 dan menyembunyikan nilai kosong', () => {
        render(
            <DetailHero
                title="Kios Uji Demo"
                avatar={{ icon: Store }}
                highlights={[
                    {
                        icon: ShieldCheck,
                        label: 'Terverifikasi',
                        value: 'Limit Rp 5.000.000',
                        tone: 'success',
                    },
                    { label: 'Kosong', value: null },
                    { label: 'Sales', value: 'Budi Demo' },
                    { label: 'Tempo', value: '30 hari' },
                    { label: 'Keempat', value: 'tidak tampil' },
                ]}
            />,
        );

        // Dua tempat render (pita untuk kartu lebar, bawah judul untuk HP); CSS memilih satu.
        const lists = screen.getAllByRole('list', { name: 'Sorotan' });
        expect(lists).toHaveLength(2);

        for (const list of lists) {
            const items = within(list).getAllByRole('listitem');
            expect(items.map((li) => li.textContent)).toEqual([
                'Terverifikasi: Limit Rp 5.000.000',
                'Sales: Budi Demo',
                'Tempo: 30 hari',
            ]);
            expect(items[0].getAttribute('data-tone')).toBe('success');
            expect(items[1].getAttribute('data-tone')).toBe('default');
        }
    });

    it('tanpa sorotan tidak merender daftar sorotan; motif pakis tersembunyi dari pembaca layar', () => {
        const { container } = render(
            <DetailHero title="Produk Uji Demo" avatar={{ icon: Store }} />,
        );

        expect(screen.queryByRole('list', { name: 'Sorotan' })).toBeNull();
        const motif = container.querySelector('[data-testid="fern-motif"]');
        expect(motif).not.toBeNull();
        expect(motif?.getAttribute('aria-hidden')).toBe('true');
        expect(motif?.closest('[aria-hidden="true"]')).not.toBeNull();
    });

    it('menampilkan foto bila ada dan jatuh ke ikon saat foto gagal dimuat', () => {
        const { container } = render(
            <DetailHero
                title="Toko Uji Demo"
                avatar={{
                    icon: Store,
                    photoUrl: '/foto/kedaluwarsa.jpg',
                    photoAlt: 'Foto toko Toko Uji Demo',
                }}
            />,
        );

        const img = screen.getByRole('img', {
            name: 'Foto toko Toko Uji Demo',
        });
        fireEvent.error(img);

        expect(
            screen.queryByRole('img', { name: 'Foto toko Toko Uji Demo' }),
        ).toBeNull();
        // Ikon cadangan (lucide) dirender sebagai svg di dalam avatar.
        expect(container.querySelector('svg.lucide-store')).not.toBeNull();
    });
});
