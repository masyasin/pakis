<?php

namespace App\Domain\Settings;

use App\Domain\Notification\Channels\ChannelAvailability;
use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Settings\Validation\ContrastRatio;
use App\Domain\Settings\Validation\Semver;
use InvalidArgumentException;

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
    ];

    /** Grup yang perubahannya butuh konfirmasi ulang password (06 §1.6, 07 §4). */
    public const SENSITIVE_GROUPS = ['security', 'auth'];

    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= collect(self::define())
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
