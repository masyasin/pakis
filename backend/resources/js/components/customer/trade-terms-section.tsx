import { FileText } from 'lucide-react';
import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Isian ketentuan dagang di form kios (string agar cocok dengan input). */
export type TradeTermsInput = {
    harvest_term_allowed: boolean;
    harvest_term_max_days: string;
    restricted_buyer_eligible: boolean;
    restricted_buyer_note: string;
    restricted_buyer_valid_until: string;
    restricted_buyer_document: File | null;
};

export type TradeTermsSource = {
    harvest_term_allowed: boolean;
    harvest_term_max_days: number | null;
    restricted_buyer_eligible: boolean;
    restricted_buyer_note: string | null;
    restricted_buyer_valid_until: string | null;
};

export function tradeTermsInitial(c: TradeTermsSource | null): TradeTermsInput {
    return {
        harvest_term_allowed: c?.harvest_term_allowed ?? false,
        harvest_term_max_days:
            c?.harvest_term_max_days !== null &&
            c?.harvest_term_max_days !== undefined
                ? String(c.harvest_term_max_days)
                : '',
        restricted_buyer_eligible: c?.restricted_buyer_eligible ?? false,
        restricted_buyer_note: c?.restricted_buyer_note ?? '',
        restricted_buyer_valid_until: c?.restricted_buyer_valid_until ?? '',
        restricted_buyer_document: null,
    };
}

/**
 * Isian ketentuan dagang → body `admin.customers.update` (T-074, BR-112). Batas hari tempo panen
 * dan masa berlaku kelayakan hanya dikirim bila ketentuannya aktif: tanggal lama yang sudah lewat
 * tidak boleh menggagalkan simpan kios yang kelayakannya baru dicabut. Berkas hanya ikut bila
 * dipilih; `multipart` memberi tahu pemanggil agar mengirim FormData (PUT lewat `_method`).
 */
export function tradeTermsPayload(input: TradeTermsInput): {
    data: Record<string, string | boolean | File | null>;
    multipart: boolean;
} {
    const days = input.harvest_term_max_days.trim();
    const note = input.restricted_buyer_note.trim();
    const data: Record<string, string | boolean | File | null> = {
        harvest_term_allowed: input.harvest_term_allowed,
        harvest_term_max_days:
            input.harvest_term_allowed && days !== '' ? days : null,
        restricted_buyer_eligible: input.restricted_buyer_eligible,
        restricted_buyer_note: note === '' ? null : note,
        restricted_buyer_valid_until:
            input.restricted_buyer_eligible &&
            input.restricted_buyer_valid_until !== ''
                ? input.restricted_buyer_valid_until
                : null,
    };
    const multipart = input.restricted_buyer_document !== null;
    if (multipart) {
        data.restricted_buyer_document = input.restricted_buyer_document;
    }

    return { data, multipart };
}

/**
 * Section "Ketentuan dagang" form ubah kios: tempo panen (BR-112 tempo sampai panen) dan kelayakan
 * pembeli pestisida terbatas (dokumen PDF/gambar, isi berkas diperiksa server SEC-109).
 */
