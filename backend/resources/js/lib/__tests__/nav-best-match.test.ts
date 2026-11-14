import { describe, expect, it } from 'vitest';
import { bestMatch } from '@/components/nav-main';

const hrefs = ['/dashboard', '/pengaturan', '/pengaturan/penomoran', '/role'];

describe('bestMatch (menu aktif sidebar)', () => {
    it('hanya satu item aktif: yang terpanjang menang', () => {
        expect(bestMatch(hrefs, '/pengaturan/penomoran')).toBe(
            '/pengaturan/penomoran',
        );
        expect(bestMatch(hrefs, '/pengaturan/keamanan')).toBe('/pengaturan');
    });

    it('halaman turunan menandai menu induknya, per segmen path', () => {
        expect(bestMatch(hrefs, '/role/3/ubah')).toBe('/role');
        expect(bestMatch(hrefs, '/roleX')).toBeNull();
    });
});
