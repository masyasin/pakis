import type { Auth } from '@/types/auth';

/** Branding dari Pengaturan (T-008, HandleInertiaRequests). URL logo relatif. */
export type Branding = {
    company: string;
    logoLight: string | null;
    logoDark: string | null;
    loginTagline: string | null;
};

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            branding: Branding;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
