import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AppLogoMark } from '@/components/app-logo-mark';

// Props halaman tiruan: diisi per test lewat `pageProps`.
const pageProps = vi.hoisted(() => ({
    current: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps.current }),
}));

function withBranding(logoLight: string | null, logoDark: string | null) {
    pageProps.current = {
        name: 'Pakis Demo',
        branding: {
            company: 'CV Contoh',
            logoLight,
            logoDark,
            loginTagline: null,
        },
    };
}

afterEach(() => {
    pageProps.current = {};
});

describe('AppLogoMark (T-008 branding)', () => {
    it('tanpa logo unggahan memakai ikon daun (tanpa <img>)', () => {
        withBranding(null, null);
        const { container } = render(<AppLogoMark />);

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('logo terang saja dipakai untuk kedua tema', () => {
        withBranding('/storage/branding/terang.png', null);
        render(<AppLogoMark />);

        const images = screen.getAllByRole('img');
        expect(images).toHaveLength(1);
        expect(images[0].getAttribute('src')).toBe(
            '/storage/branding/terang.png',
        );
        expect(images[0].getAttribute('alt')).toBe('Logo Pakis Demo');
        expect(images[0].className).not.toContain('dark:hidden');
    });

    it('logo gelap terpisah: terang disembunyikan di mode gelap, gelap hanya di mode gelap', () => {
        withBranding(
            '/storage/branding/terang.png',
            '/storage/branding/gelap.png',
        );
        render(<AppLogoMark />);

        const [light, dark] = screen.getAllByRole('img');
        expect(light.className).toContain('dark:hidden');
        expect(dark.getAttribute('src')).toBe('/storage/branding/gelap.png');
        expect(dark.className).toContain('hidden');
        expect(dark.className).toContain('dark:block');
    });
});
