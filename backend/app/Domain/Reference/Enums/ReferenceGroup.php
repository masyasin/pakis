<?php

namespace App\Domain\Reference\Enums;

/**
 * Grup referensi (08 §3). Daftar ini dikunci di kode: UI tidak bisa membuat grup baru, karena
 * setiap grup terhubung ke kolom FK tertentu.
 */
enum ReferenceGroup: string
{
    case LocationType = 'location_type';
    case EmploymentStatus = 'employment_status';
    case CorrectionReason = 'correction_reason';
    case OffboardingReason = 'offboarding_reason';
    // Paket A (15 §4.1, T-069): satuan (meta `integer_only`) & formulasi pestisida.
    case Unit = 'unit';
    case Formulation = 'formulation';
    // Paket A (15 §4.1, T-073): jenis pelanggan (kios, toko eceran, kelompok tani, ...).
    case CustomerType = 'customer_type';
    // Paket A (15 §4.9, T-080): alasan barang ditolak saat penerimaan (klaim ke supplier, BR-158(7)).
    case ReceiptRejectReason = 'receipt_reject_reason';
    // Paket A (15 §2, T-083): alasan selisih stock opname (BR-162(5)).
    case OpnameVarianceReason = 'opname_variance_reason';

    public function label(): string
    {
        return match ($this) {
            self::LocationType => 'Tipe lokasi',
            self::EmploymentStatus => 'Status kerja',
            self::CorrectionReason => 'Alasan koreksi presensi',
            self::OffboardingReason => 'Alasan keluar',
            self::Unit => 'Satuan',
            self::Formulation => 'Formulasi',
            self::CustomerType => 'Jenis pelanggan',
            self::ReceiptRejectReason => 'Alasan tolak penerimaan',
            self::OpnameVarianceReason => 'Alasan selisih opname',
        };
    }

    /**
     * Kolom yang memakai item grup ini (08 §3), untuk menolak hapus item yang sudah dipakai.
     *
     * @return list<array{0: string, 1: string}> [tabel, kolom]
     */
    public function usages(): array
    {
        return match ($this) {
            self::LocationType => [['locations', 'location_type_id']],
            self::EmploymentStatus => [['employees', 'employment_status_id']],
            self::CorrectionReason => [['attendance_corrections', 'reason_id']],
            self::OffboardingReason => [['employees', 'exit_reason_id']],
            // T-070: satuan dipakai satuan produk & satuan dasar; formulasi dipakai produk.
            self::Unit => [['product_units', 'unit_id'], ['products', 'base_unit_id']],
            self::Formulation => [['products', 'formulation_id']],
            self::CustomerType => [['customers', 'customer_type_id']],
            self::ReceiptRejectReason => [['goods_receipt_lines', 'reject_reason_id']],
            self::OpnameVarianceReason => [['stock_opname_lines', 'variance_reason_id']],
        };
    }
}
