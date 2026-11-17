<?php

namespace App\Domain\Approval\Contracts;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokumen yang memakai mesin approval generik (ADR-105, BR-153). Diimplementasikan model Eloquent
 * dokumen (PO, opname, kios, ...) dan didaftarkan di `config/pakis.php` `approvables` agar mesin bisa
 * memuat ulang dokumen saat keputusan diambil.
 *
 * Callback `on*` dipanggil mesin DI DALAM transaksi keputusan, setelah baris dokumen dikunci, jadi
 * perubahan status dokumen dan status permintaan selalu ikut commit/rollback bersama.
 *
 * @mixin Model
 */
interface Approvable
{
    /** Kunci jenis dokumen, mis. `purchase_order` (15 §4.2.1). */
    public function approvalDocumentType(): string;

    /** Nominal yang dibandingkan dengan rentang aturan (string desimal, 2 digit), atau null. */
    public function approvalAmount(): ?string;

    /** Ringkasan satu baris untuk kotak masuk, mis. `PO-2611-0042 · PT Agro · Rp. 48.500.000`. */
    public function approvalSummary(): string;

    /**
     * Data pendukung untuk penyetuju (ditampilkan apa adanya: jangan isi data sensitif).
     *
     * @return array<string, mixed>
     */
    public function approvalContext(): array;

    /**
     * Nilai atribut dokumen untuk `approval_rules.conditions` (kunci yang diizinkan per jenis ada
     * di `config/pakis.php` `approvables.<jenis>.conditions`).
     *
     * @return array<string, int|string|null>
     */
    public function approvalConditions(): array;

    /** `locations.id` Fase 0 untuk `location_head` & scope (PA-29). */
    public function approvalScopeLocationId(): ?int;

    /** Pegawai terkait (untuk `direct_supervisor` & scope). */
    public function approvalScopeEmployeeId(): ?int;

    /** Pengguna pembuat dokumen (maker-checker BR-153(4)); null bila tidak diketahui. */
    public function creatorUserId(): ?int;

    /** Tautan relatif halaman dokumen, mis. `/pembelian/po/12`, atau null. */
    public function approvalUrl(): ?string;

    /** Rantai selesai disetujui. `$request` null = tanpa aturan & `approval.auto_approve_when_no_rule`. */
    public function onApproved(?ApprovalRequest $request): void;

    /** Ditolak di langkah mana pun (BR-153(8)). */
    public function onRejected(ApprovalRequest $request): void;

    /** Dibatalkan pengaju sebelum ada keputusan (BR-153(9)). */
    public function onCancelled(ApprovalRequest $request): void;
}
