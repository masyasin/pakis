/**
 * Bentuk prop listing dari backend (05 §3.2), dibuat oleh ListingResult::toInertia().
 * Rujukan bentuk persis: tests/Unit/Listing/SerializationTest.php.
 */

export type ListingOption = { value: string; label: string };

type FilterBase = {
    key: string;
    label: string;
    /** Filter wajib (mis. grup referensi): tidak bisa dikosongkan, tidak ada opsi "Semua". */
    required?: boolean;
};

export type SelectFilterSpec = FilterBase & {
    type: 'select';
    options?: ListingOption[];
    /** Opsi diambil dari endpoint lookup (filter relasi), bukan dikirim di spec. */
    options_source?: string | null;
    default?: string | number;
};

export type MultiSelectFilterSpec = FilterBase & {
    type: 'multi_select';
    options?: ListingOption[];
    options_source?: string | null;
    default?: Array<string | number>;
};

export type BooleanFilterSpec = FilterBase & {
    type: 'boolean';
    default?: boolean;
};

export type DateRangeValue = { from?: string; to?: string };

export type DateRangeFilterSpec = FilterBase & {
    type: 'date_range';
    default?: DateRangeValue;
};

export type NumberRangeValue = { min?: number; max?: number };

export type NumberRangeFilterSpec = FilterBase & {
    type: 'number_range';
    default?: NumberRangeValue;
};

export type FilterSpec =
    | SelectFilterSpec
    | MultiSelectFilterSpec
    | BooleanFilterSpec
    | DateRangeFilterSpec
    | NumberRangeFilterSpec;

export type FilterValue =
    | string
    | number
    | boolean
    | Array<string | number>
    | DateRangeValue
    | NumberRangeValue;

export type SortSpec = { key: string; label: string };

export type ListingSpec = {
    key: string;
    search_placeholder: string;
    per_page_options: number[];
    /** `key` naik, `-key` turun. */
    default_sort: string;
    sorts: SortSpec[];
    filters: FilterSpec[];
    exportable: boolean;
};

export type ListingState = {
    q: string;
    sort: string;
    filters: Record<string, FilterValue>;
    invalid_filters: Record<string, string>;
};

export type ListingPagination = {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
};

export type Listing = {
    pagination: ListingPagination;
    state: ListingState;
    spec: ListingSpec;
};

/** Prop halaman daftar: `rows` + `listing`. */
export type ListingProps<Row> = {
    rows: Row[];
    listing: Listing;
};
