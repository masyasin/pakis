import { describe, expect, it } from 'vitest';
import { formatClock, shiftWindowSummary } from '@/lib/shift-time';

describe('formatClock', () => {
    it('memakai titik sebagai pemisah jam (07 §7)', () => {
        expect(formatClock('07:30')).toBe('07.30');
        expect(formatClock('22:05:00')).toBe('22.05');
    });
});

describe('shiftWindowSummary (BR-060)', () => {
    it('shift reguler: masuk dari start − open sampai end, pulang s.d. end + close', () => {
        expect(
            shiftWindowSummary({
                start_time: '07:30',
                end_time: '16:30',
                crosses_midnight: false,
                checkin_open_min: 120,
                checkout_close_min: 360,
            }),
        ).toBe('Masuk 05.30 – 16.30; pulang paling lambat 22.30.');
    });

    it('shift lintas hari: jam pulang jatuh di hari berikutnya', () => {
        expect(
            shiftWindowSummary({
                start_time: '22:00',
                end_time: '06:00',
                crosses_midnight: true,
                checkin_open_min: 60,
                checkout_close_min: 120,
            }),
        ).toBe(
            'Masuk 21.00 – 06.00 (hari berikutnya); pulang paling lambat 08.00 (hari berikutnya).',
        );
    });

    it('batas buka absen sebelum tengah malam ditandai hari sebelumnya', () => {
        expect(
            shiftWindowSummary({
                start_time: '00:30',
                end_time: '08:00',
                crosses_midnight: false,
                checkin_open_min: 60,
                checkout_close_min: 0,
            }),
        ).toBe(
            'Masuk 23.30 (hari sebelumnya) – 08.00; pulang paling lambat 08.00.',
        );
    });

    it('null bila jam belum diisi', () => {
        expect(
            shiftWindowSummary({
                start_time: '',
                end_time: '16:00',
                crosses_midnight: false,
                checkin_open_min: 0,
                checkout_close_min: 0,
            }),
        ).toBeNull();
    });
});
