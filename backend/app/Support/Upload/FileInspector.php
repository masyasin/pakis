<?php

namespace App\Support\Upload;

/**
 * Pemeriksa isi berkas unggahan (SEC-050, SEC-052, SEC-109). Jenis ditentukan dari BYTE berkas,
 * bukan ekstensi atau MIME kiriman klien: teks/HTML/skrip bernama `.jpg` tertolak. Diekstrak dari
 * SelfieStore (JPEG) dan CorrectionAttachment (PNG/PDF) di T-070 tanpa mengubah perilakunya;
 * WEBP ditambahkan untuk unggahan Paket A.
 */
final class FileInspector
{
    /**
     * Batas teknis dimensi gambar (bukan parameter bisnis): mencegah "bom dekompresi" — gambar kecil
     * yang mengaku 60 000 × 60 000 px membuat GD mengalokasikan memori raksasa (DoS).
     */
    public const MAX_SIDE_PX = 4096;

    public const MAX_PIXELS = 12_000_000;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Chunk PNG metadata yang dibuang: teks bebas, EXIF, waktu ubah. */
    private const PNG_DROP = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'];

    /** Segmen JPEG APPn yang dipertahankan: APP0 JFIF, APP2 ICC, APP14 Adobe. */
    private const JPEG_KEEP_APP = [0xE0, 0xE2, 0xEE];

    /**
     * @param  list<FileKind>  $allowed
     * @return FileKind|null jenis sah menurut isi dan termasuk `$allowed`, null = ditolak
     */
    public static function kind(string $bytes, array $allowed): ?FileKind
    {
        foreach ($allowed as $kind) {
            $genuine = match ($kind) {
                FileKind::Jpg => self::isGenuineJpeg($bytes),
                FileKind::Png => self::isGenuinePng($bytes),
                FileKind::Webp => self::isGenuineWebp($bytes),
                FileKind::Pdf => self::isPdf($bytes),
            };
            if ($genuine) {
                return $kind;
            }
        }

        return null;
    }

    /** JPEG sah: tanda tangan FFD8FF, header SOF terbaca, dimensi wajar, struktur utuh, bisa didekode. */
    public static function isGenuineJpeg(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return false;
        }

        return self::imageWithin($bytes, IMAGETYPE_JPEG) && self::stripJpeg($bytes) !== null && self::decodes($bytes);
    }

    public static function isGenuinePng(string $bytes): bool
    {
        if (! str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return false;
        }

        return self::imageWithin($bytes, IMAGETYPE_PNG) && self::stripPng($bytes) !== null && self::decodes($bytes);
    }

    /** WEBP sah: wadah RIFF....WEBP, header terbaca, dimensi wajar, bisa didekode GD. */
    public static function isGenuineWebp(string $bytes): bool
    {
        if (strlen($bytes) < 12 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return false;
        }

        return self::imageWithin($bytes, IMAGETYPE_WEBP) && self::decodes($bytes);
    }

    /** PDF: header `%PDF-1.x`/`2.0` di awal dan penanda `%%EOF` di akhir berkas. */
    public static function isPdf(string $bytes): bool
    {
        if (preg_match('/^%PDF-[12]\.\d/', $bytes) !== 1) {
            return false;
        }

        return str_contains(substr($bytes, -1024), '%%EOF');
    }

    /**
     * Buang segmen metadata JPEG TANPA re-encode (piksel bukti tidak berubah): APP1 (EXIF/XMP, GPS),
     * APP3–APP13/APP15, dan COM. Null bila struktur rusak.
     */
    public static function stripJpeg(string $bytes): ?string
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

            $isMetadata = ($marker >= 0xE0 && $marker <= 0xEF && ! in_array($marker, self::JPEG_KEEP_APP, true)) || $marker === 0xFE;
            if (! $isMetadata) {
                $out .= "\xFF".chr($marker).substr($bytes, $pos, $segmentLength);
            }
            $pos += $segmentLength;
        }

        return null;
    }

    /** Buang chunk metadata PNG; null bila struktur rusak (panjang chunk melewati akhir, tanpa IEND). */
    public static function stripPng(string $bytes): ?string
    {
        $out = self::PNG_SIGNATURE;
        $pos = strlen(self::PNG_SIGNATURE);
        $total = strlen($bytes);

        while ($pos + 12 <= $total) {
            $unpacked = unpack('N', substr($bytes, $pos, 4));
            $length = is_array($unpacked) ? (int) $unpacked[1] : -1;
            if ($length < 0 || $pos + 12 + $length > $total) {
                return null;
            }

            $type = substr($bytes, $pos + 4, 4);
            $chunk = substr($bytes, $pos, 12 + $length);
            if (! in_array($type, self::PNG_DROP, true)) {
                $out .= $chunk;
            }
            $pos += 12 + $length;

            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    /** Header gambar terbaca, jenisnya sesuai, dan dimensinya di dalam batas — dicek SEBELUM dekode. */
    private static function imageWithin(string $bytes, int $type): bool
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== $type) {
            return false;
        }

        [$width, $height] = $info;

        return $width >= 1 && $height >= 1 && $width <= self::MAX_SIDE_PX && $height <= self::MAX_SIDE_PX && $width * $height <= self::MAX_PIXELS;
    }

    private static function decodes(string $bytes): bool
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
        }
        imagedestroy($image);

        return true;
    }
}
