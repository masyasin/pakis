import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

// Fitur bawaan starter kit, perilakunya tidak diubah. Diganti offboarding di T-017.
export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <SettingsSection
            tone="danger"
            title="Hapus akun"
            description="Akun dan semua datanya dihapus permanen. Tindakan ini tidak bisa dibatalkan."
        >
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="destructive"
                        data-test="delete-user-button"
                    >
                        Hapus akun
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Hapus akun ini secara permanen?</DialogTitle>
                    <DialogDescription>
                        Semua data akun ikut terhapus dan tidak bisa dipulihkan.
                        Masukkan password Anda untuk melanjutkan.
                    </DialogDescription>

                    <Form
                        {...ProfileController.destroy.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        onError={() => passwordInput.current?.focus()}
                        resetOnSuccess
                        className="space-y-6"
                    >
                        {({ resetAndClearErrors, processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>

                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        ref={passwordInput}
                                        autoComplete="current-password"
                                        aria-invalid={
                                            errors.password ? true : undefined
                                        }
                                    />

                                    <InputError message={errors.password} />
                                </div>

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                resetAndClearErrors()
                                            }
                                        >
                                            Batal
                                        </Button>
                                    </DialogClose>

                                    <Button
                                        variant="destructive"
                                        disabled={processing}
                                        asChild
                                    >
                                        <button
                                            type="submit"
                                            data-test="confirm-delete-user-button"
                                        >
                                            Hapus akun
                                        </button>
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </SettingsSection>
    );
}
