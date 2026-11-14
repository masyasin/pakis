/** Bentuk `auth.user` yang dibagikan HandleInertiaRequests::sharedUser (T-017), bukan model utuh. */
export type User = {
    id: number;
    name: string;
    username: string;
    email: string | null;
    email_verified_at: string | null;
    phone: string | null;
    /** URL relatif `/profil/foto?v=…` atau null. */
    avatar: string | null;
    two_factor_enabled: boolean;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
