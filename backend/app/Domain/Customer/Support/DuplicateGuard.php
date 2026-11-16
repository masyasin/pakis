<?php

namespace App\Domain\Customer\Support;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Duplicates\DuplicateCandidate;
use App\Domain\Customer\Duplicates\DuplicateFinder;
use App\Domain\Customer\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * Penjaga simpan kios (BR-166(2)(3), T-074), dipanggil Create/UpdateCustomer DI DALAM transaksinya:
 * 1. HP: blokir bila milik pegawai/akun aktif atau kios lain yang masih dipakai (PhoneConflicts);
 * 2. kandidat duplikat (nama mirip dekat/desa sama, atau HP sama dengan kios nonaktif) → hanya
 *    peringatan, tetapi simpan wajib centang "bukan duplikat" + alasan; override dicatat di audit
 *    `customer.duplicate_override`.
 *
 * Layar memanggil `admin.customers.duplicates` lebih dulu untuk menampilkan kandidatnya; pemeriksaan
 * di sini memastikan aturan tetap berlaku walau layar dilewati.
 */
final class DuplicateGuard
{
    /** Kunci data form untuk konfirmasi "bukan duplikat". */
    public const CONFIRM_KEYS = ['not_duplicate', 'duplicate_reason'];

    public function __construct(
        private readonly DuplicateFinder $finder,
        private readonly PhoneConflicts $phones,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  isian kios (PROFILE) + CONFIRM_KEYS
     * @return list<string>|null kode kios kandidat yang dikonfirmasi "bukan duplikat"; null bila tidak ada kandidat
     */
    public function check(array $data, ?int $exceptCustomerId, bool $phoneChanged = true): ?array
    {
        $phone = (string) $data['owner_phone'];
        if ($phoneChanged) {
            $this->phones->assertUsable($phone, $exceptCustomerId);
        }

        $candidates = $this->finder->find(
            (string) $data['store_name'],
            $phone,
            self::str($data['latitude'] ?? null),
            self::str($data['longitude'] ?? null),
            self::str($data['village_code'] ?? null),
            $exceptCustomerId,
        );
        if ($candidates === []) {
            return null;
        }

        $confirmed = filter_var($data['not_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $reason = trim((string) ($data['duplicate_reason'] ?? ''));
        if (! $confirmed || mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'not_duplicate' => sprintf('Ada %d kios yang mirip (nama/lokasi/HP). Periksa daftarnya; bila memang bukan kios yang sama, centang "Bukan duplikat" dan isi alasannya (minimal 5 karakter).', count($candidates)),
            ]);
        }

        return array_map(fn (DuplicateCandidate $c) => $c->customer->code, $candidates);
    }

    /**
     * @param  list<string>|null  $override
     * @param  array<string, mixed>  $data
     */
    public function recordOverride(Customer $customer, ?array $override, array $data): void
    {
        if ($override === null) {
            return;
        }

        $this->audit->record('customer.duplicate_override', $customer, null, [
            'candidates' => $override,
            'reason' => mb_substr(trim((string) ($data['duplicate_reason'] ?? '')), 0, 255),
        ]);
    }

    private static function str(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
