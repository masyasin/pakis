# PAKIS ONE — Paket Dokumen Fase 0 + 0,5

**Klien:** CV Pakis Jaya Abadi (distributor pestisida & alat pertanian, Kalirejo, Undaan, Kudus)
**Pengembang:** PT Sintech Berkah Abadi
**Versi paket:** 1.0 · 3 November 2026
**Status:** Siap dieksekusi AI coding agent (Claude Code), dengan pertanyaan terbuka di `12-OPEN-QUESTIONS.md`

## 1. Ringkasan

Paket ini mencakup **fondasi** PAKIS ONE. Isinya inti organisasi & pegawai, akun & hak akses, site setting dinamis, autentikasi lengkap (login, ubah password, lupa password, MFA), audit log, master referensi, serta **absensi** lewat aplikasi mobile **Pakis Pro** (Flutter).

Modul penjualan, gudang, armada, Pakis Mitra, dan payroll **tidak** termasuk paket ini. Modul-modul itu dibuat di paket fase berikutnya dan akan menempel ke fondasi yang dibangun di sini.

Tiga prinsip yang berlaku di seluruh paket:

1. **Single-tenant untuk Pakis.** Tidak ada `tenant_id` (ADR-001).
2. **Semua dinamis.** Parameter bisnis, keamanan, absensi, tampilan, penomoran, role & permission, dan daftar referensi bisa diubah admin dari layar, tanpa deploy. Hanya secret yang tetap di `.env`.
3. **Setiap daftar (CRUD) wajib punya paging, pencarian, filter, dan sort.** Aturan ini ditegakkan oleh komponen bersama dan test otomatis (`docs/07-UI-SPEC.md` §3, `docs/10-TEST-PLAN.md` §3, BR-001–BR-006).

## 2. Cara Membaca

| Urutan | Dokumen | Isi |
|---|---|---|
| 1 | `CLAUDE.md` (root) | Aturan kerja agent, perintah, alur per task |
| 2 | `Implementasi_Plan.md` (root) | Fase, exit gate, **status progres** |
| 3 | `TASKS.md` (root) | Task T-001 s.d. T-048 |
| 4 | `docs/01-PRD.md` | Tujuan, persona, user story, scope |
| 5 | `docs/02-SRS.md` | FR-xxx & NFR-xxx |
| 6 | `docs/03-ARCHITECTURE.md` | Stack + versi, struktur, ADR, deployment |
| 7 | `docs/04-DATABASE.md` | ERD, DDL, index, seed |
| 8 | `docs/05-API-CONTRACT.md` | Route web, API mobile, format respons |
| 9 | `docs/06-AUTHORIZATION.md` | Role, permission, scope data, menu |
| 10 | `docs/07-UI-SPEC.md` | Standar listing, halaman web, layar mobile |
| 11 | `docs/08-BUSINESS-RULES.md` | BR-xxx |
| 12 | `docs/09-SECURITY.md` | Threat model, SEC-xxx, checklist rilis |
| 13 | `docs/10-TEST-PLAN.md` | Strategi test, test kontrak listing, UAT |
| 14 | `docs/11-GLOSSARY.md` | Istilah |
| 15 | `docs/12-OPEN-QUESTIONS.md` | Keputusan yang belum final |

## 3. Sumber Kebenaran (satu hal, satu tempat)

| Hal | Dokumen |
|---|---|
| Tabel, kolom, index, enum | `04-DATABASE.md` |
| Route & kontrak API | `05-API-CONTRACT.md` |
| Permission, role, scope, menu | `06-AUTHORIZATION.md` |
| Kolom/cari/filter/sort tiap daftar | `07-UI-SPEC.md` §3.4 (Katalog Listing) |
| Daftar kunci site setting | `08-BUSINESS-RULES.md` §2 (Registri Setting) |
| Kontrol keamanan | `09-SECURITY.md` |

## 4. Posisi Paket dalam Roadmap PAKIS ONE

| Fase | Isi | Paket |
|---|---|---|
| **0 — Core** | Auth, akun, role/permission, site setting, penomoran, audit, referensi, wilayah, lokasi, departemen, jabatan, pegawai, penugasan, import pegawai, fondasi Flutter | **Paket ini** |
| **0,5 — Absensi** | Shift, jadwal, hari libur, perangkat, absen selfie+GPS, offline, koreksi, rekap, export | **Paket ini** |
| 1 — Uang aman | Produk, kios, harga, pesanan, piutang, kwitansi WA, plafon tunai, setoran, konfirmasi saldo | Paket berikutnya |
| 2 — Barang aman | Gudang scan/FEFO, driver & POD, BBM, Pakis Mitra | Paket berikutnya |
| 3 — Lengkap | Cuti/lembur, payroll, armada GPS, CRM, analitik | Paket berikutnya |

Konsep lengkap ada di `PAKIS_ONE_Konsep_Aplikasi_v1.4.html`. Simpan di `docs/reference/` sebagai **bacaan latar saja**. Agent dilarang membangun fitur dari dokumen itu tanpa task.

## 5. Status Dokumen

| Dokumen | Status |
|---|---|
| 00–12, CLAUDE.md, AGENTS.md, Implementasi_Plan.md, TASKS.md | v1.0 final untuk eksekusi |
| Keputusan terbuka | Lihat `12-OPEN-QUESTIONS.md`. Task yang terblokir ditandai di kolom "Task terblokir" |
