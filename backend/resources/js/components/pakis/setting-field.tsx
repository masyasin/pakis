import { Lock, RotateCcw } from 'lucide-react';
import { useId } from 'react';
import {
    groupRupiah as formatRupiah,
    MoneyInput,
} from '@/components/pakis/money-input';
import { NativeSelect } from '@/components/pakis/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** Label tampilan untuk nilai opsi setting (nilai tersimpan tetap kode huruf kecil). */
const OPTION_LABELS: Record<string, string> = {
    email: 'Email',
    whatsapp: 'WhatsApp',
    telegram: 'Telegram',
};

export type SettingValue =
    | string
    | number
    | boolean
    | string[]
    | number[]
    | null;

export type SettingFieldDef = {
    field: string;
    key: string;
    type:
        | 'string'
        | 'text'
        | 'int'
        | 'bool'
        | 'enum'
        | 'multi_enum'
        | 'time'
        | 'color'
        | 'image'
        | 'email'
        | 'url'
        | 'email_list'
        | 'decimal'
        | 'int_list'
        | 'money';
    label: string;
    help: string;
    value: SettingValue;
    default: SettingValue;
    customized: boolean;
    options: Array<string | number>;
    /** String desimal (mis. "0.5") hanya untuk tipe decimal/money (T-074). */
    min: number | string | null;
    max: number | string | null;
    nullable: boolean;
    public: boolean;
    /** PA-19: hanya super_admin yang boleh mengubah (server menolak selainnya). */
    locked: boolean;
    /** PA-19: bool yang hanya boleh dimatikan; setelah mati tidak bisa dinyalakan lagi. */
    one_way: boolean;
    image_url: string | null;
};

type Props = {
    def: SettingFieldDef;
    value: SettingValue;
    onChange: (value: SettingValue) => void;
    onFile: (file: File | null) => void;
    onReset: () => void;
    error?: string;
    disabled?: boolean;
    /** Pengguna boleh mengubah field `locked` (super_admin). */
    canChangeLocked?: boolean;
};

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

// Rupiah dipindah ke `money-input.tsx` (T-074, dipakai juga di luar Pengaturan); nama lama tetap
// diekspor dari sini agar impor yang sudah ada tidak berubah.
export { parseRupiah } from '@/components/pakis/money-input';
export { formatRupiah, MoneyInput };

/** Nilai untuk teks "Default: …". */
export function describeSetting(
    value: SettingValue,
    type?: SettingFieldDef['type'],
): string {
    if (value === null || value === '') {
        return 'kosong';
    }
    if (type === 'money' && typeof value === 'string') {
        const [intPart, frac] = value.split('.');
        const sen = frac && /[1-9]/.test(frac) ? `,${frac}` : '';

        return `Rp ${formatRupiah(intPart)}${sen}`;
    }
    if (type === 'decimal' && typeof value === 'string') {
        return value.replace('.', ',');
    }
    if (typeof value === 'boolean') {
        return value ? 'Ya' : 'Tidak';
    }
    if (Array.isArray(value)) {
        return value.length ? value.join(', ') : 'kosong';
    }

    return String(value);
}

/** Teks penjelas untuk field berpenanda (PA-19); null bila tidak ada. */
function flagNote(
    def: SettingFieldDef,
    lockedOut: boolean,
    closed: boolean,
): string | null {
    if (lockedOut) {
        return 'Terkunci: hanya Super Admin yang bisa mengubah pengaturan ini.';
    }
    if (def.locked) {
        return 'Pengaturan terkunci: perubahan butuh konfirmasi password dan tercatat di audit log.';
    }
    if (closed) {
        return 'Sudah dimatikan dan tidak bisa diaktifkan lagi.';
    }
    if (def.one_way) {
        return 'Hanya bisa dimatikan. Setelah dimatikan, tidak bisa diaktifkan lagi.';
    }

    return null;
}

/**
 * Satu field Pengaturan, dirender dari tipe registri (03 §4.2: form dibuat otomatis).
 */
