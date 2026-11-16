<?php

namespace App\Support\Upload;

/** Hasil SecureUpload::store(): path relatif di disk tujuan, jenis, dan ukuran tersimpan. */
final class StoredUpload
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly FileKind $kind,
        public readonly int $bytes,
    ) {}
}
