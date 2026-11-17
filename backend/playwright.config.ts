import { existsSync, readFileSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

// Kunci E2E_* dibaca dari .env lokal (gitignored) agar rahasia akun uji tidak pernah ada di kode.
if (existsSync('.env')) {
    for (const line of readFileSync('.env', 'utf8').split(/\r?\n/)) {
        const match = /^(E2E_[A-Z_]+)=(.*)$/.exec(line);
        if (match && process.env[match[1]] === undefined) {
            process.env[match[1]] = match[2].replace(/^"|"$/g, '');
        }
    }
}

/**
 * E2E web (T-087, `npx playwright test`). Berjalan terhadap app lokal + data demo
 * (`php artisan migrate:fresh --seed` lalu `db:seed --class=E2eUserSeeder` dengan env E2E_*).
 * Aset dari `public/build` (`npm run build`), bukan Vite dev server. Belum dijalankan di CI.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
        locale: 'id-ID',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            dependencies: ['setup'],
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'tests/e2e/.auth/owner.json',
            },
        },
    ],
    webServer: {
        command: 'php artisan serve --port=8000',
        url: 'http://localhost:8000/login',
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
