import { describe, expect, it } from 'vitest';
import {
    bearingLabel,
    diagramGeometry,
    offsetMeters,
    osmLink,
} from '@/lib/location-diagram';

// Titik fiktif di laut Jawa (data uji, bukan kantor Pakis). 0,001° lintang ≈ 111 m.
const AREA = { lat: -6.5, lng: 110.5, radius_m: 100, name: 'Gudang Uji' };

describe('offsetMeters', () => {
    it('0,001° lintang ke utara ≈ 111 m utara', () => {
        const { east, north } = offsetMeters(AREA, { lat: -6.499, lng: 110.5 });
        expect(Math.round(north)).toBe(111);
        expect(Math.round(east)).toBe(0);
    });

    it('bujur dikoreksi cos(lintang)', () => {
        const { east } = offsetMeters(AREA, { lat: -6.5, lng: 110.501 });
        expect(Math.round(east)).toBe(110);
    });
});

describe('bearingLabel', () => {
    it('delapan mata angin, berimpit = null', () => {
        expect(bearingLabel(0, 10)).toBe('Utara');
        expect(bearingLabel(10, 10)).toBe('Timur Laut');
        expect(bearingLabel(10, 0)).toBe('Timur');
        expect(bearingLabel(0, -10)).toBe('Selatan');
        expect(bearingLabel(-10, 0)).toBe('Barat');
        expect(bearingLabel(-10, 10)).toBe('Barat Laut');
        expect(bearingLabel(0.2, 0.2)).toBeNull();
    });
});

describe('diagramGeometry', () => {
    it('titik di dalam radius: lingkaran memenuhi diagram, titik di dalamnya', () => {
        const g = diagramGeometry({ lat: -6.5003, lng: 110.5 }, AREA);
        expect(g.area).not.toBeNull();
        expect(Math.round(g.distanceM)).toBe(33);
        const dx = g.point.x - g.center.x;
        const dy = g.point.y - g.center.y;
        expect(Math.hypot(dx, dy)).toBeLessThan(g.area!.r);
        // Selatan = y SVG lebih besar dari pusat.
        expect(g.point.y).toBeGreaterThan(g.center.y);
        expect(g.bearing).toBe('Selatan');
    });

    it('titik jauh di luar radius tetap terlihat di dalam diagram', () => {
        const g = diagramGeometry({ lat: -6.5, lng: 110.52 }, AREA);
        expect(g.distanceM).toBeGreaterThan(2000);
        expect(g.point.x).toBeGreaterThan(0);
        expect(g.point.x).toBeLessThan(g.size);
        expect(g.area!.r).toBeLessThan(g.size / 2);
    });

    it('tanpa lokasi: titik absen di pusat, tanpa arah', () => {
        const g = diagramGeometry({ lat: -6.5, lng: 110.5 }, null);
        expect(g.point).toEqual(g.center);
        expect(g.area).toBeNull();
        expect(g.bearing).toBeNull();
    });
});

describe('osmLink', () => {
    it('membangun tautan OSM dengan 6 desimal', () => {
        expect(osmLink({ lat: -6.5, lng: 110.5 })).toBe(
            'https://www.openstreetmap.org/?mlat=-6.500000&mlon=110.500000#map=18/-6.500000/110.500000',
        );
    });
});
