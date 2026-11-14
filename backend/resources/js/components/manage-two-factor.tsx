import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { SettingsSection } from '@/components/settings-section';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
    /** Role pengguna mewajibkan MFA (BR-015): tidak bisa dinonaktifkan. */
    mfaRequired?: boolean;
    /** Server mengizinkan kode pemulihan dilihat sekali (tepat setelah dibuat). */
    recoveryCodesViewable?: boolean;
    recoveryCodesRemaining?: number;
};

export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <SettingsSection
            title="Autentikasi dua faktor"
            description="Lapisan pengaman tambahan setiap kali masuk ke PAKIS ONE."
        >
            {twoFactorEnabled ? (
                <div className="flex flex-col items-start justify-start space-y-4">
                    <p className="inline-flex items-center gap-2 rounded-full bg-status-success-bg px-3 py-1 text-sm font-medium text-status-success">
                        <ShieldCheck className="size-4" aria-hidden="true" />
                        Aktif
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Setiap kali masuk, Anda akan diminta kode 6 digit dari
                        aplikasi autentikator (mis. Google Authenticator).
                    </p>

                    {props.mfaRequired ? (
                        <p className="text-sm text-muted-foreground">
                            Wajib untuk role Anda, jadi tidak bisa
                            dinonaktifkan. Bila HP hilang, minta admin mereset
                            MFA akun Anda.
                        </p>
                    ) : (
                        <div className="relative inline">
                            <Form {...disable.form()}>
                                {({ processing }) => (
                                    <Button
                                        variant="destructive"
                                        type="submit"
                                        disabled={processing}
                                    >
                                        Nonaktifkan 2FA
                                    </Button>
                                )}
                            </Form>
                        </div>
                    )}

                    <TwoFactorRecoveryCodes
                        recoveryCodesList={recoveryCodesList}
                        fetchRecoveryCodes={fetchRecoveryCodes}
                        errors={errors}
                        viewable={props.recoveryCodesViewable ?? false}
                        remaining={props.recoveryCodesRemaining}
                    />
                </div>
            ) : (
                <div className="flex flex-col items-start justify-start space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Setelah diaktifkan, Anda akan diminta kode 6 digit dari
                        aplikasi autentikator (mis. Google Authenticator) setiap
                        kali masuk.
                    </p>

                    <div>
                        {hasSetupData ? (
                            <Button onClick={() => setShowSetupModal(true)}>
                                <ShieldCheck />
                                Lanjutkan pengaturan
                            </Button>
                        ) : (
                            <Form
                                {...enable.form()}
                                onSuccess={() => setShowSetupModal(true)}
                            >
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        <ShieldCheck />
                                        Aktifkan 2FA
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </SettingsSection>
    );
}
