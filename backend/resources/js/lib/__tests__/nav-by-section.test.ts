import { describe, expect, it } from 'vitest';
import { bySection } from '@/components/nav-main';
import type { MenuGroup } from '@/types';

const group = (section: string | null, title: string | null): MenuGroup => ({
    section,
    title,
    items: [
        {
            title: `${title ?? 'Dasbor'} item`,
            href: `/${title ?? 'dasbor'}`,
            icon: 'x',
        },
    ],
});

describe('bySection (judul seksi sidebar, 06 §8)', () => {
    it('grup berurutan dengan seksi sama menjadi satu blok, urutan server dipertahankan', () => {
        const blocks = bySection([
            group(null, null),
            group('HRIS', 'Unit Kerja'),
            group('HRIS', 'Kepegawaian'),
            group('HRIS', 'Presensi'),
            group('Sistem', 'Pengguna & Akses'),
            group('Sistem', 'Audit'),
        ]);

        expect(blocks.map((b) => b.section)).toEqual([null, 'HRIS', 'Sistem']);
        expect(blocks[1].groups.map((g) => g.title)).toEqual([
            'Unit Kerja',
            'Kepegawaian',
            'Presensi',
        ]);
    });

    it('seksi tanpa grup yang boleh dilihat tidak muncul (menu kosong = tanpa blok)', () => {
        expect(bySection([group(null, null)])).toEqual([
            { section: null, groups: [group(null, null)] },
        ]);
        expect(bySection([])).toEqual([]);
    });
});
