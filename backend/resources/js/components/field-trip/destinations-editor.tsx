import { MapPin, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MAPS_PARSE_MESSAGES, parseMapsLink } from '@/lib/maps-link';

export type DestinationInput = {
    name: string;
    latitude: string;
    longitude: string;
    radius_m: string;
};

export const emptyDestination = (): DestinationInput => ({
    name: '',
    latitude: '',
    longitude: '',
    radius_m: '',
});

type Props = {
    value: DestinationInput[];
    onChange: (value: DestinationInput[]) => void;
    /** Galat dari server, kunci `destinations` & `destinations.N.field`. */
    errors: Record<string, string | undefined>;
    max: number;
    defaultRadius: number;
};

/**
 * Titik tujuan tugas luar kota (BR-071; keputusan Mas 16 Nov 2026: satu tugas bisa beberapa
 * tempat). Tiap titik: nama tempat + koordinat dari tempel tautan Google Maps + radius opsional.
 */
export function DestinationsEditor({
    value,
    onChange,
    errors,
    max,
    defaultRadius,
}: Props) {
    const update = (i: number, patch: Partial<DestinationInput>) =>
        onChange(value.map((d, j) => (j === i ? { ...d, ...patch } : d)));

    return (
        <div className="space-y-4">
            {value.map((d, i) => (
                <DestinationRow
                    key={i}
                    index={i}
                    value={d}
                    canRemove={value.length > 1}
                    onChange={(patch) => update(i, patch)}
                    onRemove={() => onChange(value.filter((_, j) => j !== i))}
                    errors={errors}
                    defaultRadius={defaultRadius}
                />
            ))}
            <InputError message={errors.destinations} />
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={value.length >= max}
                onClick={() => onChange([...value, emptyDestination()])}
            >
                <Plus aria-hidden="true" />
                Tambah titik tujuan
            </Button>
            {value.length >= max && (
                <p className="text-xs text-muted-foreground">
                    Paling banyak {max} titik per tugas.
                </p>
            )}
        </div>
    );
}

function DestinationRow({
    index,
    value,
    canRemove,
    onChange,
    onRemove,
    errors,
    defaultRadius,
}: {
    index: number;
    value: DestinationInput;
    canRemove: boolean;
    onChange: (patch: Partial<DestinationInput>) => void;
    onRemove: () => void;
    errors: Record<string, string | undefined>;
    defaultRadius: number;
}) {
    const [link, setLink] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const id = (field: string) => `dest-${index}-${field}`;
    const err = (field: string) => errors[`destinations.${index}.${field}`];

    const applyLink = () => {
        const result = parseMapsLink(link);
        if (!result.ok) {
            setMessage(MAPS_PARSE_MESSAGES[result.reason]);

            return;
        }
        onChange({ latitude: result.latitude, longitude: result.longitude });
        setMessage(null);
        setLink('');
    };

    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <div className="flex items-center justify-between gap-2">
                <legend className="text-sm font-semibold">
                    Titik {index + 1}
                </legend>
                {canRemove && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={`Hapus titik ${index + 1}`}
                        onClick={onRemove}
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                )}
            </div>
            <div className="grid gap-2">
                <Label htmlFor={id('name')}>Nama tempat</Label>
                <Input
                    id={id('name')}
                    value={value.name}
                    maxLength={150}
                    autoComplete="off"
                    placeholder="Mis. Toko Tani Makmur, Semarang"
                    onChange={(e) => onChange({ name: e.target.value })}
                    aria-invalid={err('name') ? true : undefined}
                />
                <InputError message={err('name')} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={id('link')}>Tempel tautan Google Maps</Label>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Input
                        id={id('link')}
                        value={link}
                        placeholder="https://www.google.com/maps/place/…"
                        onChange={(e) => setLink(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                applyLink();
                            }
                        }}
                        aria-describedby={id('help')}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={link.trim() === ''}
                        onClick={applyLink}
                    >
                        <MapPin aria-hidden="true" />
                        Ambil koordinat
                    </Button>
                </div>
                <p
                    id={id('help')}
                    className={
                        message
                            ? 'text-xs text-destructive'
                            : 'text-xs text-muted-foreground'
                    }
                    role={message ? 'alert' : undefined}
                >
                    {message ??
                        'Buka tempat di Google Maps, salin alamat dari address bar, lalu tempel. Bisa juga angka "-6.98, 110.42".'}
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="grid content-start gap-2">
                    <Label htmlFor={id('latitude')}>Latitude</Label>
                    <Input
                        id={id('latitude')}
                        inputMode="decimal"
                        value={value.latitude}
                        className="tabular-nums"
                        onChange={(e) => onChange({ latitude: e.target.value })}
                        aria-invalid={err('latitude') ? true : undefined}
                    />
                    <InputError message={err('latitude')} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={id('longitude')}>Longitude</Label>
                    <Input
                        id={id('longitude')}
                        inputMode="decimal"
                        value={value.longitude}
                        className="tabular-nums"
                        onChange={(e) =>
                            onChange({ longitude: e.target.value })
                        }
                        aria-invalid={err('longitude') ? true : undefined}
                    />
                    <InputError message={err('longitude')} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={id('radius_m')}>Radius (meter)</Label>
                    <Input
                        id={id('radius_m')}
                        type="number"
                        min={20}
                        max={5000}
                        value={value.radius_m}
                        placeholder={String(defaultRadius)}
                        onChange={(e) => onChange({ radius_m: e.target.value })}
                        aria-invalid={err('radius_m') ? true : undefined}
                    />
                    <InputError message={err('radius_m')} />
                </div>
            </div>
        </fieldset>
    );
}
