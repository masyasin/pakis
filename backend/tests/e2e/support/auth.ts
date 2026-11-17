import { createHmac } from 'node:crypto';
import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Login E2E (T-087). Akun dari `E2eUserSeeder`; password & rahasia TOTP hanya dari env
 * (`E2E_PASSWORD`, `E2E_TOTP_SECRET`), tidak pernah ditulis di kode (CLAUDE.md §6).
 */
export const E2E_USERNAME = 'e2e.owner';

function env(name: string): string {
    const value = process.env[name];
    if (!value) {
        throw new Error(
            `Env ${name} wajib diisi untuk test E2E (lihat E2eUserSeeder).`,
        );
    }

    return value;
}

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of input.replace(/=+$/, '').toUpperCase()) {
        const index = alphabet.indexOf(char);
        if (index < 0) {
            throw new Error('E2E_TOTP_SECRET bukan base32.');
        }
        bits += index.toString(2).padStart(5, '0');
    }
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}

/** Kode TOTP RFC 6238 (SHA-1, 6 digit, 30 detik) — sama dengan google2fa di Fortify. */
export function totp(secret: string, at: number = Date.now()): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const hmac = createHmac('sha1', base32Decode(secret))
        .update(counter)
        .digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return code.toString().padStart(6, '0');
}

export async function loginAsOwner(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login').fill(E2E_USERNAME);
    await page.locator('#password').fill(env('E2E_PASSWORD'));
    await page.getByRole('button', { name: /masuk/i }).click();

    await expect(page).toHaveURL(/two-factor-challenge|dasbor/);
    if (page.url().includes('two-factor-challenge')) {
        // input-otp = satu input tersembunyi; 6 digit lengkap langsung mengirim formulir.
        await page
            .locator('input[name="code"]')
            .pressSequentially(totp(env('E2E_TOTP_SECRET')));
    }
    await expect(page).toHaveURL(/dasbor/);
}
