import { describe, expect, it } from 'vitest';
import {
    describeCell,
    formatMonth,
    monthDays,
    shiftMonth,
    summarizePattern,
} from '@/lib/schedule';
import type { ScheduleCell } from '@/lib/schedule';

const cell = (patch: Partial<ScheduleCell>): ScheduleCell => ({
    kind: 'unscheduled',
    source: 'none',
    shift: null,
    holiday: null,
    note: null,
    override: false,
    override_id: null,
    ...patch,
});

describe('monthDays (kolom kalender jadwal T-035)', () => {
    it('November 2026: 30 hari, 1 Nov = Minggu, 16 Nov = Senin', () => {
        const days = monthDays('2026-11');

        expect(days).toHaveLength(30);
        expect(days[0]).toEqual({
            date: '2026-11-01',
            day: 1,
            weekday: 'Min',
            isSunday: true,
        });
        expect(days[15]).toMatchObject({ date: '2026-11-16', weekday: 'Sen' });
    });

    it('Februari tahun kabisat & bulan tidak sah', () => {
        expect(monthDays('2028-02')).toHaveLength(29);
        expect(monthDays('2026-02')).toHaveLength(28);
        expect(monthDays('2026-13x')).toEqual([]);
    });
});

describe('shiftMonth & formatMonth', () => {
    it('melewati batas tahun dua arah', () => {
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
        expect(shiftMonth('bukan', 1)).toBe('bukan');
    });

    it('nama bulan Bahasa Indonesia', () => {
        expect(formatMonth('2026-11')).toBe('November 2026');
    });
});

describe('describeCell (label pembaca layar, BR-061/BR-062)', () => {
    const pagi = { id: 1, code: 'PAGI', name: 'Pagi', color: '#2F6B3B' };

    it('shift dari pola, masuk di hari libur, dan pengecualian bercatatan', () => {
        expect(
            describeCell(
                cell({ kind: 'shift', source: 'pattern', shift: pagi }),
            ),
        ).toBe('Shift PAGI (Pagi)');
        expect(
            describeCell(
                cell({
                    kind: 'shift',
                    source: 'override',
                    shift: pagi,
                    holiday: 'Natal',
                    override: true,
                    note: 'Piket',
                }),
            ),
        ).toBe(
            'Shift PAGI (Pagi) · masuk di hari libur Natal · pengecualian · Piket',
        );
    });

    it('libur resmi, libur pegawai, dan belum dijadwalkan', () => {
        expect(describeCell(cell({ kind: 'holiday', holiday: 'Natal' }))).toBe(
            'Libur: Natal',
        );
        expect(describeCell(cell({ kind: 'off', source: 'pattern' }))).toBe(
            'Libur',
        );
        expect(describeCell(cell({}))).toBe('Belum dijadwalkan');
    });
});

describe('summarizePattern (ringkasan pola di HP)', () => {
    const shift = (code: string) => ({
        id: 1,
        code,
        name: code,
        color: '#000000',
    });

    it('menggabungkan hari berurutan yang sama', () => {
        const sls = shift('SLS');
        expect(
            summarizePattern({
                mon: sls,
                tue: sls,
                wed: sls,
                thu: sls,
                fri: sls,
                sat: sls,
                sun: null,
            }),
        ).toBe('Sen–Sab SLS · Min Libur');
        expect(
            summarizePattern({
                mon: shift('PAGI'),
                tue: null,
                wed: shift('PAGI'),
                thu: shift('PAGI'),
                fri: shift('PAGI'),
                sat: shift('SIANG'),
                sun: null,
            }),
        ).toBe('Sen PAGI · Sel Libur · Rab–Jum PAGI · Sab SIANG · Min Libur');
    });
});
