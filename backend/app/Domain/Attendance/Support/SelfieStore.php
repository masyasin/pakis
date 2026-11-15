<?php

namespace App\Domain\Attendance\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Selfie absen (SEC-022). Hanya JPEG ASLI (diperiksa dari isi, bukan ekstensi/MIME kiriman):
 * tanda tangan FFD8FF, header SOF terbaca, dan bisa didekode GD. Metadata dibuang dengan
 * melewati segmen APP1 (EXIF/XMP, termasuk GPS), APP3–APP13/APP15 (IPTC, dll.), dan COM —
 * TANPA re-encode, sehingga piksel bukti tidak berubah. APP0 (JFIF), APP2 (profil warna ICC),
 * dan APP14 (Adobe, transformasi warna) dipertahankan karena dibutuhkan untuk tampil benar.
 * Disimpan di disk privat `local` dengan nama acak (ULID), tidak pernah lewat URL publik.
 */
final class SelfieStore
{
    public const DISK = 'local';

    /**
     * Batas teknis dimensi selfie (bukan parameter bisnis): Pakis Pro mengompres foto kamera ke
     * ±100 KB, jauh di bawah ini. Mencegah dekode gambar raksasa (≤ 12 MP ≈ 48 MB RAM di GD).
     */
    public const MAX_SIDE_PX = 4096;

    public const MAX_PIXELS = 12_000_000;

    /** Segmen APPn yang dipertahankan: APP0 JFIF, APP2 ICC, APP14 Adobe. */
    private const KEEP_APP = [0xE0, 0xE2, 0xEE];

    /** JPEG sah & bisa didekode; dipakai validasi request agar galat tampil di `selfie`. */
    public static function isGenuineJpeg(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return false;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            return false;
        }

        // Dimensi dari header SOF dicek SEBELUM dekode: JPEG kecil bisa mengaku 60 000 × 60 000 px,
        // dan GD akan mencoba mengalokasikan memori sebanyak itu (bom dekompresi → DoS).
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE_PX || $height > self::MAX_SIDE_PX || $width * $height > self::MAX_PIXELS) {
            return false;
        }

        if (self::strip($bytes) === null) {
            return false;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
        }
        imagedestroy($image);

        return true;
    }

    /**
     * Buang segmen metadata. Null bila struktur JPEG rusak (panjang segmen melewati akhir berkas,
     * tanpa SOS, dsb.).
     */
    public static function strip(string $bytes): ?string
    {
        $length = strlen($bytes);
        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }

        $out = "\xFF\xD8";
        $pos = 2;

        while ($pos < $length) {
            if ($bytes[$pos] !== "\xFF") {
                return null;
            }
            // Byte pengisi 0xFF boleh berulang sebelum kode marker.
            while ($pos < $length && $bytes[$pos] === "\xFF") {
                $pos++;
            }
            if ($pos >= $length) {
                return null;
            }
            $marker = ord($bytes[$pos]);
            $pos++;

            // SOS: sisa berkas = data gambar terkompresi (+ EOI), disalin apa adanya.
            if ($marker === 0xDA) {
                return $out."\xFF\xDA".substr($bytes, $pos);
            }
            if ($marker === 0xD9) {
                return null; // EOI sebelum SOS: tidak ada gambar
            }
            // Marker tanpa panjang (RSTn, TEM).
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $out .= "\xFF".chr($marker);

                continue;
            }

            if ($pos + 2 > $length) {
                return null;
            }
            $unpacked = unpack('n', substr($bytes, $pos, 2));
            $segmentLength = $unpacked === false ? 0 : (int) $unpacked[1];
            if ($segmentLength < 2 || $pos + $segmentLength > $length) {
                return null;
            }

            $isMetadata = ($marker >= 0xE0 && $marker <= 0xEF && ! in_array($marker, self::KEEP_APP, true)) || $marker === 0xFE;
            if (! $isMetadata) {
                $out .= "\xFF".chr($marker).substr($bytes, $pos, $segmentLength);
            }
            $pos += $segmentLength;
        }

        return null;
    }

    /** Simpan selfie bersih; path relatif disk privat, mis. `selfies/2026/11/01jc….jpg`. */
    public function store(UploadedFile $file, CarbonInterface $localDate): string
    {
        $clean = self::strip((string) $file->get());
        if ($clean === null) {
            // Sudah divalidasi request; sampai sini berarti berkas berubah di tengah jalan.
            throw new \RuntimeException('Selfie bukan JPEG yang sah.');
        }

        $path = sprintf('selfies/%s/%s.jpg', $localDate->format('Y/m'), strtolower((string) Str::ulid()));
        Storage::disk(self::DISK)->put($path, $clean);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
