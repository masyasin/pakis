import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, UserRound, UsersRound } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CustomerPhone } from '@/components/customer/customer-ui';
import type {
    ChangeState,
    ContactRow,
} from '@/components/customer/customer-ui';
import { DetailCard, EmptyPanel } from '@/components/employee/detail-ui';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, store, update } from '@/routes/admin/customers/contacts';

type Props = {
    customerId: number;
    state: ChangeState['contacts'];
    /** HP kontak sudah termasking dari server (SEC-108). */
    phoneMasked: boolean;
};

/**
 * Tab Kontak detail kios (15 §5 `admin.customers.contacts.*`, T-074): orang lain di kios selain
 * pemilik (anak, penjaga toko, bagian bayar). Tambah/ubah/hapus hanya untuk pengelola kios; HP
 * termasking dari server untuk yang lain.
 */
export function ContactsTab({ customerId, state, phoneMasked }: Props) {
    const [editing, setEditing] = useState<ContactRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<ContactRow | null>(null);

    const addButton = state.can_manage ? (
        <Button size="sm" onClick={() => setEditing('new')}>
            <Plus aria-hidden="true" />
            Tambah Kontak
        </Button>
    ) : undefined;

    return (
        <>
            {state.rows.length === 0 ? (
                <EmptyPanel
                    icon={UsersRound}
                    title="Belum ada kontak"
                    text="Catat orang lain yang bisa dihubungi di kios ini, mis. penjaga toko atau bagian pembayaran."
                    action={addButton}
                />
            ) : (
                <DetailCard
                    title="Kontak kios"
                    icon={UsersRound}
                    action={addButton}
                >
                    <ul className="divide-y">
                        {state.rows.map((c) => (
                            <li
                                key={c.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 first:pt-0 last:pb-0"
                            >
                                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
                                    <UserRound
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div className="min-w-0 flex-1 basis-40">
                                    <p className="font-medium break-words">
                                        {c.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {c.role ?? 'Peran belum diisi'}
                                    </p>
                                </div>
                                <CustomerPhone
                                    phone={c.phone}
                                    masked={phoneMasked}
                                    className="text-sm"
                                />
                                {state.can_manage && (
                                    <div className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Ubah kontak ${c.name}`}
                                            onClick={() => setEditing(c)}
                                        >
                                            <Pencil aria-hidden="true" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="text-destructive hover:text-destructive"
                                            aria-label={`Hapus kontak ${c.name}`}
                                            onClick={() => setDeleting(c)}
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </DetailCard>
            )}

            {state.can_manage && editing !== null && (
                <ContactDialog
                    customerId={customerId}
                    contact={editing === 'new' ? null : editing}
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}
            {state.can_manage && deleting !== null && (
                <DeleteContactDialog
                    customerId={customerId}
                    contact={deleting}
                    phoneMasked={phoneMasked}
                    onOpenChange={(open) => !open && setDeleting(null)}
                />
            )}
        </>
    );
}

function ContactDialog({
    customerId,
    contact,
    onOpenChange,
}: {
    customerId: number;
    contact: ContactRow | null;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: contact?.name ?? '',
        role: contact?.role ?? '',
        phone: contact?.phone ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.data.name.trim().length < 2) {
            form.setError('name', 'Isi nama kontak, minimal 2 huruf.');

            return;
        }
        form.submit(
            contact
                ? update({ customer: customerId, contact: contact.id })
                : store(customerId),
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <FormDialog
            open
            onOpenChange={onOpenChange}
            title={contact ? `Ubah kontak ${contact.name}` : 'Tambah kontak'}
            icon={UsersRound}
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
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Simpan Kontak
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.form} />
            <div className="grid gap-2">
                <Label htmlFor="contact-name">Nama</Label>
                <Input
                    id="contact-name"
                    maxLength={100}
                    placeholder="Siti Aminah"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    aria-invalid={errors.name ? true : undefined}
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="contact-role">Peran (opsional)</Label>
                <Input
                    id="contact-role"
                    maxLength={60}
                    placeholder="mis. penjaga toko, bagian pembayaran"
                    value={form.data.role}
                    onChange={(e) => form.setData('role', e.target.value)}
                    aria-invalid={errors.role ? true : undefined}
                />
                <InputError message={errors.role} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="contact-phone">HP (opsional)</Label>
                <Input
                    id="contact-phone"
                    type="tel"
                    inputMode="tel"
                    maxLength={20}
                    className="tabular-nums"
                    placeholder="0812 3456 7890"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                    aria-invalid={errors.phone ? true : undefined}
                />
                <InputError message={errors.phone} />
            </div>
        </FormDialog>
    );
}

function DeleteContactDialog({
    customerId,
    contact,
    phoneMasked,
    onOpenChange,
}: {
    customerId: number;
    contact: ContactRow;
    phoneMasked: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <FormDialog
            open
            onOpenChange={onOpenChange}
            title={`Hapus kontak ${contact.name}?`}
            description="Kontak dihapus dari kios ini. Perubahan tercatat di log audit."
            icon={Trash2}
            tone="danger"
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
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={() =>
                            router.delete(
                                destroy({
                                    customer: customerId,
                                    contact: contact.id,
                                }).url,
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        onOpenChange(false);
                                    },
                                },
                            )
                        }
                    >
                        {processing && <Spinner />}
                        Hapus Kontak
                    </Button>
                </>
            }
        >
            <p className="text-sm">
                {contact.role ? `${contact.role} · ` : ''}
                <CustomerPhone phone={contact.phone} masked={phoneMasked} />
            </p>
        </FormDialog>
    );
}
