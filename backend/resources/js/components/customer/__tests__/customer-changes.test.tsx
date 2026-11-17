import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { CustomerNotices } from '@/components/customer/customer-actions';
import { SignedPhoto } from '@/components/customer/customer-ui';
import type { ChangeState } from '@/components/customer/customer-ui';
import { creditLimitIssues } from '@/components/customer/limit-tab';
import { phoneChangeIssues } from '@/components/customer/phone-change';
import {
    tradeTermsInitial,
    tradeTermsPayload,
} from '@/components/customer/trade-terms-section';

const reload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { reload },
    Link: ({ href, children }: { href: unknown; children: ReactNode }) => (
        <a
            href={
                typeof href === 'string' ? href : (href as { url: string }).url
            }
        >
            {children}
        </a>
    ),
    useForm: () => ({}),
}));

const phoneInput = {
    new_phone: '0813 2222 3333',
    method: 'otp' as const,
    office_call_note: '',
    reason: 'HP lama hilang',
};

describe('ganti HP kios (BR-166(4), PA-57a)', () => {
    it('HP baru wajib sah dan beda dari HP sekarang', () => {
        expect(
            phoneChangeIssues({ ...phoneInput, new_phone: '' }, true, null)
                .new_phone,
        ).toContain('Isi HP baru');
        expect(
            phoneChangeIssues({ ...phoneInput, new_phone: '0813' }, true, null)
                .new_phone,
        ).toContain('belum sah');
        // HP sekarang tersimpan 62…, ketikan 08…: dibandingkan setelah normalisasi.
        expect(
            phoneChangeIssues(phoneInput, true, '6281322223333').new_phone,
        ).toBe('HP baru sama dengan HP sekarang.');
        // HP sekarang termasking (null): tidak dibandingkan di layar.
        expect(phoneChangeIssues(phoneInput, true, null)).toEqual({});
    });

    it('bukti nomor lama: OTP harus sudah benar, telepon kantor wajib catatan ≥ 10 karakter', () => {
        expect(
            phoneChangeIssues(phoneInput, false, null).otp_code,
        ).toBeTruthy();
        const office = { ...phoneInput, method: 'office_call' as const };
        expect(
            phoneChangeIssues(
                { ...office, office_call_note: 'singkat' },
                false,
                null,
            ).office_call_note,
        ).toBeTruthy();
        expect(
            phoneChangeIssues(
                {
                    ...office,
                    office_call_note: 'Dijawab pemilik, benar ganti nomor.',
                },
                false,
                null,
            ),
        ).toEqual({});
    });

    it('alasan minimal 5 karakter', () => {
        expect(
            phoneChangeIssues({ ...phoneInput, reason: ' hp ' }, true, null)
                .reason,
        ).toBeTruthy();
    });
});

describe('pengajuan limit kredit (BR-125)', () => {
    it('wajib diisi, tidak negatif, dan beda dari limit sekarang', () => {
        expect(
            creditLimitIssues('', 'naik omzet', '0.00').credit_limit,
        ).toBeTruthy();
        expect(
            creditLimitIssues('-5', 'naik omzet', '0.00').credit_limit,
        ).toBeTruthy();
        expect(
            creditLimitIssues('5000000', 'naik omzet', '5000000.00')
                .credit_limit,
        ).toBe('Limit baru sama dengan limit sekarang.');
        expect(
            creditLimitIssues('7500000', 'naik omzet', '5000000.00'),
        ).toEqual({});
        // 0 = mencabut limit: sah bila limit sekarang bukan 0.
        expect(creditLimitIssues('0', 'tutup kredit', '5000000.00')).toEqual(
            {},
        );
    });

    it('alasan minimal 5 karakter', () => {
        expect(creditLimitIssues('100', 'ok', '0.00').reason).toBeTruthy();
    });
});

