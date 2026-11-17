import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Power, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { NativeSelect } from '@/components/pakis/native-select';
import { StatusBadge } from '@/components/pakis/status-badge';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { PROMO_TONE } from './index';

type Option = { value: string; label: string };
type Ref = { type: string; id: number | null };
type Tier = {
    min_qty_base: string;
    min_amount: string;
    discount_pct: string;
    discount_amount: string;
    bundle_price: string;
    free_product_id: string;
    free_qty_base: string;
};

type Promo = {
    id: number;
    code: string;
    name: string;
    type: string;
    starts_at: string;
    ends_at: string;
    priority: number;
    is_stackable: boolean;
    channels: string[];
    budget_amount: string | null;
    budget_used: string;
    funded_by: string;
    principal_id: number | null;
    principal_share_pct: string | null;
    description: string | null;
    status: keyof typeof PROMO_TONE;
    status_label: string;
    approval_request_id: number | null;
    targets: Ref[];
    scopes: Ref[];
    tiers: Record<keyof Tier, string | number | null>[];
};

type Props = {
    promotion: Promo | null;
    labels: Record<string, LookupOption[]>;
    types: Option[];
    fundings: Option[];
    channels: Option[];
    nowLocal: string;
    can: { manage: boolean; approve: boolean };
};

const EMPTY_TIER: Tier = {
    min_qty_base: '1',
    min_amount: '',
    discount_pct: '',
    discount_amount: '',
    bundle_price: '',
    free_product_id: '',
    free_qty_base: '',
};

/** Angka desimal server ("10.0000") → tampilan ringkas ("10"); kosong tetap kosong. */
const trim = (v: string | number | null | undefined) =>
    v === null || v === undefined
        ? ''
        : String(v)
              .replace(/(\.\d*?)0+$/, '$1')
              .replace(/\.$/, '');

const ids = (refs: Ref[], type: string) =>
    refs.filter((r) => r.type === type).map((r) => String(r.id));

const TARGETS = [
    { type: 'product', source: 'products', label: 'Produk' },
    { type: 'category', source: 'product_categories', label: 'Kategori' },
    { type: 'principal', source: 'principals', label: 'Prinsipal' },
] as const;

const SCOPES = [
    { type: 'price_list', source: 'price_lists', label: 'Daftar harga kios' },
    { type: 'sales_area', source: 'sales_areas', label: 'Area penjualan' },
    { type: 'customer', source: 'customers', label: 'Kios tertentu' },
] as const;

