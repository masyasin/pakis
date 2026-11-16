import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApprovalDecisionDialog } from '@/components/pakis/approval-decision-dialog';
import type {
    ApprovalDecision,
    ApprovalDecisionTarget,
} from '@/components/pakis/approval-decision-dialog';

const routerPost = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { post: routerPost },
}));

const approval = (
    overrides: Partial<ApprovalDecisionTarget> = {},
): ApprovalDecisionTarget => ({
    id: 7,
    document_type_label: 'Purchase order',
    summary: 'PO-2611-0042 · PT Uji',
    amount_label: 'Rp 48.500.000',
    current_step: 2,
    steps_total: 3,
    requires_password: false,
    ...overrides,
});

function open(decision: ApprovalDecision) {
    render(
        <ApprovalDecisionDialog
            pending={decision}
            onClose={() => {}}
            reauthAmount="Rp 25.000.000"
        />,
    );
}

function lastPost(): { url: string; data: Record<string, unknown> } {
    const [url, data] = routerPost.mock.calls.at(-1) as [
        string,
        Record<string, unknown>,
    ];

    return { url, data };
}

describe('ApprovalDecisionDialog (T-067, SEC-101)', () => {
    beforeEach(() => routerPost.mockReset());

    it('SEC-101 field password muncul bila requires_password, dikirim bersama keputusan + step', () => {
        open({
            kind: 'approve',
            approval: approval({ requires_password: true }),
        });

        const password = screen.getByLabelText('Password Anda');
        expect(screen.getByText(/≥ Rp 25\.000\.000/)).toBeTruthy();
        fireEvent.change(password, { target: { value: 'rahasia-uji' } });
        fireEvent.click(screen.getByRole('button', { name: 'Setujui' }));

        const { url, data } = lastPost();
        expect(url).toBe('/persetujuan/7/setujui');
        expect(data).toEqual({
            step: 2,
            note: null,
            current_password: 'rahasia-uji',
        });
    });

    it('tanpa requires_password tidak ada field password dan current_password tidak dikirim', () => {
        open({ kind: 'approve', approval: approval() });

        expect(screen.queryByLabelText('Password Anda')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Setujui' }));
        expect(lastPost().data).not.toHaveProperty('current_password');
    });

    it('tolak: alasan dikirim ke rute tolak; galat field tampil di dialog (tanpa toast kedua)', () => {
        open({ kind: 'reject', approval: approval() });

        fireEvent.change(screen.getByLabelText('Alasan penolakan'), {
            target: { value: 'Harga di atas kontrak' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Tolak Permintaan' }),
        );

        const [url, data, options] = routerPost.mock.calls.at(-1) as [
            string,
            Record<string, unknown>,
            { onError: (e: Record<string, string>) => void },
        ];
        expect(url).toBe('/persetujuan/7/tolak');
        expect(data.note).toBe('Harga di atas kontrak');

        act(() =>
            options.onError({ note: 'Alasan penolakan minimal 5 karakter.' }),
        );
        expect(
            screen.getByText('Alasan penolakan minimal 5 karakter.'),
        ).toBeTruthy();
    });

    it('batal tanpa isian; PA-38 cancel_requires_note memunculkan alasan dan mengirim note', () => {
        open({ kind: 'cancel', approval: approval() });
        expect(screen.queryByRole('textbox')).toBeNull();
        fireEvent.click(
            screen.getByRole('button', { name: 'Batalkan Permintaan' }),
        );
        expect(lastPost()).toEqual({
            url: '/persetujuan/7/batalkan',
            data: {},
        });
    });

    it('PA-38 batal oleh pengelola: alasan wajib dikirim sebagai note', () => {
        open({
            kind: 'cancel',
            approval: approval({ cancel_requires_note: true }),
        });
        fireEvent.change(screen.getByLabelText('Alasan pembatalan'), {
            target: { value: 'Dokumen salah input' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Batalkan Permintaan' }),
        );
        expect(lastPost().data).toEqual({ note: 'Dokumen salah input' });
    });
});
