import { test as setup } from '@playwright/test';
import { loginAsOwner } from './support/auth';

/*
 * Login sekali lalu simpan sesi: Fortify menolak kode TOTP yang sama dipakai ulang (anti-replay),
 * jadi setiap test memakai sesi ini alih-alih login ulang dalam jendela 30 detik yang sama.
 */
export const OWNER_STATE = 'tests/e2e/.auth/owner.json';

setup('login Owner (password + TOTP)', async ({ page }) => {
    await loginAsOwner(page);
    await page.context().storageState({ path: OWNER_STATE });
});
