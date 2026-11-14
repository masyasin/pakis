import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// RTL tidak membersihkan DOM otomatis bila `globals` Vitest mati.
afterEach(() => {
    cleanup();

    try {
        window.localStorage.clear();
    } catch {
        // localStorage bisa tidak tersedia; test tidak bergantung padanya.
    }
});
