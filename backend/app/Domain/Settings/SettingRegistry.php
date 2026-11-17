<?php

namespace App\Domain\Settings;

use App\Domain\Notification\Channels\ChannelAvailability;
use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Settings\Validation\ContrastRatio;
use App\Domain\Settings\Validation\Semver;
use InvalidArgumentException;
use LogicException;

/**
 * Registri SEMUA kunci site setting (08 §2, ADR-006). Menambah kunci = tambah di sini + 08 §2;
 * SettingRegistryTest mencocokkan keduanya. Secret TIDAK PERNAH masuk registri (SEC-060).
 */
final class SettingRegistry
{
    /** Urutan & nama tab halaman Pengaturan. */
    public const GROUPS = [
        'general' => 'Umum',
        'branding' => 'Branding',
        'security' => 'Keamanan',
        'auth' => 'Lupa password',
        'mobile' => 'Aplikasi Pakis Pro',
        'attendance' => 'Presensi',
        'ui' => 'Tampilan',
        'export' => 'Ekspor',
        'import' => 'Impor',
        'notification' => 'Notifikasi',
        'monitoring' => 'Monitoring',
        'retention' => 'Retensi data',
        'approval' => 'Persetujuan',
        'product' => 'Produk',
        'upload' => 'Unggahan berkas',
        'customer' => 'Pelanggan',
        'inventory' => 'Gudang & stok',
        'pricing' => 'Harga',
        'purchasing' => 'Pembelian',
        'accounting' => 'Akuntansi',
    ];

    /** Grup yang perubahannya butuh konfirmasi ulang password (06 §1.6, 07 §4). */
    public const SENSITIVE_GROUPS = ['security', 'auth'];

    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /** @var list<SettingDefinition> definisi tambahan khusus test (lihat extendForTesting) */
    private static array $testDefinitions = [];