describe('ketentuan dagang form kios (T-074, BR-112)', () => {
    const base = tradeTermsInitial({
        harvest_term_allowed: true,
        harvest_term_max_days: 120,
        restricted_buyer_eligible: true,
        restricted_buyer_note: 'Sertifikat aplikator',
        restricted_buyer_valid_until: '2027-01-31',
    });

    it('nilai awal dari kios; tanpa berkas dikirim biasa (bukan multipart)', () => {
        expect(base.harvest_term_max_days).toBe('120');
        const { data, multipart } = tradeTermsPayload(base);
        expect(multipart).toBe(false);
        expect(data).toEqual({
            harvest_term_allowed: true,
            harvest_term_max_days: '120',
            restricted_buyer_eligible: true,
            restricted_buyer_note: 'Sertifikat aplikator',
            restricted_buyer_valid_until: '2027-01-31',
        });
        expect('restricted_buyer_document' in data).toBe(false);
    });

    it('ketentuan dimatikan: batas hari & masa berlaku tidak dikirim (tanggal lama tidak menggagalkan simpan)', () => {
        const { data } = tradeTermsPayload({
            ...base,
            harvest_term_allowed: false,
            restricted_buyer_eligible: false,
        });
        expect(data.harvest_term_max_days).toBeNull();
        expect(data.restricted_buyer_valid_until).toBeNull();
    });

    it('berkas dipilih → multipart dan berkas ikut dikirim', () => {
        const file = new File(['%PDF-1.4'], 'izin.pdf', {
            type: 'application/pdf',
        });
        const { data, multipart } = tradeTermsPayload({
            ...base,
            restricted_buyer_document: file,
        });
        expect(multipart).toBe(true);
        expect(data.restricted_buyer_document).toBe(file);
    });

    it('kios baru tanpa ketentuan: semua mati', () => {
        expect(tradeTermsInitial(null)).toMatchObject({
            harvest_term_allowed: false,
            harvest_term_max_days: '',
            restricted_buyer_eligible: false,
            restricted_buyer_document: null,
        });
    });
});

const mergeState = (
    overrides: Partial<ChangeState['merge']> = {},
): ChangeState['merge'] => ({
    can_request: false,
    pending: null,
    merged_into: null,
    ...overrides,
});

describe('pemberitahuan kios (BR-166(5), 15 §6.6)', () => {
    it('kios digabung menautkan ke kios tujuan', () => {
        render(
            <CustomerNotices
                status="merged"
                merge={mergeState({
                    merged_into: {
                        id: 42,
                        code: 'KIO-0042',
                        store_name: 'Toko Tani Makmur',
                    },
                })}
                holdReason={null}
                timezone="Asia/Jakarta"
            />,
        );
        const link = screen.getByRole('link', {
            name: 'Toko Tani Makmur (KIO-0042)',
        });
        expect(link.getAttribute('href')).toBe('/pelanggan/42');
    });

    it('alasan tahan kredit tampil hanya untuk status tahan/nonaktif', () => {
        const { rerender } = render(
            <CustomerNotices
                status="credit_hold"
                merge={mergeState()}
                holdReason="Tagihan lewat jatuh tempo"
                timezone="Asia/Jakarta"
            />,
        );
        expect(screen.getByText('Tagihan lewat jatuh tempo')).toBeTruthy();
        expect(screen.getByText(/Alasan tahan kredit/)).toBeTruthy();

        rerender(
            <CustomerNotices
                status="active"
                merge={mergeState()}
                holdReason="Tagihan lewat jatuh tempo"
                timezone="Asia/Jakarta"
            />,
        );
        expect(screen.queryByText('Tagihan lewat jatuh tempo')).toBeNull();
    });
});

describe('SignedPhoto (SEC-108 tautan 5 menit)', () => {
    it('membuka foto di tab baru; tautan kedaluwarsa → tombol muat ulang; URL baru dicoba lagi', () => {
        const { rerender } = render(
            <SignedPhoto url="/pelanggan/1/foto?signature=a" alt="Foto toko" />,
        );
        const link = screen.getByRole('link');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toContain('noopener');

        fireEvent.error(screen.getByAltText('Foto toko'));
        fireEvent.click(screen.getByRole('button', { name: 'Muat ulang' }));
        expect(reload).toHaveBeenCalledTimes(1);

        rerender(
            <SignedPhoto url="/pelanggan/1/foto?signature=b" alt="Foto toko" />,
        );
        expect(screen.getByAltText('Foto toko')).toBeTruthy();
    });

    it('tanpa foto menampilkan teks kosong', () => {
        render(<SignedPhoto url={null} alt="Foto" emptyText="Belum ada." />);
        expect(screen.getByText('Belum ada.')).toBeTruthy();
    });
});
