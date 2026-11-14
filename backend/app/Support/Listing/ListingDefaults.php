<?php

namespace App\Support\Listing;

/**
 * Angka standar listing. Yang berasal dari aturan tetap (BR-001–BR-003) adalah konstanta;
 * yang berasal dari registri setting (`ui.*`) dibaca lewat method agar mudah dialihkan di T-008.
 */
final class ListingDefaults
{
    /** BR-001: opsi per halaman web dan mobile. */
    public const WEB_PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public const API_PER_PAGE_OPTIONS = [20, 50];

    public const API_DEFAULT_PER_PAGE = 20;

    /** BR-002 */
    public const SEARCH_MAX_LENGTH = 100;

    public const SEARCH_MIN_LENGTH = 2;

    /** BR-003 */
    public const DATE_RANGE_MAX_DAYS = 366;

    /** Batas jumlah ID pada filter relasi, mencegah query whereIn raksasa dari URL. */
    public const RELATION_MAX_IDS = 50;

    public static function webDefaultPerPage(): int
    {
        return (int) setting('ui.default_per_page');
    }

    public static function maxPerPage(): int
    {
        return (int) setting('ui.max_per_page');
    }
}
