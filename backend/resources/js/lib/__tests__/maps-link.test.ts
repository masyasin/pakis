import { describe, expect, it } from 'vitest';
import {
    formatAccuracy,
    geolocationErrorMessage,
    googleMapsUrl,
    parseMapsLink,
    pointFromCoordinates,
} from '@/lib/maps-link';

describe('parseMapsLink (T-021)', () => {
    it('membaca titik tempat (!3d…!4d…) lebih dulu daripada pusat peta (@…)', () => {
        const url =
            'https://www.google.com/maps/place/Gudang/@-6.8000000,110.8000000,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d-6.8512345!4d110.8423456';

        expect(parseMapsLink(url)).toEqual({
            ok: true,
            latitude: '-6.8512345',
            longitude: '110.8423456',
        });
    });

    it('membaca @lat,lng dan parameter q=', () => {
        expect(
            parseMapsLink('https://www.google.com/maps/@-6.75,110.9,15z'),
        ).toEqual({ ok: true, latitude: '-6.75', longitude: '110.9' });
        expect(
            parseMapsLink('https://maps.google.com/?q=-6.8123%2C110.8456'),
        ).toEqual({ ok: true, latitude: '-6.8123', longitude: '110.8456' });
    });

    it('menerima teks koordinat polos dan membulatkan ke 7 desimal', () => {
        expect(parseMapsLink(' -6.812345678, 110.845678912 ')).toEqual({
            ok: true,
            latitude: '-6.8123457',
            longitude: '110.8456789',
        });
    });

    it('menolak tautan pendek tanpa mencoba membukanya', () => {
        expect(parseMapsLink('https://maps.app.goo.gl/AbCdEf123')).toEqual({
            ok: false,
            reason: 'short-link',
        });
    });

    it('menolak koordinat di luar Indonesia dan teks tanpa koordinat', () => {
        expect(
            parseMapsLink('https://www.google.com/maps/@48.85,2.35,12z'),
        ).toEqual({
            ok: false,
            reason: 'outside-indonesia',
        });
        expect(parseMapsLink('Gudang Undaan, Kudus')).toEqual({
            ok: false,
            reason: 'not-found',
        });
    });

    it('membentuk tautan Google Maps biasa (tanpa kunci API)', () => {
        expect(googleMapsUrl('-6.85', '110.84')).toBe(
            'https://www.google.com/maps?q=-6.85%2C110.84',
        );
    });
});

describe('pointFromCoordinates & pesan Geolocation (T-073)', () => {
    it('membulatkan titik HP ke 7 desimal', () => {
        expect(pointFromCoordinates(-6.812345678, 110.845678912)).toEqual({
            ok: true,
            latitude: '-6.8123457',
            longitude: '110.8456789',
        });
    });

    it('menolak titik di luar Indonesia atau bukan angka', () => {
        expect(pointFromCoordinates(48.85, 2.35)).toEqual({
            ok: false,
            reason: 'outside-indonesia',
        });
        expect(pointFromCoordinates(Number.NaN, 110)).toEqual({
            ok: false,
            reason: 'not-found',
        });
    });

    it('memetakan kode galat Geolocation ke pesan Indonesia', () => {
        expect(geolocationErrorMessage(1)).toContain('Izin lokasi ditolak');
        expect(geolocationErrorMessage(2)).toContain('tidak tersedia');
        expect(geolocationErrorMessage(3)).toContain('belum didapat');
        expect(geolocationErrorMessage(99)).toContain('tautan Google Maps');
    });

    it('memformat akurasi dalam meter atau kilometer', () => {
        expect(formatAccuracy(12.4)).toBe('± 12 m');
        expect(formatAccuracy(1250)).toBe('± 1,3 km');
    });
});
