import { Loader2, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Input } from '@/components/ui/input';
import { cachedLabel, fetchLookup, LOOKUP_DEBOUNCE_MS } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { cn } from '@/lib/utils';

type Value = Array<number | string>;

type Props = {
    /** Kunci sumber lookup, mis. `users` → `/lookup/users`. */
    source: string;
    /** Nilai terpilih (selalu array; mode tunggal memakai paling banyak satu). */
    value: Value;
    onChange: (value: Value) => void;
    multiple?: boolean;
    /** Parameter khusus sumber (mis. `{ level: 'district', parent: '33.19' }`). */
    params?: Record<string, string | undefined>;
    disabled?: boolean;
    id?: string;
    placeholder?: string;
    'aria-label'?: string;
};

/**
 * Select dengan pencarian ke server (07 §2, filter relasi R). Combobox ARIA tanpa dependency:
 * ↑/↓ memilih, Enter mengambil, Esc menutup, Backspace di isian kosong menghapus pilihan terakhir.
 * Opsi maksimal 20 dari server (BR-006), jadi tidak pernah memuat seluruh tabel.
 *
 * Mode tunggal tampil seperti select biasa: label terpilih ada DI DALAM kolom (+ tombol hapus);
 * saat kolom difokus untuk mencari, label itu menjadi placeholder. Mode banyak memakai chip.
 */
export function AsyncSelect({
    source,
    value,
    onChange,
    multiple = false,
    params,
    disabled = false,
    id,
    placeholder = 'Ketik untuk mencari…',
    'aria-label': ariaLabel,
}: Props) {
    const listId = useId();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState<LookupOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    // String stabil untuk dependensi effect (objek params baru tiap render).
    const paramKey = JSON.stringify(params ?? {});

    // Ambil opsi saat terbuka / kata kunci berubah (ditunda agar tidak tiap ketukan).
    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            fetchLookup(
                source,
                {
                    q: query.trim(),
                    extra: JSON.parse(paramKey) as Record<string, string>,
                },
                controller.signal,
            )
                .then((result) => {
                    setOptions(result);
                    setFailed(false);
                    setActive(0);
                })
                .catch((error: unknown) => {
                    if (!(error instanceof DOMException)) {
                        setFailed(true);
                    }
                })
                .finally(() => setLoading(false));
        }, LOOKUP_DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, source, paramKey]);

    const isSelected = (option: LookupOption) =>
        value.map(String).includes(String(option.value));

    const pick = (option: LookupOption) => {
        if (multiple) {
            onChange(
                isSelected(option)
                    ? value.filter((v) => String(v) !== String(option.value))
                    : [...value, option.value],
            );
            setQuery('');
            inputRef.current?.focus();
        } else {
            onChange([option.value]);
            setQuery('');
            setOpen(false);
        }
    };

    const remove = (item: number | string) =>
        onChange(value.filter((v) => String(v) !== String(item)));

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                setOpen(true);
                setActive((i) => Math.min(i + 1, options.length - 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                setActive((i) => Math.max(i - 1, 0));
                break;
            case 'Enter':
                if (open && options[active]) {
                    event.preventDefault();
                    pick(options[active]);
                }
                break;
            case 'Escape':
                if (open) {
                    event.preventDefault();
                    event.stopPropagation();
                    setOpen(false);
                }
                break;
            case 'Backspace':
                if (query === '' && value.length > 0) {
                    remove(value[value.length - 1]);
                }
                break;
        }
    };

    const selectedLabel =
        !multiple && value.length > 0
            ? (cachedLabel(source, value[0]) ?? `#${value[0]}`)
            : null;

    const status = failed
        ? 'Gagal memuat pilihan. Coba lagi.'
        : loading && options.length === 0
          ? 'Memuat…'
          : options.length === 0
            ? query.trim()
                ? 'Tidak ada hasil.'
                : 'Belum ada pilihan.'
            : null;

    return (
        <div className="relative grid gap-2">
            {multiple && value.length > 0 && (
                <ul className="flex flex-wrap gap-1.5" aria-label="Terpilih">
                    {value.map((item) => {
                        const label = cachedLabel(source, item) ?? `#${item}`;

                        return (
                            <li
                                key={String(item)}
                                className="inline-flex max-w-full items-center gap-1 rounded-full border bg-muted/60 py-0.5 pr-1 pl-2.5 text-xs"
                            >
                                <span className="truncate">{label}</span>
                                <button
                                    type="button"
                                    disabled={disabled}
                                    className="rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label={`Hapus pilihan ${label}`}
                                    onClick={() => remove(item)}
                                >
                                    <X
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="relative">
                <Input
                    ref={inputRef}
                    id={id}
                    role="combobox"
                    aria-label={ariaLabel}
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        open && options[active]
                            ? `${listId}-${active}`
                            : undefined
                    }
                    autoComplete="off"
                    disabled={disabled}
                    placeholder={
                        open && selectedLabel ? selectedLabel : placeholder
                    }
                    value={open ? query : (selectedLabel ?? query)}
                    className={cn(
                        selectedLabel && 'pr-9',
                        selectedLabel && !open && 'font-medium text-foreground',
                    )}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => !disabled && setOpen(true)}
                    onBlur={() => setOpen(false)}
                    onKeyDown={onKeyDown}
                />
                {loading ? (
                    <Loader2
                        className="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin text-muted-foreground"
                        aria-hidden="true"
                    />
                ) : (
                    selectedLabel &&
                    !disabled && (
                        <button
                            type="button"
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            aria-label={`Hapus pilihan ${selectedLabel}`}
                            // mousedown: jangan sampai input kehilangan fokus lalu membuka daftar.
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => {
                                onChange([]);
                                setQuery('');
                            }}
                        >
                            <X className="size-4" aria-hidden="true" />
                        </button>
                    )
                )}
            </div>

            {open && !disabled && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-multiselectable={multiple || undefined}
                    className="absolute top-full right-0 left-0 z-50 mt-1 max-h-64 overflow-y-auto rounded-lg border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {status ? (
                        <li className="px-2.5 py-2 text-sm text-muted-foreground">
                            {status}
                        </li>
                    ) : (
                        options.map((option, index) => (
                            <li
                                key={String(option.value)}
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={isSelected(option)}
                                // mousedown: pilih sebelum input kehilangan fokus (blur menutup daftar).
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    pick(option);
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={cn(
                                    'flex cursor-pointer items-baseline justify-between gap-3 rounded-md px-2.5 py-1.5 text-sm',
                                    index === active && 'bg-muted',
                                    isSelected(option) && 'font-medium',
                                )}
                            >
                                {/* Nama lebih penting daripada keterangan: keterangan yang dipotong bila sempit. */}
                                <span className="max-w-[70%] shrink-0 truncate">
                                    {option.label}
                                </span>
                                {option.hint && (
                                    <span className="min-w-0 truncate text-right text-xs text-muted-foreground">
                                        {option.hint}
                                    </span>
                                )}
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}
