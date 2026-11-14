import { useId } from 'react';
import { NativeSelect } from '@/components/pakis/native-select';

type Props = {
    value: number;
    options: number[];
    onChange: (perPage: number) => void;
};

export function PerPageSelect({ value, options, onChange }: Props) {
    const id = useId();

    return (
        <div className="flex items-center gap-2 text-sm">
            <NativeSelect
                id={id}
                className="h-8 w-auto"
                value={value}
                onChange={(e) => onChange(Number(e.target.value))}
            >
                {options.map((o) => (
                    <option key={o} value={o}>
                        {o}
                    </option>
                ))}
            </NativeSelect>
            <label
                htmlFor={id}
                className="whitespace-nowrap text-muted-foreground"
            >
                per halaman
            </label>
        </div>
    );
}