export function SettingField({
    def,
    value,
    onChange,
    onFile,
    onReset,
    error,
    disabled: formDisabled,
    canChangeLocked = false,
}: Props) {
    const id = useId();
    const errorId = `${id}-error`;
    const describedBy = error ? errorId : undefined;
    // Server tetap menegakkan kedua penanda; di sini hanya mencegah isian yang pasti ditolak.
    const lockedOut = def.locked && !canChangeLocked;
    const closed = def.one_way && def.value === false;
    const disabled = formDisabled || lockedOut || closed;
    const note = flagNote(def, lockedOut, closed);

    const control = (() => {
        switch (def.type) {
            case 'bool':
                return (
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={id}
                            checked={value === true}
                            disabled={disabled}
                            aria-describedby={describedBy}
                            onCheckedChange={(state) =>
                                onChange(state === true)
                            }
                        />
                        <Label htmlFor={id} className="font-normal">
                            Aktif
                        </Label>
                    </div>
                );
            case 'enum':
                return (
                    <NativeSelect
                        id={id}
                        value={String(value ?? '')}
                        disabled={disabled}
                        aria-describedby={describedBy}
                        onChange={(e) => onChange(e.target.value)}
                    >
                        {def.options.map((o) => (
                            <option key={o} value={o}>
                                {o}
                            </option>
                        ))}
                    </NativeSelect>
                );
            case 'multi_enum': {
                const selected = Array.isArray(value) ? value.map(String) : [];

                return (
                    <div
                        className="grid gap-2"
                        role="group"
                        aria-labelledby={`${id}-label`}
                    >
                        {def.options.map((o) => (
                            <div key={o} className="flex items-center gap-2">
                                <Checkbox
                                    id={`${id}-${o}`}
                                    checked={selected.includes(String(o))}
                                    disabled={disabled}
                                    onCheckedChange={(state) =>
                                        onChange(
                                            state
                                                ? [...selected, String(o)]
                                                : selected.filter(
                                                      (v) => v !== String(o),
                                                  ),
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`${id}-${o}`}
                                    className="font-normal"
                                >
                                    {OPTION_LABELS[String(o)] ?? o}
                                </Label>
                            </div>
                        ))}
                    </div>
                );
            }
            case 'text':
            case 'email_list':
                return (
                    <textarea
                        id={id}
                        className={textareaClass}
                        disabled={disabled}
                        aria-describedby={describedBy}
                        value={
                            Array.isArray(value)
                                ? value.join('\n')
                                : String(value ?? '')
                        }
                        onChange={(e) => onChange(e.target.value)}
                    />
                );
            case 'color':
                return (
                    <div className="flex items-center gap-2">
                        <input
                            type="color"
                            aria-label={`${def.label} (pemilih warna)`}
                            className="h-9 w-12 cursor-pointer rounded-md border border-input bg-transparent p-1"
                            value={String(value ?? '#000000')}
                            disabled={disabled}
                            onChange={(e) =>
                                onChange(e.target.value.toUpperCase())
                            }
                        />
                        <Input
                            id={id}
                            className="w-32 font-mono uppercase"
                            value={String(value ?? '')}
                            disabled={disabled}
                            aria-describedby={describedBy}
                            onChange={(e) => onChange(e.target.value)}
                        />
                    </div>
                );
            case 'image':
                return (
                    <div className="flex flex-wrap items-center gap-3">
                        {def.image_url ? (
                            <img
                                src={def.image_url}
                                alt={def.label}
                                className="h-12 max-w-40 rounded border bg-muted/40 object-contain p-1"
                            />
                        ) : (
                            <span className="text-sm text-muted-foreground">
                                Memakai bawaan
                            </span>
                        )}
                        <Input
                            id={id}
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            className="max-w-72"
                            disabled={disabled}
                            aria-describedby={describedBy}
                            onChange={(e) =>
                                onFile(e.target.files?.[0] ?? null)
                            }
                        />
                    </div>
                );
            case 'money':
                return (
                    <MoneyInput
                        id={id}
                        value={typeof value === 'string' ? value : ''}
                        disabled={disabled}
                        describedBy={describedBy}
                        onChange={onChange}
                    />
                );
            case 'decimal':
                // Tampil dengan koma (id-ID), dikirim dengan titik (bentuk yang divalidasi server).
                return (
                    <Input
                        id={id}
                        inputMode="decimal"
                        className="w-40"
                        value={String(value ?? '').replace('.', ',')}
                        disabled={disabled}
                        aria-describedby={describedBy}
                        onChange={(e) =>
                            onChange(e.target.value.replace(',', '.'))
                        }
                    />
                );
            case 'int_list':
                return (
                    <Input
                        id={id}
                        inputMode="numeric"
                        placeholder="mis. 90, 60, 30"
                        className="max-w-72"
                        value={
                            Array.isArray(value)
                                ? value.join(', ')
                                : String(value ?? '')
                        }
                        disabled={disabled}
                        aria-describedby={describedBy}
                        onChange={(e) => onChange(e.target.value)}
                    />
                );
            default:
                return (
                    <Input
                        id={id}
                        type={
                            def.type === 'int'
                                ? 'number'
                                : def.type === 'time'
                                  ? 'time'
                                  : def.type === 'email'
                                    ? 'email'
                                    : def.type === 'url'
                                      ? 'url'
                                      : 'text'
                        }
                        min={
                            def.type === 'int'
                                ? (def.min ?? undefined)
                                : undefined
                        }
                        max={
                            def.type === 'int'
                                ? (def.max ?? undefined)
                                : undefined
                        }
                        className={def.type === 'int' ? 'w-40' : undefined}
                        value={String(value ?? '')}
                        disabled={disabled}
                        aria-describedby={describedBy}
                        onChange={(e) => onChange(e.target.value)}
                    />
                );
        }
    })();

    return (
        <div className={cn('grid gap-2 py-4', error && 'rounded-md')}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Label
                    id={`${id}-label`}
                    htmlFor={id}
                    className="text-sm font-medium"
                >
                    {def.label}
                    {def.public && (
                        <span className="ml-2 rounded bg-status-info-bg px-1.5 py-0.5 text-[10px] font-normal text-status-info">
                            dikirim ke aplikasi
                        </span>
                    )}
                    {def.locked && (
                        <span className="ml-2 inline-flex items-center gap-1 rounded bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-normal text-status-warning">
                            <Lock className="size-3" aria-hidden />
                            terkunci
                        </span>
                    )}
                </Label>
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <span>
                        Default: {describeSetting(def.default, def.type)}
                    </span>
                    {def.customized && !disabled && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={onReset}
                        >
                            <RotateCcw className="size-3.5" aria-hidden />
                            Kembalikan default
                        </Button>
                    )}
                </div>
            </div>
            {control}
            {def.help && (
                <p className="text-xs text-muted-foreground">{def.help}</p>
            )}
            {note && (
                <p className="inline-flex items-center gap-1.5 text-xs text-status-warning">
                    <Lock className="size-3.5 shrink-0" aria-hidden />
                    {note}
                </p>
            )}
            {error && (
                <p id={errorId} className="text-sm text-status-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
