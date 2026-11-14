import { useId } from 'react';
import { NativeSelect } from '@/components/pakis/native-select';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    DateRangeValue,
    FilterSpec,
    FilterValue,
    NumberRangeValue,
} from '@/types/listing';

type FieldProps = {
    filter: FilterSpec;
    value: FilterValue | undefined;
    onChange: (value: FilterValue | null) => void;
    error?: string;
};

const toNumber = (raw: string): number | undefined =>
    raw === '' ? undefined : Number(raw);

/** Satu field filter, dirender otomatis dari tipe di spec (07 §2: FilterBar dibuat dari spec). */
export function FilterField({ filter, value, onChange, error }: FieldProps) {
    const id = useId();
    const usesLookup = 'options_source' in filter && !!filter.options_source;

    const control = (() => {
        switch (filter.type) {
            case 'select':
                if (usesLookup) {
                    // TODO(T-011): ganti dengan pencarian dari endpoint lookup `options_source`.
                    return (
                        <Input
                            id={id}
                            inputMode="numeric"
                            placeholder="ID"
                            value={(value as string | number | undefined) ?? ''}
                            onChange={(e) => onChange(e.target.value || null)}
                        />
                    );
                }

                return (
                    <NativeSelect
                        id={id}
                        value={(value as string | undefined) ?? ''}
                        onChange={(e) => onChange(e.target.value || null)}
                    >
                        {!filter.required && <option value="">Semua</option>}
                        {filter.options?.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                );

            case 'multi_select': {
                const selected = ((value as Array<string | number>) ?? []).map(
                    String,
                );

                if (usesLookup) {
                    // TODO(T-011): pemilih multi dari lookup.
                    return (
                        <Input
                            id={id}
                            placeholder="ID, pisahkan dengan koma"
                            value={selected.join(',')}
                            onChange={(e) => {
                                const ids = e.target.value
                                    .split(',')
                                    .map((s) => s.trim())
                                    .filter(Boolean);
                                onChange(ids.length ? ids : null);
                            }}
                        />
                    );
                }

                return (
                    <div
                        id={id}
                        className="grid gap-2"
                        role="group"
                        aria-label={filter.label}
                    >
                        {filter.options?.map((o) => {
                            const checkboxId = `${id}-${o.value}`;
                            const checked = selected.includes(o.value);

                            return (
                                <div
                                    key={o.value}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={checkboxId}
                                        checked={checked}
                                        onCheckedChange={(state) => {
                                            const next = state
                                                ? [...selected, o.value]
                                                : selected.filter(
                                                      (v) => v !== o.value,
                                                  );
                                            onChange(next.length ? next : null);
                                        }}
                                    />
                                    <Label
                                        htmlFor={checkboxId}
                                        className="font-normal"
                                    >
                                        {o.label}
                                    </Label>
                                </div>
                            );
                        })}
                    </div>
                );
            }

            case 'boolean':
                return (
                    <NativeSelect
                        id={id}
                        value={value === undefined ? '' : value ? '1' : '0'}
                        onChange={(e) =>
                            onChange(
                                e.target.value === ''
                                    ? null
                                    : e.target.value === '1',
                            )
                        }
                    >
                        <option value="">Semua</option>
                        <option value="1">Ya</option>
                        <option value="0">Tidak</option>
                    </NativeSelect>
                );

            case 'date_range': {
                const range = (value as DateRangeValue | undefined) ?? {};
                const update = (patch: DateRangeValue) => {
                    const next = { ...range, ...patch };
                    onChange(next.from || next.to ? next : null);
                };

                return (
                    <div className="grid grid-cols-2 gap-2">
                        <Input
                            id={id}
                            type="date"
                            aria-label={`${filter.label} dari`}
                            value={range.from ?? ''}
                            onChange={(e) =>
                                update({ from: e.target.value || undefined })
                            }
                        />
                        <Input
                            type="date"
                            aria-label={`${filter.label} sampai`}
                            value={range.to ?? ''}
                            onChange={(e) =>
                                update({ to: e.target.value || undefined })
                            }
                        />
                    </div>
                );
            }

            case 'number_range': {
                const range = (value as NumberRangeValue | undefined) ?? {};
                const update = (patch: NumberRangeValue) => {
                    const next = { ...range, ...patch };
                    onChange(
                        next.min !== undefined || next.max !== undefined
                            ? next
                            : null,
                    );
                };

                return (
                    <div className="grid grid-cols-2 gap-2">
                        <Input
                            id={id}
                            type="number"
                            aria-label={`${filter.label} minimum`}
                            placeholder="Min"
                            value={range.min ?? ''}
                            onChange={(e) =>
                                update({ min: toNumber(e.target.value) })
                            }
                        />
                        <Input
                            type="number"
                            aria-label={`${filter.label} maksimum`}
                            placeholder="Maks"
                            value={range.max ?? ''}
                            onChange={(e) =>
                                update({ max: toNumber(e.target.value) })
                            }
                        />
                    </div>
                );
            }
        }
    })();

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{filter.label}</Label>
            {control}
            {error && <p className="text-xs text-status-warning">{error}</p>}
        </div>
    );
}