    /**
     * KHUSUS TEST: sisipkan definisi sementara, mis. kunci `locked`/`one_way` contoh, karena belum
     * ada kunci produksi yang memakai penanda itu dan 08 §2 tidak boleh berisi kunci palsu.
     * Panggil `extendForTesting([])` di afterEach untuk memulihkan registri.
     *
     * @param  list<SettingDefinition>  $definitions
     */
    public static function extendForTesting(array $definitions): void
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('SettingRegistry::extendForTesting hanya untuk test.');
        }

        self::$testDefinitions = $definitions;
        self::$definitions = null;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= collect([...self::define(), ...self::$testDefinitions])
            ->keyBy(fn (SettingDefinition $d) => $d->key)
            ->all();
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function get(string $key): SettingDefinition
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Setting [{$key}] tidak terdaftar di SettingRegistry.");
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public static function group(string $group): array
    {
        return array_filter(self::all(), fn (SettingDefinition $d) => $d->group() === $group);
    }

    /** Grup berisi kunci `locked`: super_admin wajib konfirmasi password sebelum membuka form. */
    public static function hasLocked(string $group): bool
    {
        return array_filter(self::group($group), fn (SettingDefinition $d) => $d->locked) !== [];
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public static function public(): array
    {
        return array_filter(self::all(), fn (SettingDefinition $d) => $d->isPublic);
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function define(): array
    {
        $t = fn (string $key, SettingType $type, mixed $default, string $label, mixed ...$rest) => new SettingDefinition($key, $type, $default, $label, ...$rest);

        return [
            // 2.1 Umum & Branding
            $t('general.company_name', SettingType::String, 'CV Pakis Jaya Abadi', 'Nama perusahaan', 'Tampil di kop, email, dan aplikasi.', isPublic: true, min: 3, max: 150),
            $t('general.app_name', SettingType::String, 'PAKIS ONE', 'Nama aplikasi', 'Judul di browser dan aplikasi mobile.', isPublic: true, min: 2, max: 40),
            $t('general.address', SettingType::Text, null, 'Alamat', nullable: true, max: 500),
            $t('general.phone', SettingType::String, null, 'Telepon', 'Format Indonesia, mis. 0291 123456 atau 0812-3456-7890.', nullable: true, max: 20,
                check: fn ($v) => $v === null || preg_match('/^(\+62|62|0)\d{7,13}$/', (string) preg_replace('/[\s-]/', '', (string) $v)) === 1 ? null : 'Nomor telepon tidak valid.'),
            $t('general.email', SettingType::Email, null, 'Email perusahaan', nullable: true, max: 150),
            $t('general.website', SettingType::Url, null, 'Situs web', 'Harus diawali https://', nullable: true, max: 255),
            $t('general.timezone', SettingType::Enum, 'Asia/Jakarta', 'Zona waktu', 'Zona untuk tampilan tanggal & jam. Data tetap disimpan UTC.', isPublic: true,
                options: ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura']),
            $t('branding.logo_light', SettingType::Image, null, 'Logo (tema terang)', 'PNG/JPG/WebP, maks 1 MB, maks 1024 px. SVG tidak diterima.', isPublic: true, nullable: true),
            $t('branding.logo_dark', SettingType::Image, null, 'Logo (tema gelap)', 'Kosong = memakai logo tema terang.', isPublic: true, nullable: true),
            $t('branding.favicon', SettingType::Image, null, 'Favicon', 'PNG persegi 32–512 px.', isPublic: true, nullable: true),
            $t('branding.primary_color', SettingType::Color, '#2F6B3B', 'Warna utama', 'Harus cukup gelap: kontras minimal 4.5:1 terhadap putih.', isPublic: true,
                check: fn ($v) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1 ? ContrastRatio::problem($v) : null),
            $t('branding.login_tagline', SettingType::String, 'Distribusi pestisida & alat pertanian', 'Tagline halaman login', isPublic: true, nullable: true, max: 120),

            // 2.2 Keamanan & Autentikasi
            $t('security.password_min_length', SettingType::Int, 10, 'Panjang minimal password', isPublic: true, min: 8, max: 64),
            $t('security.password_check_breached', SettingType::Bool, true, 'Tolak password yang pernah bocor', 'Butuh koneksi internet ke layanan pengecekan. Bila layanan tidak bisa dihubungi, pengecekan dilewati.'),
            $t('security.login_max_attempts', SettingType::Int, 5, 'Batas percobaan login', 'Lebih dari ini akun dikunci sementara.', min: 3, max: 20),
            $t('security.lockout_minutes', SettingType::Int, 15, 'Lama kunci akun (menit)', min: 1, max: 1440),
            $t('security.account_max_failures', SettingType::Int, 20, 'Batas gagal per akun (semua IP)', 'Total login gagal dari jaringan mana pun dalam rentang lama kunci; mencapai batas ini akun dikunci. Menahan penyerang yang berganti-ganti IP.', min: 10, max: 100),
            $t('security.session_idle_minutes', SettingType::Int, 120, 'Sesi berakhir bila tidak aktif (menit)', min: 15, max: 720),
            $t('security.session_absolute_hours', SettingType::Int, 12, 'Batas umur sesi (jam)', min: 1, max: 72),
            $t('security.password_confirm_minutes', SettingType::Int, 15, 'Konfirmasi ulang password berlaku (menit)', min: 5, max: 120),
            $t('security.reset_token_minutes', SettingType::Int, 60, 'Tautan reset password berlaku (menit)', min: 15, max: 1440),
            $t('security.notify_new_device_login', SettingType::Bool, true, 'Email saat login dari perangkat baru'),
            $t('auth.forgot_channels', SettingType::MultiEnum, ['email'], 'Kanal lupa password', 'Kanal yang dicentang tampil di halaman lupa password. Matikan WhatsApp/Telegram bila kuota habis atau nomor/bot diblokir. WhatsApp & Telegram butuh penyedia OTP aktif.', isPublic: true,
                options: ['email', 'whatsapp', 'telegram'], min: 1, check: self::checkForgotChannels(...)),
            $t('auth.otp_length', SettingType::Int, 6, 'Panjang kode OTP', min: 6, max: 8),
            $t('auth.otp_minutes', SettingType::Int, 10, 'Kode OTP berlaku (menit)', min: 3, max: 30),

            // 2.3 Mobile
            $t('mobile.min_version', SettingType::String, '1.0.0', 'Versi minimum Pakis Pro', 'Di bawah versi ini aplikasi wajib diperbarui. Format 1.2.3.', isPublic: true, max: 20,
                check: fn ($v) => Semver::isValid(is_string($v) ? $v : null) ? null : 'Format versi harus 1.2.3.'),
            $t('mobile.latest_version', SettingType::String, '1.0.0', 'Versi terbaru Pakis Pro', 'Tidak boleh lebih rendah dari versi minimum.', isPublic: true, max: 20, check: self::checkLatestVersion(...)),
            $t('mobile.download_url', SettingType::Url, null, 'URL unduh APK', 'Harus diawali https://', isPublic: true, nullable: true, max: 255),
            $t('mobile.update_message', SettingType::String, 'Versi baru Pakis Pro tersedia.', 'Pesan pembaruan', isPublic: true, max: 200),
            $t('mobile.auto_approve_first_device', SettingType::Bool, true, 'Setujui otomatis HP pertama pegawai'),
            $t('mobile.token_days', SettingType::Int, 90, 'Masa berlaku login aplikasi (hari)', min: 7, max: 365),

            // 2.4 Presensi
            $t('attendance.require_selfie', SettingType::Bool, true, 'Wajib selfie saat presensi', isPublic: true),
            $t('attendance.default_radius_m', SettingType::Int, 100, 'Radius lokasi bawaan (meter)', 'Dipakai bila lokasi tidak punya radius sendiri.', min: 20, max: 5000),
            $t('attendance.max_accuracy_m', SettingType::Int, 100, 'Akurasi GPS maksimal (meter)', isPublic: true, min: 10, max: 500),
            $t('attendance.offline_max_hours', SettingType::Int, 72, 'Batas presensi offline (jam)', isPublic: true, min: 1, max: 168),
            $t('attendance.max_clock_skew_minutes', SettingType::Int, 10, 'Toleransi selisih jam HP (menit)', min: 1, max: 120),
            $t('attendance.allow_unscheduled', SettingType::Bool, false, 'Izinkan presensi tanpa jadwal'),
            $t('attendance.allow_checkout_without_checkin', SettingType::Bool, false, 'Izinkan presensi pulang tanpa presensi masuk'),
            $t('attendance.min_minutes_between_in_out', SettingType::Int, 5, 'Jeda minimal masuk–pulang (menit)', min: 0, max: 240),
            $t('attendance.early_leave_tolerance_min', SettingType::Int, 0, 'Toleransi pulang cepat (menit)', min: 0, max: 120),
            $t('attendance.daily_close_time', SettingType::Time, '23:30', 'Jam tutup harian', 'Setelah jam ini pegawai yang tidak presensi ditandai.'),
            $t('attendance.correction_max_days', SettingType::Int, 7, 'Batas pengajuan koreksi (hari)', isPublic: true, min: 1, max: 60),
            $t('attendance.correction_attachment_max_kb', SettingType::Int, 2048, 'Ukuran maksimal lampiran koreksi (KB)', 'Foto/PDF bukti koreksi presensi.', isPublic: true, min: 256, max: 5120),
            $t('attendance.selfie_retention_days', SettingType::Int, 365, 'Masa simpan selfie (hari)', min: 30, max: 1825),
            $t('attendance.field_trip_max_days', SettingType::Int, 7, 'Lama maksimal tugas luar kota (hari)', 'Batas satu tugas luar kota/dinas luar (BR-071).', min: 1, max: 31),

            // 2.5 Tampilan, Ekspor, Impor, Notifikasi, Monitoring, Retensi
            $t('ui.default_per_page', SettingType::Enum, 25, 'Baris per halaman bawaan', options: [10, 25, 50, 100]),
            $t('ui.max_per_page', SettingType::Int, 100, 'Batas baris per halaman', min: 25, max: 100),
            // 0 mematikan; di bawah 15 detik ditolak agar layar dasbor yang dibiarkan terbuka tidak membebani server.
            $t('ui.dashboard_refresh_seconds', SettingType::Int, 60, 'Muat ulang dasbor otomatis (detik)', '0 = mati. Selain 0, minimal 15 detik.', min: 0, max: 600,
                check: fn ($v) => (int) $v === 0 || (int) $v >= 15 ? null : 'Isi 0 (mati) atau minimal 15 detik.'),
            $t('ui.dashboard_max_range_days', SettingType::Int, 92, 'Rentang grafik dasbor terpanjang (hari)', 'Rentang tanggal yang lebih panjang dipotong ke jumlah hari ini.', min: 7, max: 366),
            $t('export.sync_max_rows', SettingType::Int, 2000, 'Ekspor langsung sampai (baris)', 'Lebih dari ini diproses di antrean.', min: 100, max: 10000),
            $t('export.max_rows', SettingType::Int, 100000, 'Batas ekspor (baris)', min: 1000, max: 500000),
            $t('export.mass_export_alert_rows', SettingType::Int, 1000, 'Peringatan ekspor massal (baris)', 'Ekspor lebih besar dicatat di log keamanan.', min: 100, max: 100000),
            $t('import.max_file_mb', SettingType::Int, 5, 'Ukuran berkas impor maks (MB)', min: 1, max: 20),
            $t('import.max_rows', SettingType::Int, 2000, 'Baris impor maks', min: 50, max: 10000),
            $t('notification.mail_from_name', SettingType::String, 'PAKIS ONE', 'Nama pengirim email', max: 60),
            // BR-070 (T-029): notifikasi aplikasi selalu aktif; kanal luar = kanal jenis ∩ kanal global ∩ alamat penerima.
            $t('notification.channels', SettingType::MultiEnum, ['email'], 'Kanal notifikasi aktif', 'Notifikasi di aplikasi (lonceng) selalu aktif. Matikan WhatsApp/Telegram di sini bila kuota habis atau nomor/bot diblokir; notifikasi dialihkan ke kanal cadangan.',
                nullable: true, options: DeliveryChannel::values(), check: self::checkNotificationChannels(...)),
            $t('notification.on_export_ready', SettingType::MultiEnum, [], 'Kanal: ekspor siap', 'Kosong = hanya di aplikasi.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_import_done', SettingType::MultiEnum, [], 'Kanal: impor selesai', 'Kosong = hanya di aplikasi.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_password_changed', SettingType::MultiEnum, ['email'], 'Kanal: password diganti', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_new_device_login', SettingType::MultiEnum, ['email'], 'Kanal: login perangkat baru', 'Hanya terkirim bila "Email saat login dari perangkat baru" (Keamanan) aktif.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_account_locked', SettingType::MultiEnum, ['email'], 'Kanal: akun dikunci', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_device_pending', SettingType::MultiEnum, [], 'Kanal: perangkat menunggu persetujuan', 'Penerima: admin yang bisa mengelola perangkat. Notifikasi lonceng selalu dikirim.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_attendance_mock', SettingType::MultiEnum, [], 'Kanal: lokasi palsu terdeteksi', 'Penerima: HRD dan kepala lokasi pegawai. Notifikasi lonceng selalu dikirim.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_correction_submitted', SettingType::MultiEnum, [], 'Kanal: koreksi presensi diajukan', 'Penerima: atasan langsung, atau kepala lokasi + HRD bila tidak ada atasan aktif. Notifikasi lonceng selalu dikirim.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_correction_decided', SettingType::MultiEnum, ['email'], 'Kanal: koreksi presensi diputuskan', 'Penerima: pengaju koreksi, atau pegawai yang presensinya diisi manual.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_field_trip_submitted', SettingType::MultiEnum, [], 'Kanal: tugas luar kota diajukan', 'Penerima: atasan langsung pengaju, atau kepala lokasi + HRD bila tidak ada atasan aktif. Notifikasi lonceng selalu dikirim.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_field_trip_decided', SettingType::MultiEnum, ['email'], 'Kanal: tugas luar kota diputuskan', 'Penerima: pegawai yang mengajukan dari Pakis Pro.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_approval_requested', SettingType::MultiEnum, [], 'Kanal: menunggu persetujuan', 'Penerima: calon penyetuju langkah aktif, termasuk delegasinya. Notifikasi lonceng selalu dikirim.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_approval_decided', SettingType::MultiEnum, [], 'Kanal: persetujuan diputuskan', 'Penerima: pengaju permintaan persetujuan.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_approval_escalated', SettingType::MultiEnum, [], 'Kanal: persetujuan tereskalasi', 'Penerima: penyetuju langkah dan pemegang izin eskalasi.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_registration_expiring', SettingType::MultiEnum, [], 'Kanal: izin edar akan habis', 'Penerima: pemegang izin kelola izin edar. Dikirim H-60/30/7/0 sebelum izin habis, sekali per ambang.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_customer_phone_changed', SettingType::MultiEnum, [], 'Kanal: HP kios diganti', 'Penerima: pemegang izin menyetujui ganti HP kios (bukan Super Admin). Nomor lama dan baru selalu termasking.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.on_stock_reconcile_mismatch', SettingType::MultiEnum, [], 'Kanal: selisih rekonsiliasi stok', 'Penerima: Owner dan Super Admin. Dikirim bila rekonsiliasi harian 01.00 menemukan saldo batch yang tidak cocok dengan kartu stok.', nullable: true, options: DeliveryChannel::values(), check: self::checkChannelSubset(...)),
            $t('notification.whatsapp_daily_limit', SettingType::Int, 300, 'Batas WhatsApp per hari', 'Lebih dari ini notifikasi dialihkan ke Telegram/Email. 0 = WhatsApp tidak dipakai untuk notifikasi.', min: 0, max: 10000),
            $t('notification.whatsapp_hourly_per_user', SettingType::Int, 5, 'Batas WhatsApp per orang per jam', 'Mencegah nomor dianggap spam dan diblokir.', min: 1, max: 50),
            $t('notification.circuit_failures', SettingType::Int, 5, 'Jeda kanal setelah gagal berturut-turut', 'Kanal yang gagal sebanyak ini dijeda sementara dan notifikasi dialihkan ke cadangan.', min: 3, max: 20),
            $t('notification.circuit_minutes', SettingType::Int, 15, 'Lama jeda kanal (menit)', min: 5, max: 120),
            $t('monitoring.alert_emails', SettingType::EmailList, [], 'Email penerima peringatan keamanan', 'Maksimal 5 alamat, satu per baris.', nullable: true, max: 5, check: self::checkEmailList(...)),
            $t('monitoring.login_burst_count', SettingType::Int, 20, 'Peringatan login gagal dari satu IP', 'Jumlah login gagal dari satu alamat IP dalam jendela waktu di bawah yang memicu email peringatan (SEC-041).', min: 5, max: 1000),
            $t('monitoring.login_burst_minutes', SettingType::Int, 10, 'Jendela hitung login gagal (menit)', min: 1, max: 120),
            $t('monitoring.alert_mute_minutes', SettingType::Int, 60, 'Redam peringatan berulang (menit)', 'Peringatan login gagal dari IP yang sama tidak dikirim ulang selama ini.', min: 5, max: 1440),
            $t('monitoring.work_hour_start', SettingType::Int, 6, 'Jam kerja mulai (pantau ekspor massal)', 'Ekspor massal di luar jam kerja memicu peringatan. Jam lokal, 0–23.', min: 0, max: 23),
            $t('monitoring.work_hour_end', SettingType::Int, 21, 'Jam kerja selesai (pantau ekspor massal)', 'Jam lokal, 1–24 (24 = tengah malam). Bila lebih kecil dari jam mulai, jam kerja melewati tengah malam.', min: 1, max: 24),
            $t('retention.audit_years', SettingType::Int, 5, 'Masa simpan audit (tahun)', min: 1, max: 10),
            $t('retention.security_days', SettingType::Int, 365, 'Masa simpan log keamanan (hari)', min: 90, max: 1825),
            $t('retention.import_days', SettingType::Int, 90, 'Masa simpan berkas impor (hari)', min: 7, max: 365),
            $t('retention.export_days', SettingType::Int, 7, 'Masa simpan berkas ekspor (hari)', min: 1, max: 30),

            // 2.6 Persetujuan (T-067, 15 §9)
            $t('approval.auto_approve_when_no_rule', SettingType::Bool, false, 'Setujui otomatis bila tidak ada aturan', 'Mati = dokumen tanpa aturan persetujuan yang cocok tidak bisa diajukan. Nyala = dokumen itu langsung disetujui tanpa penyetuju.'),
            $t('approval.default_escalation_hours', SettingType::Int, 24, 'Eskalasi bawaan (jam)', 'Langkah yang belum diputuskan selama ini diteruskan ke penyetuju eskalasi. Jam kalender.', min: 1, max: 168),
            $t('approval.reauth_amount', SettingType::Money, '50000000', 'Nominal wajib konfirmasi password', 'Menyetujui dokumen senilai ini atau lebih meminta password lagi.', min: 0),

            // 2.7 Produk (T-070, 15 §9)
            $t('product.sku_mode', SettingType::Enum, 'manual', 'Cara mengisi SKU', 'Manual = diketik admin (huruf besar, angka, strip). Otomatis = nomor dari Penomoran (pola `product`).', options: ['manual', 'auto']),

            // 2.8 Unggahan berkas (T-070, SEC-109)
            $t('upload.max_image_kb', SettingType::Int, 5120, 'Ukuran foto maksimal (KB)', 'Berlaku untuk foto JPG/PNG/WebP di semua unggahan Paket A.', min: 512, max: 10240),
            $t('upload.max_pdf_kb', SettingType::Int, 10240, 'Ukuran PDF maksimal (KB)', 'Berlaku untuk dokumen PDF (izin edar, berita acara, dll.).', min: 512, max: 20480),

            // 2.9 Pelanggan (T-073 data inti; T-074 verifikasi, OTP, duplikat — 15 §9, BR-165/166, SEC-105).
            $t('customer.default_term_days', SettingType::Int, 30, 'Termin bawaan kios baru (hari)', 'Diisi otomatis di form kios baru; bisa diubah per kios.', min: 0, max: 365),
            $t('customer.code_mode', SettingType::Enum, 'auto', 'Cara mengisi kode kios', 'Otomatis = nomor dari Penomoran (pola `customer`, mis. KIO-00001). Manual = diketik admin (huruf besar, angka, strip).', options: ['auto', 'manual']),
            $t('customer.otp_enabled', SettingType::Bool, false, 'Verifikasi kios lewat OTP WhatsApp', 'Mati = verifikasi kios memakai telepon kantor. Hanya bisa dinyalakan bila penyedia OTP WhatsApp sudah dikonfigurasi.',
                check: self::checkCustomerOtp(...)),
            $t('customer.otp_minutes', SettingType::Int, 5, 'Masa berlaku OTP kios (menit)', 'Terpisah dari masa berlaku OTP lupa password.', min: 3, max: 30),
            $t('customer.otp_max_attempts', SettingType::Int, 5, 'Batas percobaan OTP kios', 'Lewat batas ini kode hangus dan harus dikirim ulang.', min: 3, max: 10),
            $t('customer.otp_send_per_phone_hour', SettingType::Int, 3, 'Kirim OTP per nomor per jam', 'Mencegah nomor pemilik kios dibanjiri kode.', min: 1, max: 10),
            $t('customer.otp_send_per_user_hour', SettingType::Int, 20, 'Kirim OTP per pengguna per jam', min: 5, max: 100),
            $t('customer.verify_max_accuracy_m', SettingType::Int, 50, 'Akurasi titik verifikasi maksimal (meter)', 'Titik dengan akurasi GPS lebih buruk dari ini ditolak saat kirim verifikasi.', min: 5, max: 500),
            $t('customer.duplicate_radius_m', SettingType::Int, 300, 'Radius cek duplikat kios (meter)', 'Kios bernama mirip dalam radius ini (atau di desa yang sama) ditandai kemungkinan duplikat. 0 = hanya desa yang sama.', min: 0, max: 5000),
            $t('customer.duplicate_name_similarity', SettingType::Decimal, '0.8', 'Ambang kemiripan nama kios', '0,5–1. Makin tinggi makin ketat (1 = nama harus sama setelah kata umum "toko", "tani", "ud", "cv" dibuang).', min: '0.5', max: '1'),

            // 2.10 Gudang & stok (15 §9). T-071: pengingat izin edar; kunci inventory.* lain ditambah T-077+.
            $t('inventory.registration_reminder_days', SettingType::Int, 60, 'Ambang "izin edar akan habis" (hari)', 'Izin yang habis dalam jumlah hari ini ditandai akan habis dan memicu pengingat pertama. Pengingat berikutnya H-30, H-7, dan H-0.', min: 7, max: 365),
            // T-077 buku stok. BR-101: stok tidak pernah minus; kunci ada agar keputusan itu tampil &
            // terkunci di Pengaturan, bukan tersembunyi di kode. Selain `false` ditolak di sini dan di StockLedger.
            $t('inventory.negative_stock_allowed', SettingType::Bool, false, 'Izinkan stok minus', 'Terkunci mati: stok tidak pernah boleh minus (BR-101). Ditampilkan agar aturan ini terlihat; nilainya hanya boleh mati.',
                locked: true, check: fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'Stok minus tidak didukung; pengaturan ini hanya boleh mati.' : null),
            $t('inventory.reservation_ttl_hours', SettingType::Int, 72, 'Masa berlaku reservasi stok (jam)', 'Reservasi batch (mutasi draft, retur prinsipal disetujui) yang lewat masa ini dilepas otomatis tiap 15 menit.', min: 1, max: 720),

            // 2.11 Harga (T-075, 15 §9, BR-168).
            $t('pricing.rounding', SettingType::Enum, 'none', 'Pembulatan harga hasil salin ±%/promo', 'Dibulatkan ke atas ke kelipatan ini. Tanpa pembulatan = dua desimal biasa.', options: ['none', '100', '500', '1000']),
            $t('pricing.allow_backdated_publish', SettingType::Bool, false, 'Izinkan versi harga berlaku surut', 'Bila aktif, pemegang izin terbit harga boleh menerbitkan versi yang mulai berlaku di masa lalu dengan alasan tertulis. Bawaan: tidak boleh.'),

            // 2.12 Pembelian (T-079, 15 §9). Kunci penerimaan (`purchasing.*`) ditambah T-080.
            $t('purchasing.default_payment_term_days', SettingType::Int, 30, 'Tempo bayar bawaan PO (hari)', 'Bawaan di form PO sebelum supplier dipilih. Bila dikosongkan, PO memakai tempo supplier. 0 = tunai.', min: 0, max: 365),

            // 2.13 Akuntansi (T-077, 15 §9, ADR-115). Terkunci selama Paket A; aksi ubah tanggal kunci
            // dengan aturan maju/mundur (BR-229, izin accounting.lock) menggantikannya di T-096.
            $t('accounting.lock_date', SettingType::Date, null, 'Tanggal kunci akuntansi', 'Dokumen stok ber-jurnal (penerimaan, retur prinsipal, mutasi, opname, pemusnahan, saldo awal) bertanggal pada atau sebelum tanggal ini ditolak. Kosong = tidak ada periode terkunci.',
                nullable: true, locked: true),
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function checkForgotChannels(mixed $value, array $group): ?string
    {
        $value = (array) $value;
        if (array_diff($value, ['email', 'whatsapp', 'telegram']) !== []) {
            return 'Kanal tidak dikenal.';
        }

        // Kanal OTP tanpa driver yang tersedia = pengguna menunggu kode yang tak pernah datang.
        foreach (['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram'] as $channel => $label) {
            if (in_array($channel, $value, true) && ! OtpManager::available($channel)) {
                return "{$label} belum bisa dipakai: penyedia OTP belum dikonfigurasi.";
            }
        }

        return null;
    }

    /**
     * OTP kios hanya lewat WhatsApp (BR-165, PA-6): menyalakan tanpa driver WhatsApp yang tersedia =
     * sales menunggu kode yang tak pernah datang, jadi ditolak sejak di Pengaturan.
     *
     * @param  array<string, mixed>  $group
     */
    private static function checkCustomerOtp(mixed $value, array $group): ?string
    {
        return (bool) $value && ! OtpManager::available('whatsapp')
            ? 'OTP WhatsApp belum bisa dipakai: penyedia WhatsApp belum dikonfigurasi. Verifikasi kios memakai telepon kantor.'
            : null;
    }

    /**
     * Opsi multi enum TIDAK divalidasi otomatis per item: nilai di luar daftar kanal ditolak di sini.
     *
     * @param  array<string, mixed>  $group
     */
    private static function checkChannelSubset(mixed $value, array $group): ?string
    {
        return array_diff((array) $value, DeliveryChannel::values()) === [] ? null : 'Kanal tidak dikenal.';
    }

    /**
     * Kanal tanpa driver yang tersedia = notifikasi yang tak pernah sampai; tolak sejak di Pengaturan.
     *
     * @param  array<string, mixed>  $group
     */
    private static function checkNotificationChannels(mixed $value, array $group): ?string
    {
        if (($problem = self::checkChannelSubset($value, $group)) !== null) {
            return $problem;
        }

        foreach ((array) $value as $raw) {
            $channel = DeliveryChannel::from((string) $raw);
            if (! ChannelAvailability::available($channel)) {
                return "{$channel->label()} belum bisa dipakai: penyedia/bot belum dikonfigurasi.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function checkLatestVersion(mixed $value, array $group): ?string
    {
        if (! Semver::isValid(is_string($value) ? $value : null)) {
            return 'Format versi harus 1.2.3.';
        }

        $min = $group['mobile.min_version'] ?? null;

        return is_string($min) && Semver::isValid($min) && Semver::lessThan($value, $min)
            ? 'Versi terbaru tidak boleh lebih rendah dari versi minimum.'
            : null;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function checkEmailList(mixed $value, array $group): ?string
    {
        foreach ((array) $value as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return "Alamat email tidak valid: {$email}";
            }
        }

        return null;
    }
}
