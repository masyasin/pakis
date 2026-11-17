import { expect, test } from '@playwright/test';

/*
 * E2E Paket A (T-087): alur web laporan stok & antrean di atas data demo (DemoSeeder:
 * InventoryDemoSeeder + CustomerVerificationDemoSeeder). Sesi Owner dari auth.setup.ts
 * (scope semua lokasi + HPP).
 */

test.beforeEach(async ({ page }) => {
    await page.goto('/dasbor');
});

test('menu Persediaan memuat laporan stok T-087', async ({ page }) => {
    // Grup sidebar bisa dilipat: buka Persediaan dulu bila tertutup.
    const group = page.getByRole('button', { name: 'Persediaan', exact: true });
    if ((await group.getAttribute('aria-expanded')) !== 'true') {
        await group.click();
    }
    for (const item of [
        'Posisi Stok',
        'Kartu Stok',
        'Stok Minimum',
        'Telusur Batch',
        'Laporan Audit Stok',
    ]) {
        await expect(
            page.getByRole('link', { name: item, exact: true }),
        ).toBeVisible();
    }
});

test('Posisi Stok: per produk dengan nilai HPP, per batch lewat filter, tombol ekspor', async ({
    page,
}) => {
    await page.goto('/stok');
    await expect(
        page.getByRole('heading', { name: 'Posisi Stok', level: 1 }),
    ).toBeVisible();
    await expect(
        page.getByText('Insektisida Demo Abamektin 500 ml').first(),
    ).toBeVisible();
    await expect(page.getByText('Nilai (HPP)').first()).toBeVisible();
    await expect(page.getByRole('link', { name: /Ekspor/ })).toBeVisible();

    await page.goto('/stok?filter[view]=per_batch&q=DMINS001-01');
    await expect(page.getByText('DMINS001-01').first()).toBeVisible();
});

test('Kartu Stok: mutasi saldo awal & mutasi antar lokasi tampil dengan tautan dokumen', async ({
    page,
}) => {
    await page.goto('/stok/kartu');
    await expect(
        page.getByRole('heading', { name: 'Kartu Stok', level: 1 }),
    ).toBeVisible();
    await expect(page.getByText('Saldo awal').first()).toBeVisible();
    await expect(
        page.getByRole('link', { name: /^MUT-/ }).first(),
    ).toBeVisible();
});

test('Telusur Batch: contoh batch bisa diklik dan menampilkan lokasi', async ({
    page,
}) => {
    await page.goto('/stok/telusur');
    await expect(page.getByText('Contoh:')).toBeVisible();
    await page
        .getByRole('link', { name: /DM[A-Z0-9]+-0\d/ })
        .first()
        .click();
    await expect(page).toHaveURL(/filter/);
    await expect(
        page.getByText('Semua lokasi yang menyimpan batch ini'),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Kartu stok' }).first(),
    ).toBeVisible();
});

test('Stok Minimum: ubah batas lewat dialog tersimpan', async ({ page }) => {
    await page.goto('/stok/minimum');
    await expect(
        page.getByRole('heading', { name: 'Stok Minimum', level: 1 }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Ubah' }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Minimum').fill('77');
    await dialog.getByLabel('Maksimum (opsional)').fill('10');
    await dialog.getByRole('button', { name: 'Simpan' }).click();
    // Maks < min ditolak server dan dialog tetap terbuka dengan pesannya.
    await expect(
        dialog.getByText('Stok maksimum tidak boleh lebih kecil dari minimum.'),
    ).toBeVisible();

    await dialog.getByLabel('Maksimum (opsional)').fill('');
    await dialog.getByRole('button', { name: 'Simpan' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('77').first()).toBeVisible();
});

test('Laporan Audit Stok: pelanggaran FEFO & selisih per penghitung', async ({
    page,
}) => {
    await page.goto('/stok/audit/fefo');
    await expect(
        page.getByRole('heading', { name: 'Laporan Audit Stok', level: 1 }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: /^MUT-/ }).first(),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Selisih per penghitung' }).click();
    await expect(page).toHaveURL(/stok\/audit\/penghitung/);
    await expect(
        page.getByRole('link', { name: /^OPN-/ }).first(),
    ).toBeVisible();
});

test('Nomor Seri & Antrean Verifikasi Kios berisi data demo', async ({
    page,
}) => {
    await page.goto('/nomor-seri');
    await expect(page.getByText('DMSPR25-2601').first()).toBeVisible();

    await page.goto('/pelanggan/verifikasi');
    await expect(page.getByText('Kios Barokah Demo').first()).toBeVisible();
});
