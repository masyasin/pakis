import { useSyncExternalStore } from 'react';

/** < 768 px: HP. Dipakai DataTable/FilterSheet (kartu vs tabel, sheet filter). */
const MOBILE_BREAKPOINT = 768;

/** < 1024 px: sidebar menjadi drawer (07 §1.2), agar konten tablet tidak terjepit. */
const SIDEBAR_DRAWER_BREAKPOINT = 1024;

function createBelowQuery(breakpoint: number) {
    const mql =
        typeof window === 'undefined'
            ? undefined
            : window.matchMedia(`(max-width: ${breakpoint - 1}px)`);

    const subscribe = (callback: (event: MediaQueryListEvent) => void) => {
        if (!mql) {
            return () => {};
        }

        mql.addEventListener('change', callback);

        return () => {
            mql.removeEventListener('change', callback);
        };
    };

    const getSnapshot = (): boolean => mql?.matches ?? false;

    return { subscribe, getSnapshot };
}

const mobileQuery = createBelowQuery(MOBILE_BREAKPOINT);
const sidebarDrawerQuery = createBelowQuery(SIDEBAR_DRAWER_BREAKPOINT);

function getServerSnapshot(): boolean {
    return false;
}

export function useIsMobile(): boolean {
    return useSyncExternalStore(
        mobileQuery.subscribe,
        mobileQuery.getSnapshot,
        getServerSnapshot,
    );
}

export function useIsSidebarDrawer(): boolean {
    return useSyncExternalStore(
        sidebarDrawerQuery.subscribe,
        sidebarDrawerQuery.getSnapshot,
        getServerSnapshot,
    );
}
