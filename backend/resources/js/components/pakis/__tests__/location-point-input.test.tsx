import { act, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocationPointInput } from '@/components/pakis/location-point-input';
import type { LocationPointValue } from '@/components/pakis/location-point-input';

type Success = (position: {
    coords: { latitude: number; longitude: number; accuracy: number };
}) => void;
type Failure = (error: { code: number }) => void;

/** Geolocation tiruan: test memutuskan kapan & bagaimana posisi dijawab. */
function mockGeolocation() {
    const calls: { ok: Success; fail: Failure; options?: PositionOptions }[] =
        [];
    const geolocation = {
        getCurrentPosition: vi.fn(
            (ok: Success, fail: Failure, options?: PositionOptions) => {
                calls.push({ ok, fail, options });
            },
        ),
    };
    Object.defineProperty(navigator, 'geolocation', {
        configurable: true,
        value: geolocation,
    });

    return { geolocation, calls };
}

function Harness({
    onChange,
    initial = { latitude: '', longitude: '' },
}: {
    onChange: (value: LocationPointValue) => void;
    initial?: { latitude: string; longitude: string };
}) {
    const [point, setPoint] = useState(initial);

    return (
        <LocationPointInput
            label="Titik kios"
            latitude={point.latitude}
            longitude={point.longitude}
            onChange={(v) => {
                setPoint({ latitude: v.latitude, longitude: v.longitude });
                onChange(v);
            }}
        />
    );
}

afterEach(() => {
    Reflect.deleteProperty(navigator, 'geolocation');
});

describe('LocationPointInput (15 §7.2, PA-20, T-073)', () => {
    it('mengisi titik dari tautan Google Maps yang ditempel', () => {
        const onChange = vi.fn();
        render(<Harness onChange={onChange} />);

        fireEvent.change(screen.getByLabelText('Tempel tautan Google Maps'), {
            target: {
                value: 'https://www.google.com/maps/place/Kios/@-6.8,110.8,17z/data=!3m1!4b1!8m2!3d-6.8512345!4d110.8423456',
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: /Ambil koordinat/ }),
        );

        expect(onChange).toHaveBeenLastCalledWith({
            latitude: '-6.8512345',
            longitude: '110.8423456',
            accuracyM: null,
        });
        expect(
            (screen.getByLabelText('Lintang') as HTMLInputElement).value,
        ).toBe('-6.8512345');
        // Titik tampil di diagram dengan tautan Google Maps biasa (tanpa tile/kunci API).
        const link = screen.getByRole('link', { name: /Lihat di Google Maps/ });
        expect(link.getAttribute('href')).toBe(
            'https://www.google.com/maps?q=-6.8512345%2C110.8423456',
        );
    });

    it('menolak tautan pendek tanpa mengubah titik', () => {
        const onChange = vi.fn();
        render(<Harness onChange={onChange} />);

        fireEvent.change(screen.getByLabelText('Tempel tautan Google Maps'), {
            target: { value: 'https://maps.app.goo.gl/AbCdEf' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: /Ambil koordinat/ }),
        );

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toContain(
            'Tautan pendek',
        );
    });

    it('mengambil titik HP lewat Geolocation API beserta akurasinya', () => {
        const { calls } = mockGeolocation();
        const onChange = vi.fn();
        render(<Harness onChange={onChange} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Ambil lokasi HP/ }),
        );
        expect(calls).toHaveLength(1);
        // Titik harus posisi saat ini, bukan cache dari tempat lain.
        expect(calls[0].options).toMatchObject({
            enableHighAccuracy: true,
            maximumAge: 0,
        });
        expect(
            screen.getByRole('button', { name: /Mengambil lokasi/ }),
        ).toBeTruthy();

        act(() =>
            calls[0].ok({
                coords: {
                    latitude: -6.812345678,
                    longitude: 110.845678912,
                    accuracy: 12.4,
                },
            }),
        );

        expect(onChange).toHaveBeenLastCalledWith({
            latitude: '-6.8123457',
            longitude: '110.8456789',
            accuracyM: 12,
        });
        expect(screen.getByText('Akurasi lokasi HP ± 12 m')).toBeTruthy();
    });

    it('menampilkan pesan saat izin lokasi ditolak', () => {
        const { calls } = mockGeolocation();
        const onChange = vi.fn();
        render(<Harness onChange={onChange} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Ambil lokasi HP/ }),
        );
        act(() => calls[0].fail({ code: 1 }));

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toContain(
            'Izin lokasi ditolak',
        );
    });

    it('menolak posisi HP di luar Indonesia', () => {
        const { calls } = mockGeolocation();
        const onChange = vi.fn();
        render(<Harness onChange={onChange} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Ambil lokasi HP/ }),
        );
        act(() =>
            calls[0].ok({
                coords: { latitude: 48.85, longitude: 2.35, accuracy: 5 },
            }),
        );

        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toContain(
            'luar Indonesia',
        );
    });

    it('tombol lokasi HP nonaktif bila browser tanpa Geolocation', () => {
        Object.defineProperty(navigator, 'geolocation', {
            configurable: true,
            value: undefined,
        });
        render(<Harness onChange={vi.fn()} />);

        const button = screen.getByRole('button', {
            name: /Ambil lokasi HP/,
        }) as HTMLButtonElement;
        expect(button.disabled).toBe(true);
        expect(
            screen.getByText('Browser ini tidak memberi akses lokasi.'),
        ).toBeTruthy();
    });

    it('menghapus titik yang sudah ada', () => {
        const onChange = vi.fn();
        render(
            <Harness
                onChange={onChange}
                initial={{ latitude: '-6.85', longitude: '110.84' }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Hapus titik/ }));

        expect(onChange).toHaveBeenLastCalledWith({
            latitude: '',
            longitude: '',
            accuracyM: null,
        });
        expect(screen.queryByRole('link')).toBeNull();
    });
});
