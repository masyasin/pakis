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

/** Item menu web (MenuBuilder, 06 §8): sudah difilter server. */
export type MenuItem = {
    title: string;
    href: string;
    /** Kunci ikon, dipetakan di `nav-main.tsx`. */
    icon: string;
};

export type MenuGroup = {
    /** Judul seksi sidebar (`HRIS`, `Sistem`); null = tanpa seksi (Dasbor). */
    section: string | null;
    /** null = tanpa judul grup (Dasbor). */
    title: string | null;
    items: MenuItem[];
};

export type Auth = {
    user: User;
    /** Nama permission milik pengguna (06 §10); hanya untuk UX, server tetap memeriksa. */
    permissions: string[];
    menu: MenuGroup[];
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
