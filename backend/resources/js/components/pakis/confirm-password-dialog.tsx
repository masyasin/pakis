import { useForm } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { DialogClose } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type RouteDefinition = {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/**
 * Dialog konfirmasi aksi akun dengan password saat ini (05 §4.1: cabut sesi, tautkan Telegram).
 * Server tetap memeriksa password; dialog ini hanya cara memintanya.
 */
export function ConfirmPasswordDialog({
    trigger,
    title,
    description,
    confirmLabel,
    action,
    destructive = false,
    onSuccess,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    confirmLabel: string;
    action: RouteDefinition;
    destructive?: boolean;
    onSuccess?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const form = useForm({ current_password: '' });

    const close = (next: boolean) => {
        setOpen(next);
        if (!next) {
            form.reset();
            form.clearErrors();
        }
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={close}
            trigger={trigger}
            icon={LockKeyhole}
            tone={destructive ? 'danger' : 'default'}
            title={title}
            description={description}
            onSubmit={(e) => {
                e.preventDefault();
                form.submit(action.method, action.url, {
                    preserveScroll: true,
                    onSuccess: () => {
                        close(false);
                        onSuccess?.();
                    },
                    onError: () => input.current?.focus(),
                });
            }}
            footer={
                <>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={
                            form.processing || form.data.current_password === ''
                        }
                    >
                        {form.processing && <Spinner />}
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <div className="grid gap-2">
                <Label htmlFor="confirm_current_password">
                    Password saat ini
                </Label>
                <PasswordInput
                    id="confirm_current_password"
                    ref={input}
                    autoComplete="current-password"
                    autoFocus
                    value={form.data.current_password}
                    onChange={(e) =>
                        form.setData('current_password', e.target.value)
                    }
                    aria-invalid={
                        form.errors.current_password ? true : undefined
                    }
                />
                <InputError message={form.errors.current_password} />
            </div>
        </FormDialog>
    );
}
