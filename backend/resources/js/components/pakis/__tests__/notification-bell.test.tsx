import { act, cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    badgeText,
    NotificationBell,
    POLL_MS,
} from '@/components/pakis/notification-bell';
import { TooltipProvider } from '@/components/ui/tooltip';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...props}>
            {children}
        </a>
    ),
    router: { on: vi.fn(() => () => {}) },
}));

const fetchMock = vi.fn();

function respond(count: number) {
    fetchMock.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ count }),
    });
}

async function renderBell() {
    render(
        <TooltipProvider>
            <NotificationBell />
        </TooltipProvider>,
    );
    await act(async () => {
        await Promise.resolve();
        await Promise.resolve();
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('NotificationBell (T-029, 07 §1.2)', () => {
    it('menampilkan jumlah belum dibaca dari /notifikasi/jumlah', async () => {
        respond(3);
        await renderBell();

        expect(fetchMock.mock.calls[0][0]).toContain('/notifikasi/jumlah');
        expect(screen.getByTestId('notification-badge').textContent).toBe('3');
        expect(screen.getByRole('link').getAttribute('aria-label')).toBe(
            'Notifikasi, 3 belum dibaca',
        );
    });

    it('menyembunyikan badge bila tidak ada yang belum dibaca', async () => {
        respond(0);
        await renderBell();

        expect(screen.queryByTestId('notification-badge')).toBeNull();
        expect(screen.getByRole('link').getAttribute('aria-label')).toBe(
            'Notifikasi',
        );
    });

    it('memolling ulang tiap 60 detik', async () => {
        respond(1);
        await renderBell();
        respond(5);

        await act(async () => {
            vi.advanceTimersByTime(POLL_MS);
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(screen.getByTestId('notification-badge').textContent).toBe('5');
    });

    it('membatasi teks badge ke 99+', () => {
        expect(badgeText(100)).toBe('99+');
        expect(badgeText(42)).toBe('42');
    });
});
