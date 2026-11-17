<?php

namespace Database\Seeders;

use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Customer\Actions\RecordOfficeCall;
use App\Domain\Customer\Actions\SubmitVerification;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Antrean Verifikasi Kios demo (T-074 / T-087): sales mengajukan verifikasi aktivasi kios prospek
 * lewat telepon kantor, lalu pengguna LAIN mencatat hasil telepon (pemisahan tugas BR-153), sehingga
 * pengajuan masuk antrean persetujuan `customers.verify`. Satu kios lagi baru diajukan (menunggu
 * telepon kantor). Lewat Action asli; foto = gambar polos buatan; titik = koordinat FIKTIF dibulatkan
 * 0,01° (bukan koordinat presisi, CLAUDE.md §6). Ditolak di produksi.
 *
 *     php artisan db:seed --class=CustomerVerificationDemoSeeder
 */
class CustomerVerificationDemoSeeder extends Seeder
{
    /** nama toko => [pengaju (sales), pencatat telepon kantor | null = belum ditelepon, limit diminta] */
    private const QUEUE = [
        'Kios Barokah Demo' => ['Eko Wibowo', 'Agus Prasetyo', '7500000'],
        'Kios Lestari Demo' => ['Bayu Saputra', 'Agus Prasetyo', '5000000'],
        'Kios Mekar Jaya Demo' => ['Eko Wibowo', null, '3000000'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('CustomerVerificationDemoSeeder ditolak di produksi: data demo hanya untuk local/staging (04 §4.2).');
        }

        // Tanpa aturan persetujuan aktivasi (ApprovalRuleSeeder belum jalan) pengajuan tidak bisa masuk
        // antrean; pola sama dengan PO demo: dilewati, bukan dipaksa.
        if (! ApprovalRule::query()->where('document_type', CustomerActivation::DOCUMENT_TYPE)->where('is_active', true)->exists()) {
            return;
        }

        foreach (self::QUEUE as $store => [$submitter, $caller, $limit]) {
            $customer = Customer::query()->where('store_name', $store)->first();
            $sales = User::query()->where('name', $submitter)->first();
            // Idempoten: hanya kios prospek yang belum pernah diajukan.
            if ($customer === null || $sales === null || $customer->status !== CustomerStatus::Prospect
                || CustomerVerification::query()->where('customer_id', $customer->id)->exists()) {
                continue;
            }

            app(SubmitVerification::class)->handle($customer, [
                'method' => 'office_call', 'latitude' => '-6.80', 'longitude' => '110.90', 'requested_credit_limit' => $limit,
            ], $this->photo(), $sales);

            $officer = $caller === null ? null : User::query()->where('name', $caller)->first();
            if ($officer !== null) {
                app(RecordOfficeCall::class)->handle($customer->fresh(), 'Data demo: pemilik mengonfirmasi lewat telepon kantor.', $officer);
            }
        }
    }

    private function photo(): UploadedFile
    {
        $image = imagecreatetruecolor(640, 480);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 46, 125, 50));
        ob_start();
        imagejpeg($image);

        return UploadedFile::fake()->createWithContent('toko-demo.jpg', (string) ob_get_clean());
    }
}
