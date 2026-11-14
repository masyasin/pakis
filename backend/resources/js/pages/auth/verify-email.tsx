import { Form, Head } from '@inertiajs/react';
import { FormStatus } from '@/components/form-status';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Verifikasi email" />

            <div className="space-y-6">
                {status === 'verification-link-sent' && (
                    <FormStatus
                        tone="success"
                        message="Tautan verifikasi baru sudah dikirim ke email Anda."
                    />
                )}

                <Form {...send.form()} className="grid gap-4">
                    {({ processing }) => (
                        <>
                            <Button
                                size="lg"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Kirim ulang email verifikasi
                            </Button>

                            <TextLink
                                href={logout()}
                                as="button"
                                className="mx-auto text-sm"
                            >
                                Keluar
                            </TextLink>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verifikasi email',
    description:
        'Kami sudah mengirim tautan verifikasi ke email Anda. Buka tautan itu untuk melanjutkan.',
};
