import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    compressPhoto,
    fitWithin,
    PHOTO_JPEG_QUALITY,
    PHOTO_MAX_SIDE,
    PhotoCapture,
} from '@/components/pakis/photo-capture';

/** jsdom tidak punya canvas/bitmap: dipalsukan agar alur kompres bisa diuji. */
function fakeCanvas() {
    const drawImage = vi.fn();
    const toBlob = vi.fn((cb: BlobCallback, type?: string) =>
        cb(new Blob(['jpeg'], { type })),
    );
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
        drawImage,
    } as unknown as CanvasRenderingContext2D);
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(toBlob);
    vi.stubGlobal(
        'createImageBitmap',
        vi.fn(async () => ({ width: 4000, height: 3000, close: vi.fn() })),
    );
    // jsdom belum punya object URL untuk pratinjau.
    Object.assign(URL, {
        createObjectURL: vi.fn(() => 'blob:pratinjau'),
        revokeObjectURL: vi.fn(),
    });

    return { drawImage, toBlob };
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('fitWithin (15 §7.2 PhotoCapture ≤ 1600 px)', () => {
    it('mengecilkan sisi terpanjang ke 1600 dengan rasio tetap', () => {
        expect(fitWithin(4000, 3000)).toEqual({ width: 1600, height: 1200 });
        expect(fitWithin(3000, 4000)).toEqual({ width: 1200, height: 1600 });
        expect(fitWithin(4032, 1908)).toEqual({ width: 1600, height: 757 });
    });

    it('tidak memperbesar foto yang sudah kecil', () => {
        expect(fitWithin(1200, 800)).toEqual({ width: 1200, height: 800 });
        expect(fitWithin(1600, 1600)).toEqual({ width: 1600, height: 1600 });
    });

    it('ukuran tidak sah menghasilkan nol, sisi sangat tipis minimal 1 px', () => {
        expect(fitWithin(0, 100)).toEqual({ width: 0, height: 0 });
        expect(fitWithin(100000, 10)).toEqual({ width: 1600, height: 1 });
        expect(PHOTO_MAX_SIDE).toBe(1600);
    });
});

describe('compressPhoto', () => {
    it('menggambar ulang ke ≤ 1600 px dan mengirim JPEG 80% berakhiran .jpg', async () => {
        const { drawImage, toBlob } = fakeCanvas();
        const out = await compressPhoto(
            new File(['x'], 'IMG_0001.HEIC', { type: 'image/heic' }),
        );

        expect(drawImage).toHaveBeenCalledWith(
            expect.anything(),
            0,
            0,
            1600,
            1200,
        );
        expect(toBlob).toHaveBeenCalledWith(
            expect.any(Function),
            'image/jpeg',
            PHOTO_JPEG_QUALITY,
        );
        expect(PHOTO_JPEG_QUALITY).toBe(0.8);
        expect(out.type).toBe('image/jpeg');
        expect(out.name).toBe('IMG_0001.jpg');
    });
});

describe('PhotoCapture', () => {
    it('memakai kamera belakang lewat input file, bukan getUserMedia', () => {
        render(
            <PhotoCapture
                id="foto"
                label="Foto toko"
                value={null}
                onChange={vi.fn()}
            />,
        );
        const input = screen.getByLabelText('Foto toko') as HTMLInputElement;
        expect(input.type).toBe('file');
        expect(input.accept).toBe('image/*');
        expect(input.getAttribute('capture')).toBe('environment');
        expect(screen.getByRole('button', { name: 'Ambil foto' })).toBeTruthy();
    });

    it('foto terpilih dikompres dulu sebelum diteruskan ke induk', async () => {
        fakeCanvas();
        const onChange = vi.fn();
        render(
            <PhotoCapture
                id="foto"
                label="Foto toko"
                value={null}
                onChange={onChange}
            />,
        );
        fireEvent.change(screen.getByLabelText('Foto toko'), {
            target: {
                files: [new File(['x'], 'toko.png', { type: 'image/png' })],
            },
        });

        await waitFor(() => expect(onChange).toHaveBeenCalledTimes(1));
        const file = onChange.mock.calls[0][0] as File;
        expect(file.type).toBe('image/jpeg');
        expect(file.name).toBe('toko.jpg');
    });

    it('foto yang tidak bisa dibaca memberi pesan, tidak mengirim apa pun', async () => {
        vi.stubGlobal(
            'createImageBitmap',
            vi.fn(async () => {
                throw new Error('rusak');
            }),
        );
        const onChange = vi.fn();
        render(
            <PhotoCapture
                id="foto"
                label="Foto toko"
                value={null}
                onChange={onChange}
            />,
        );
        fireEvent.change(screen.getByLabelText('Foto toko'), {
            target: {
                files: [new File(['x'], 'a.jpg', { type: 'image/jpeg' })],
            },
        });

        expect((await screen.findByRole('alert')).textContent).toContain(
            'Foto tidak bisa dibaca',
        );
        expect(onChange).not.toHaveBeenCalled();
    });
});