/** Tambah/ubah promo draf; detail hanya-baca untuk promo yang sudah diajukan/aktif/berakhir (FR-A20). */
export default function PromotionForm({
    promotion,
    labels,
    types,
    fundings,
    channels,
    nowLocal,
    can,
}: Props) {
    useState(() => {
        Object.entries(labels).forEach(([source, options]) =>
            rememberLabels(source, options),
        );

        return true;
    });
    const editable =
        can.manage && (promotion === null || promotion.status === 'draft');
    const form = useForm({
        code: promotion?.code ?? '',
        name: promotion?.name ?? '',
        type: promotion?.type ?? 'percent_discount',
        starts_at: promotion?.starts_at ?? nowLocal,
        ends_at: promotion?.ends_at ?? '',
        priority: String(promotion?.priority ?? 100),
        is_stackable: promotion?.is_stackable ?? false,
        channels: promotion?.channels ?? ['office', 'sales'],
        budget_amount: trim(promotion?.budget_amount),
        funded_by: promotion?.funded_by ?? 'company',
        principal_id: promotion?.principal_id
            ? String(promotion.principal_id)
            : '',
        principal_share_pct: trim(promotion?.principal_share_pct),
        description: promotion?.description ?? '',
        targets: Object.fromEntries(
            TARGETS.map((t) => [t.type, ids(promotion?.targets ?? [], t.type)]),
        ) as Record<string, string[]>,
        scopeAll: promotion
            ? promotion.scopes.some((s) => s.type === 'all')
            : true,
        scopes: Object.fromEntries(
            SCOPES.map((s) => [s.type, ids(promotion?.scopes ?? [], s.type)]),
        ) as Record<string, string[]>,
        tiers: (promotion?.tiers ?? [EMPTY_TIER]).map(
            (t) =>
                Object.fromEntries(
                    Object.keys(EMPTY_TIER).map((k) => [
                        k,
                        trim(t[k as keyof Tier]),
                    ]),
                ) as Tier,
        ),
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string | undefined>;
    const type = data.type;

    const setTier = (i: number, patch: Partial<Tier>) =>
        setData(
            'tiers',
            data.tiers.map((t, idx) => (idx === i ? { ...t, ...patch } : t)),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            code: d.code,
            name: d.name,
            type: d.type,
            starts_at: d.starts_at,
            ends_at: d.ends_at,
            priority: d.priority,
            is_stackable: d.is_stackable,
            channels: d.channels,
            budget_amount: d.budget_amount,
            funded_by: d.funded_by,
            principal_id:
                d.funded_by === 'company' || d.principal_id === ''
                    ? null
                    : Number(d.principal_id),
            principal_share_pct:
                d.funded_by === 'shared' ? d.principal_share_pct : null,
            description: d.description,
            targets: TARGETS.flatMap((t) =>
                d.targets[t.type].map((id) => ({
                    type: t.type,
                    id: Number(id),
                })),
            ),
            scopes: d.scopeAll
                ? [{ type: 'all', id: null }]
                : SCOPES.flatMap((s) =>
                      d.scopes[s.type].map((id) => ({
                          type: s.type,
                          id: Number(id),
                      })),
                  ),
            tiers: d.tiers,
        }));
        if (promotion) {
            form.put(`/promo/${promotion.id}`, { preserveScroll: true });
        } else {
            form.post('/promo', { preserveScroll: true });
        }
    };

    const act = (path: string) =>
        router.post(
            `/promo/${promotion?.id}/${path}`,
            {},
            { preserveScroll: true },
        );
    const title = promotion ? `${promotion.name}` : 'Tambah Promo';
    const tierHint: Record<string, string> = {
        percent_discount:
            'Diskon persen untuk semua baris target bila jumlah/belanja minimal terpenuhi.',
        amount_discount: 'Potongan rupiah per satuan dasar (mis. per botol).',
        tiered_discount:
            'Tingkat dengan ambang terbesar yang terpenuhi yang dipakai.',
        buy_x_get_y:
            'Setiap X satuan dasar yang dibeli mendapat Y satuan dasar produk gratis.',
        bundle_price: 'Setiap N satuan dasar dibayar dengan harga bundel.',
    };

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6"
            >
                <Link
                    href="/promo"
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Daftar promo
                </Link>
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1.5">
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            {title}
                        </h1>
                        {promotion && (
                            <StatusBadge
                                status={promotion.status}
                                label={promotion.status_label}
                                tone={PROMO_TONE[promotion.status]}
                            />
                        )}
                    </div>
                    {promotion && can.manage && (
                        <div className="flex gap-2">
                            {promotion.status === 'draft' && (
                                <Button
                                    type="button"
                                    onClick={() => act('ajukan')}
                                >
                                    <Send aria-hidden="true" />
                                    {can.approve
                                        ? 'Aktifkan Promo'
                                        : 'Ajukan Promo'}
                                </Button>
                            )}
                            {promotion.status === 'active' && (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() => act('akhiri')}
                                >
                                    <Power aria-hidden="true" />
                                    Akhiri Promo
                                </Button>
                            )}
                        </div>
                    )}
                </header>
                {promotion && promotion.status !== 'draft' && (
                    <p className="text-sm text-muted-foreground">
                        Promo yang sudah diajukan, aktif, atau berakhir tidak
                        bisa diubah. Promo aktif hanya bisa diakhiri.
                    </p>
                )}

                <fieldset disabled={!editable} className="space-y-6">
                    <SettingsSection title="Identitas & periode">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="promo-code">Kode</Label>
                                <Input
                                    id="promo-code"
                                    value={data.code}
                                    onChange={(e) =>
                                        setData(
                                            'code',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    className="font-mono"
                                    maxLength={30}
                                />
                                <InputError message={err.code} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="promo-name">Nama</Label>
                                <Input
                                    id="promo-name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    maxLength={150}
                                />
                                <InputError message={err.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="promo-type">Tipe</Label>
                                <NativeSelect
                                    id="promo-type"
                                    value={data.type}
                                    onChange={(e) =>
                                        setData((d) => ({
                                            ...d,
                                            type: e.target.value,
                                            tiers:
                                                e.target.value ===
                                                'tiered_discount'
                                                    ? [
                                                          EMPTY_TIER,
                                                          {
                                                              ...EMPTY_TIER,
                                                              min_qty_base:
                                                                  '10',
                                                          },
                                                      ]
                                                    : [EMPTY_TIER],
                                        }))
                                    }
                                >
                                    {types.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="promo-priority">
                                    Prioritas
                                </Label>
                                <Input
                                    id="promo-priority"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    value={data.priority}
                                    onChange={(e) =>
                                        setData('priority', e.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Angka kecil diproses lebih dulu.
                                </p>
                                <InputError message={err.priority} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="promo-start">Mulai</Label>
                                <Input
                                    id="promo-start"
                                    type="datetime-local"
                                    value={data.starts_at}
                                    onChange={(e) =>
                                        setData('starts_at', e.target.value)
                                    }
                                />
                                <InputError message={err.starts_at} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="promo-end">Selesai</Label>
                                <Input
                                    id="promo-end"
                                    type="datetime-local"
                                    value={data.ends_at}
                                    onChange={(e) =>
                                        setData('ends_at', e.target.value)
                                    }
                                />
                                <InputError message={err.ends_at} />
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                            <span className="text-sm font-medium">Kanal:</span>
                            {channels.map((c) => (
                                <label
                                    key={c.value}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={data.channels.includes(
                                            c.value,
                                        )}
                                        onCheckedChange={(v) =>
                                            setData(
                                                'channels',
                                                v === true
                                                    ? [
                                                          ...data.channels,
                                                          c.value,
                                                      ]
                                                    : data.channels.filter(
                                                          (x) => x !== c.value,
                                                      ),
                                            )
                                        }
                                    />
                                    {c.label}
                                </label>
                            ))}
                        </div>
                        <InputError message={err.channels} />
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.is_stackable}
                                onCheckedChange={(v) =>
                                    setData('is_stackable', v === true)
                                }
                            />
                            Bisa digabung dengan promo lain
                        </label>
                    </SettingsSection>

                    <SettingsSection
                        title="Target produk"
                        description="Promo berlaku untuk produk, kategori, atau prinsipal yang dipilih (salah satu cocok sudah cukup)."
                    >
                        <div className="grid gap-4 sm:grid-cols-3">
                            {TARGETS.map((t) => (
                                <div key={t.type} className="grid gap-2">
                                    <Label htmlFor={`promo-target-${t.type}`}>
                                        {t.label}
                                    </Label>
                                    <AsyncSelect
                                        id={`promo-target-${t.type}`}
                                        source={t.source}
                                        multiple
                                        value={data.targets[t.type]}
                                        onChange={(v) =>
                                            setData('targets', {
                                                ...data.targets,
                                                [t.type]: v.map(String),
                                            })
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                        <InputError
                            message={err.targets ?? err['targets.0.id']}
                        />
                    </SettingsSection>

                    <SettingsSection title="Cakupan pembeli">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.scopeAll}
                                onCheckedChange={(v) =>
                                    setData('scopeAll', v === true)
                                }
                            />
                            Semua pembeli
                        </label>
                        {!data.scopeAll && (
                            <div className="grid gap-4 sm:grid-cols-3">
                                {SCOPES.map((s) => (
                                    <div key={s.type} className="grid gap-2">
                                        <Label
                                            htmlFor={`promo-scope-${s.type}`}
                                        >
                                            {s.label}
                                        </Label>
                                        <AsyncSelect
                                            id={`promo-scope-${s.type}`}
                                            source={s.source}
                                            multiple
                                            value={data.scopes[s.type]}
                                            onChange={(v) =>
                                                setData('scopes', {
                                                    ...data.scopes,
                                                    [s.type]: v.map(String),
                                                })
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        )}
                        <InputError message={err.scopes} />
                    </SettingsSection>

                    <SettingsSection
                        title="Aturan promo"
                        description={tierHint[type]}
                    >
                        <ul className="space-y-3">
                            {data.tiers.map((t, i) => (
                                <li
                                    key={i}
                                    className="grid gap-3 rounded-lg border p-3 sm:grid-cols-3"
                                >
                                    {type !== 'bundle_price' &&
                                        type !== 'buy_x_get_y' && (
                                            <TierInput
                                                label="Jumlah minimal (satuan dasar)"
                                                value={t.min_qty_base}
                                                error={
                                                    err[
                                                        `tiers.${i}.min_qty_base`
                                                    ]
                                                }
                                                onChange={(v) =>
                                                    setTier(i, {
                                                        min_qty_base: v,
                                                    })
                                                }
                                            />
                                        )}
                                    {(type === 'bundle_price' ||
                                        type === 'buy_x_get_y') && (
                                        <TierInput
                                            label={
                                                type === 'bundle_price'
                                                    ? 'Isi per bundel (satuan dasar)'
                                                    : 'Beli (X, satuan dasar)'
                                            }
                                            value={t.min_qty_base}
                                            error={
                                                err[`tiers.${i}.min_qty_base`]
                                            }
                                            onChange={(v) =>
                                                setTier(i, { min_qty_base: v })
                                            }
                                        />
                                    )}
                                    {type !== 'bundle_price' &&
                                        type !== 'buy_x_get_y' && (
                                            <TierInput
                                                label="Belanja minimal (Rp, opsional)"
                                                value={t.min_amount}
                                                onChange={(v) =>
                                                    setTier(i, {
                                                        min_amount: v,
                                                    })
                                                }
                                            />
                                        )}
                                    {(type === 'percent_discount' ||
                                        type === 'tiered_discount') && (
                                        <TierInput
                                            label="Diskon (%)"
                                            value={t.discount_pct}
                                            error={
                                                err[`tiers.${i}.discount_pct`]
                                            }
                                            onChange={(v) =>
                                                setTier(i, { discount_pct: v })
                                            }
                                        />
                                    )}
                                    {(type === 'amount_discount' ||
                                        type === 'tiered_discount') && (
                                        <TierInput
                                            label="Potongan per satuan dasar (Rp)"
                                            value={t.discount_amount}
                                            error={
                                                err[
                                                    `tiers.${i}.discount_amount`
                                                ]
                                            }
                                            onChange={(v) =>
                                                setTier(i, {
                                                    discount_amount: v,
                                                })
                                            }
                                        />
                                    )}
                                    {type === 'bundle_price' && (
                                        <TierInput
                                            label="Harga bundel (Rp)"
                                            value={t.bundle_price}
                                            error={
                                                err[`tiers.${i}.bundle_price`]
                                            }
                                            onChange={(v) =>
                                                setTier(i, { bundle_price: v })
                                            }
                                        />
                                    )}
                                    {type === 'buy_x_get_y' && (
                                        <>
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor={`tier-free-${i}`}
                                                >
                                                    Produk gratis
                                                </Label>
                                                <AsyncSelect
                                                    id={`tier-free-${i}`}
                                                    source="products"
                                                    value={
                                                        t.free_product_id === ''
                                                            ? []
                                                            : [
                                                                  t.free_product_id,
                                                              ]
                                                    }
                                                    onChange={(v) =>
                                                        setTier(i, {
                                                            free_product_id:
                                                                String(
                                                                    v[0] ?? '',
                                                                ),
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        err[
                                                            `tiers.${i}.free_product_id`
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <TierInput
                                                label="Gratis (Y, satuan dasar)"
                                                value={t.free_qty_base}
                                                onChange={(v) =>
                                                    setTier(i, {
                                                        free_qty_base: v,
                                                    })
                                                }
                                            />
                                        </>
                                    )}
                                    {type === 'tiered_discount' &&
                                        data.tiers.length > 2 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                aria-label="Hapus tingkat"
                                                onClick={() =>
                                                    setData(
                                                        'tiers',
                                                        data.tiers.filter(
                                                            (_, idx) =>
                                                                idx !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </Button>
                                        )}
                                </li>
                            ))}
                        </ul>
                        <InputError message={err.tiers} />
                        {type === 'tiered_discount' && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setData('tiers', [
                                        ...data.tiers,
                                        { ...EMPTY_TIER, min_qty_base: '' },
                                    ])
                                }
                            >
                                <Plus aria-hidden="true" />
                                Tambah Tingkat
                            </Button>
                        )}
                    </SettingsSection>

                    <SettingsSection title="Dana & anggaran">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="promo-funding">
                                    Sumber dana
                                </Label>
                                <NativeSelect
                                    id="promo-funding"
                                    value={data.funded_by}
                                    onChange={(e) =>
                                        setData('funded_by', e.target.value)
                                    }
                                >
                                    {fundings.map((f) => (
                                        <option key={f.value} value={f.value}>
                                            {f.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            {data.funded_by !== 'company' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="promo-principal">
                                        Prinsipal
                                    </Label>
                                    <AsyncSelect
                                        id="promo-principal"
                                        source="principals"
                                        value={
                                            data.principal_id === ''
                                                ? []
                                                : [data.principal_id]
                                        }
                                        onChange={(v) =>
                                            setData(
                                                'principal_id',
                                                String(v[0] ?? ''),
                                            )
                                        }
                                    />
                                    <InputError message={err.principal_id} />
                                </div>
                            )}
                            {data.funded_by === 'shared' && (
                                <TierInput
                                    label="Porsi prinsipal (%)"
                                    value={data.principal_share_pct}
                                    error={err.principal_share_pct}
                                    onChange={(v) =>
                                        setData('principal_share_pct', v)
                                    }
                                />
                            )}
                            <TierInput
                                label="Anggaran (Rp, kosong = tanpa batas)"
                                value={data.budget_amount}
                                error={err.budget_amount}
                                onChange={(v) => setData('budget_amount', v)}
                            />
                        </div>
                        {promotion && promotion.budget_amount && (
                            <p className="text-xs text-muted-foreground">
                                Terpakai: Rp{trim(promotion.budget_used)} (diisi
                                saat dokumen jual diposting).
                            </p>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="promo-desc">
                                Keterangan (opsional)
                            </Label>
                            <Input
                                id="promo-desc"
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                                maxLength={500}
                            />
                        </div>
                    </SettingsSection>
                </fieldset>

                {editable && (
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/promo">Batal</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {promotion ? 'Simpan Promo' : 'Simpan sebagai Draf'}
                        </Button>
                    </div>
                )}
            </form>
        </>
    );
}

function TierInput({
    label,
    value,
    error,
    onChange,
}: {
    label: string;
    value: string;
    error?: string;
    onChange: (v: string) => void;
}) {
    const [id] = useState(
        () => `tier-${Math.random().toString(36).slice(2, 9)}`,
    );

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                inputMode="decimal"
                value={value}
                onChange={(e) => onChange(e.target.value.replace(',', '.'))}
            />
            <InputError message={error} />
        </div>
    );
}

PromotionForm.layout = {
    breadcrumbs: [{ title: 'Promo', href: '/promo' }],
};