export function TradeTermsSection({
    value,
    onChange,
    errors,
    documentUrl,
    today,
}: {
    value: TradeTermsInput;
    onChange: (next: Partial<TradeTermsInput>) => void;
    errors: Record<string, string | undefined>;
    /** Tautan dokumen tersimpan (bertanda tangan 5 menit), null = belum ada. */
    documentUrl: string | null;
    /** Tanggal lokal hari ini: batas bawah "berlaku sampai". */
    today?: string;
}) {
    return (
        <SettingsSection
            title="Ketentuan dagang"
            description="Tempo sampai panen dan kelayakan membeli pestisida terbatas. Perubahan tercatat di log audit."
        >
            <div className="grid gap-6">
                <div className="grid gap-3">
                    {/* Checkbox di LUAR <label>: klik ikon di dalam label memicu dua kali toggle. */}
                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="harvest_term_allowed"
                            className="mt-0.5"
                            checked={value.harvest_term_allowed}
                            onCheckedChange={(v) =>
                                onChange({ harvest_term_allowed: v === true })
                            }
                        />
                        <Label
                            htmlFor="harvest_term_allowed"
                            className="text-sm font-normal"
                        >
                            Boleh tempo panen: pembayaran jatuh tempo saat
                            panen, bukan tempo standar.
                        </Label>
                    </div>
                    {value.harvest_term_allowed && (
                        <div className="grid gap-2 pl-6 sm:max-w-56">
                            <Label htmlFor="harvest_term_max_days">
                                Tempo panen maksimal (hari)
                            </Label>
                            <Input
                                id="harvest_term_max_days"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={365}
                                placeholder="120"
                                value={value.harvest_term_max_days}
                                onChange={(e) =>
                                    onChange({
                                        harvest_term_max_days: e.target.value,
                                    })
                                }
                                aria-invalid={
                                    errors.harvest_term_max_days
                                        ? true
                                        : undefined
                                }
                            />
                            <InputError
                                message={errors.harvest_term_max_days}
                            />
                        </div>
                    )}
                    <InputError message={errors.harvest_term_allowed} />
                </div>

                <div className="grid gap-3">
                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="restricted_buyer_eligible"
                            className="mt-0.5"
                            checked={value.restricted_buyer_eligible}
                            onCheckedChange={(v) =>
                                onChange({
                                    restricted_buyer_eligible: v === true,
                                })
                            }
                        />
                        <Label
                            htmlFor="restricted_buyer_eligible"
                            className="text-sm font-normal"
                        >
                            Layak membeli pestisida terbatas (wajib ada dokumen
                            kelayakan yang masih berlaku).
                        </Label>
                    </div>
                    {value.restricted_buyer_eligible && (
                        <div className="grid gap-4 pl-6 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="restricted_buyer_valid_until">
                                    Berlaku sampai
                                </Label>
                                <Input
                                    id="restricted_buyer_valid_until"
                                    type="date"
                                    min={today}
                                    value={value.restricted_buyer_valid_until}
                                    onChange={(e) =>
                                        onChange({
                                            restricted_buyer_valid_until:
                                                e.target.value,
                                        })
                                    }
                                    aria-invalid={
                                        errors.restricted_buyer_valid_until
                                            ? true
                                            : undefined
                                    }
                                />
                                <InputError
                                    message={
                                        errors.restricted_buyer_valid_until
                                    }
                                />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="restricted_buyer_document">
                                    Dokumen kelayakan{' '}
                                    {documentUrl
                                        ? '(kosongkan bila tidak diganti)'
                                        : ''}
                                </Label>
                                <Input
                                    id="restricted_buyer_document"
                                    type="file"
                                    accept="application/pdf,image/jpeg,image/png,image/webp"
                                    onChange={(e) =>
                                        onChange({
                                            restricted_buyer_document:
                                                e.target.files?.[0] ?? null,
                                        })
                                    }
                                    aria-invalid={
                                        errors.restricted_buyer_document
                                            ? true
                                            : undefined
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    PDF atau foto (JPG, PNG, WEBP).
                                    {documentUrl && (
                                        <>
                                            {' '}
                                            <a
                                                href={documentUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                            >
                                                <FileText
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                Lihat dokumen tersimpan
                                            </a>{' '}
                                            (tautan berlaku 5 menit).
                                        </>
                                    )}
                                </p>
                                <InputError
                                    message={errors.restricted_buyer_document}
                                />
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="restricted_buyer_note">
                                    Catatan (opsional)
                                </Label>
                                <Input
                                    id="restricted_buyer_note"
                                    maxLength={255}
                                    placeholder="mis. sertifikat pelatihan aplikator Dinas Pertanian"
                                    value={value.restricted_buyer_note}
                                    onChange={(e) =>
                                        onChange({
                                            restricted_buyer_note:
                                                e.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={errors.restricted_buyer_note}
                                />
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </SettingsSection>
    );
}
