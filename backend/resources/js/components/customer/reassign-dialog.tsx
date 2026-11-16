import { useForm } from '@inertiajs/react';
import { ArrowRightLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber } from '@/lib/listing-query';
import { reassign } from '@/routes/admin/customers';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    customerIds: number[];
    /** Judul sasaran, mis. nama satu kios; kosong = "N kios terpilih". */
    targetLabel?: string;
    /** Tanggal lokal hari ini (`general.timezone`) dari server. */
    today: string;
    maxReassign: number;
    onSaved?: () => void;
};

const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');

/**
 * Pengalihan area & sales kios (BR-167, `admin.customers.reassign`). Dipakai dari daftar (pilihan
 * massal ≤ `maxReassign`) dan dari detail satu kios. Tanggal berlaku hari ini atau masa depan;
 * yang masa depan diterapkan job harian, jadi kios tetap di area lama sampai hari H.
 *
 * Pemanggil me-mount dialog hanya saat dibuka, jadi setiap pembukaan mulai dari isian kosong.
 */
export function ReassignDialog({
    open,
    onOpenChange,
    customerIds,
    targetLabel,
    today,
    maxReassign,
    onSaved,
}: Props) {
    const form = useForm({
        sales_area_id: '',
        sales_employee_id: '',
        effective_date: today,
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const tooMany = customerIds.length > maxReassign;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, customer_ids: customerIds }));
        form.submit(reassign(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onOpenChange(false);
                onSaved?.();
            },
        });
    };

    const customersError =
        errors.customer_ids ??
        Object.entries(errors).find(([k]) =>
            k.startsWith('customer_ids.'),
        )?.[1];

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            icon={ArrowRightLeft}
            title="Alihkan kios"
            description={
                targetLabel ??
                `${formatNumber(customerIds.length)} kios terpilih. Penugasan lama ditutup sehari sebelum tanggal berlaku.`
            }
            onSubmit={submit}
            noValidate
            footer={
                <>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            tooMany ||
                            customerIds.length === 0 ||
                            form.data.sales_area_id === ''
                        }
                    >
                        {form.processing && <Spinner />}
                        Alihkan
                    </Button>
                </>
            }
        >
            <FormStatus
                tone="error"
                message={
                    tooMany
                        ? `Maksimal ${formatNumber(maxReassign)} kios sekali alihkan. Kurangi pilihan.`
                        : (customersError ?? errors.form)
                }
            />
            <div className="grid gap-2">
                <Label htmlFor="reassign-area">Area penjualan tujuan</Label>
                <AsyncSelect
                    id="reassign-area"
                    source="sales_areas"
                    params={{ assignable: '1' }}
                    value={one(form.data.sales_area_id)}
                    placeholder="Cari kode atau nama area"
                    onChange={(v) => form.setData('sales_area_id', first(v))}
                />
                <InputError message={errors.sales_area_id} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="reassign-sales">Sales (opsional)</Label>
                <AsyncSelect
                    id="reassign-sales"
                    source="sales_employees"
                    value={one(form.data.sales_employee_id)}
                    placeholder="Cari nama atau nomor pegawai"
                    onChange={(v) =>
                        form.setData('sales_employee_id', first(v))
                    }
                />
                <p className="text-xs text-muted-foreground">
                    Kosongkan bila kios belum punya sales penanggung jawab.
                </p>
                <InputError message={errors.sales_employee_id} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="reassign-date">Berlaku mulai</Label>
                <Input
                    id="reassign-date"
                    type="date"
                    min={today}
                    value={form.data.effective_date}
                    onChange={(e) =>
                        form.setData('effective_date', e.target.value)
                    }
                    aria-invalid={errors.effective_date ? true : undefined}
                />
                <InputError message={errors.effective_date} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="reassign-reason">Alasan (opsional)</Label>
                <Input
                    id="reassign-reason"
                    value={form.data.reason}
                    maxLength={255}
                    placeholder="mis. pembagian ulang wilayah sales"
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
                <InputError message={errors.reason} />
            </div>
        </FormDialog>
    );
}
