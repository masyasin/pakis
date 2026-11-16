<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Validation\ValidationException;

/**
 * Penanda perilaku produk vs kategori (BR-151): boleh diperketat bebas; melonggarkan (kategori
 * mewajibkan, produk tidak) hanya dengan alasan tertulis yang dicatat di audit.
 */
final class ProductFlags
{
    /**
     * @param  array<string, mixed>  $flags  kolom produk (`tracks_batch`, …) => bool
     * @return list<string> penanda produk yang dilonggarkan dari kategori
     */
    public static function relaxed(ProductCategory $category, array $flags): array
    {
        $relaxed = [];
        foreach (Product::FLAGS as $productColumn => $categoryColumn) {
            if ($category->{$categoryColumn} === true && ($flags[$productColumn] ?? true) === false) {
                $relaxed[] = $productColumn;
            }
        }

        return $relaxed;
    }

    /**
     * @param  list<string>  $relaxed
     *
     * @throws ValidationException tanpa alasan saat ada penanda yang dilonggarkan
     */
    public static function assertReason(array $relaxed, ?string $reason): void
    {
        if ($relaxed !== [] && trim((string) $reason) === '') {
            throw ValidationException::withMessages([
                'flag_reason' => 'Kategori mewajibkan '.implode(', ', array_map(self::label(...), $relaxed)).'. Tulis alasan bila produk ini tidak mewajibkannya.',
            ]);
        }
    }

    public static function label(string $column): string
    {
        return match ($column) {
            'tracks_batch' => 'nomor batch',
            'tracks_expiry' => 'tanggal kedaluwarsa',
            'tracks_serial' => 'nomor seri',
            'requires_registration' => 'izin edar',
            default => $column,
        };
    }
}
