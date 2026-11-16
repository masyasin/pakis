<?php

namespace App\Support\Api;

/**
 * Kode galat (05 §2.3 untuk API, §2.4a untuk kode khusus web). Nilai identik dengan kontrak dan
 * dicocokkan dua arah oleh tests/Contract/ApiErrorFormatTest.php, jadi menambah kode = ubah dokumen
 * + enum ini. Kode §2.4a sengaja tidak wajib ada di Pakis Pro (PA-32), karena Paket A tanpa API.
 *
 * Pesan di sini generik dan aman untuk pengguna akhir. Pesan yang lebih spesifik
 * (mis. jarak dari lokasi) dikirim lewat `details`, bukan dengan membocorkan pesan exception.
 */
enum ErrorCode: string
{
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case MFA_REQUIRED = 'MFA_REQUIRED';
    case MFA_INVALID = 'MFA_INVALID';
    case MFA_SETUP_REQUIRED = 'MFA_SETUP_REQUIRED';
    case ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';
    case PASSWORD_CHANGE_REQUIRED = 'PASSWORD_CHANGE_REQUIRED';
    case DEVICE_PENDING = 'DEVICE_PENDING';
    case DEVICE_MISMATCH = 'DEVICE_MISMATCH';
    case APP_UPDATE_REQUIRED = 'APP_UPDATE_REQUIRED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case RATE_LIMITED = 'RATE_LIMITED';
    case STATE_CONFLICT = 'STATE_CONFLICT';
    case ATTENDANCE_NO_SCHEDULE = 'ATTENDANCE_NO_SCHEDULE';
    case ATTENDANCE_OUTSIDE_WINDOW = 'ATTENDANCE_OUTSIDE_WINDOW';
    case ATTENDANCE_ALREADY_CHECKED_IN = 'ATTENDANCE_ALREADY_CHECKED_IN';
    case ATTENDANCE_NOT_CHECKED_IN = 'ATTENDANCE_NOT_CHECKED_IN';
    case ATTENDANCE_OUT_OF_RADIUS = 'ATTENDANCE_OUT_OF_RADIUS';
    case ATTENDANCE_MOCK_LOCATION = 'ATTENDANCE_MOCK_LOCATION';
    case ATTENDANCE_LOW_ACCURACY = 'ATTENDANCE_LOW_ACCURACY';
    case ATTENDANCE_OFFLINE_TOO_OLD = 'ATTENDANCE_OFFLINE_TOO_OLD';
    case CORRECTION_DUPLICATE_PENDING = 'CORRECTION_DUPLICATE_PENDING';
    case CORRECTION_NOT_ALLOWED = 'CORRECTION_NOT_ALLOWED';
    case CORRECTION_SELF_APPROVAL = 'CORRECTION_SELF_APPROVAL';
    case CHANNEL_UNAVAILABLE = 'CHANNEL_UNAVAILABLE';
    case BAD_REQUEST = 'BAD_REQUEST';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    case SERVER_ERROR = 'SERVER_ERROR';
    // Khusus web (05 §2.4a), dipakai mesin approval Paket A (T-067).
    case APPROVAL_RULE_MISSING = 'APPROVAL_RULE_MISSING';
    case APPROVAL_SELF_DECISION = 'APPROVAL_SELF_DECISION';
    case APPROVAL_ALREADY_PENDING = 'APPROVAL_ALREADY_PENDING';
    // Khusus web (05 §2.4a), satuan & konversi (BR-150, T-069).
    case QTY_NOT_INTEGER = 'QTY_NOT_INTEGER';

    public function status(): int
    {
        return match ($this) {
            self::BAD_REQUEST => 400,
            self::UNAUTHENTICATED,
            self::INVALID_CREDENTIALS,
            self::MFA_REQUIRED,
            self::MFA_INVALID,
            self::DEVICE_MISMATCH => 401,
            self::MFA_SETUP_REQUIRED,
            self::PASSWORD_CHANGE_REQUIRED,
            self::DEVICE_PENDING,
            self::FORBIDDEN,
            self::CORRECTION_SELF_APPROVAL,
            self::APPROVAL_SELF_DECISION => 403,
            self::NOT_FOUND => 404,
            self::METHOD_NOT_ALLOWED => 405,
            self::STATE_CONFLICT,
            self::ATTENDANCE_ALREADY_CHECKED_IN,
            self::ATTENDANCE_NOT_CHECKED_IN,
            self::CORRECTION_DUPLICATE_PENDING,
            self::APPROVAL_ALREADY_PENDING => 409,
            self::PAYLOAD_TOO_LARGE => 413,
            self::VALIDATION_FAILED,
            self::ATTENDANCE_NO_SCHEDULE,
            self::ATTENDANCE_OUTSIDE_WINDOW,
            self::ATTENDANCE_OUT_OF_RADIUS,
            self::ATTENDANCE_MOCK_LOCATION,
            self::ATTENDANCE_LOW_ACCURACY,
            self::ATTENDANCE_OFFLINE_TOO_OLD,
            self::CORRECTION_NOT_ALLOWED,
            self::APPROVAL_RULE_MISSING,
            self::QTY_NOT_INTEGER => 422,
            self::ACCOUNT_LOCKED => 423,
            self::APP_UPDATE_REQUIRED => 426,
            self::RATE_LIMITED => 429,
            self::SERVER_ERROR => 500,
            self::CHANNEL_UNAVAILABLE,
            self::SERVICE_UNAVAILABLE => 503,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::VALIDATION_FAILED => 'Data yang dikirim belum benar.',
            self::UNAUTHENTICATED => 'Sesi Anda berakhir. Silakan masuk kembali.',
            // SEC-001: satu pesan untuk user tidak ada, password salah, dan akun nonaktif.
            self::INVALID_CREDENTIALS => 'Username/email atau password salah.',
            self::MFA_REQUIRED => 'Masukkan kode dari aplikasi authenticator.',
            self::MFA_INVALID => 'Kode verifikasi salah.',
            self::MFA_SETUP_REQUIRED => 'Aktifkan verifikasi dua langkah di web (Profil → Keamanan) sebelum melanjutkan.',
            self::ACCOUNT_LOCKED => 'Akun dikunci sementara karena terlalu banyak percobaan. Coba lagi nanti.',
            self::PASSWORD_CHANGE_REQUIRED => 'Anda wajib mengganti password sebelum melanjutkan.',
            self::DEVICE_PENDING => 'Perangkat ini menunggu persetujuan admin.',
            self::DEVICE_MISMATCH => 'Perangkat tidak dikenali. Silakan masuk kembali.',
            self::APP_UPDATE_REQUIRED => 'Versi aplikasi sudah tidak didukung. Silakan perbarui aplikasi.',
            self::FORBIDDEN => 'Anda tidak punya akses untuk tindakan ini.',
            self::NOT_FOUND => 'Data tidak ditemukan.',
            self::RATE_LIMITED => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.',
            self::STATE_CONFLICT => 'Data sudah berubah. Muat ulang lalu coba lagi.',
            self::ATTENDANCE_NO_SCHEDULE => 'Tidak ada jadwal kerja hari ini.',
            self::ATTENDANCE_OUTSIDE_WINDOW => 'Sekarang di luar jam presensi untuk shift Anda.',
            self::ATTENDANCE_ALREADY_CHECKED_IN => 'Presensi masuk Anda hari ini sudah tercatat.',
            self::ATTENDANCE_NOT_CHECKED_IN => 'Anda belum presensi masuk hari ini.',
            self::ATTENDANCE_OUT_OF_RADIUS => 'Anda berada di luar area lokasi kerja.',
            self::ATTENDANCE_MOCK_LOCATION => 'Lokasi palsu terdeteksi. Matikan aplikasi pengubah lokasi.',
            self::ATTENDANCE_LOW_ACCURACY => 'Sinyal GPS lemah. Pindah ke tempat terbuka lalu coba lagi.',
            self::ATTENDANCE_OFFLINE_TOO_OLD => 'Presensi offline ini sudah terlalu lama tersimpan dan tidak bisa dikirim. Ajukan koreksi presensi.',
            self::CORRECTION_DUPLICATE_PENDING => 'Sudah ada pengajuan koreksi yang menunggu untuk tanggal ini.',
            self::CORRECTION_NOT_ALLOWED => 'Tanggal ini tidak bisa diajukan koreksi.',
            self::CORRECTION_SELF_APPROVAL => 'Anda tidak boleh menyetujui koreksi milik sendiri.',
            self::CHANNEL_UNAVAILABLE => 'Layanan pengiriman kode sedang tidak tersedia.',
            self::BAD_REQUEST => 'Permintaan tidak dapat diproses.',
            self::METHOD_NOT_ALLOWED => 'Permintaan tidak didukung.',
            self::PAYLOAD_TOO_LARGE => 'Berkas atau data terlalu besar.',
            self::SERVICE_UNAVAILABLE => 'Server sedang dalam perbaikan. Coba lagi beberapa saat lagi.',
            self::SERVER_ERROR => 'Terjadi kesalahan di server. Coba lagi atau hubungi admin dengan kode permintaan.',
            self::APPROVAL_RULE_MISSING => 'Belum ada aturan persetujuan yang cocok untuk dokumen ini. Hubungi admin.',
            // SEC-101: maker-checker; pengaju/pembuat dokumen tidak boleh memutuskan sendiri.
            self::APPROVAL_SELF_DECISION => 'Anda tidak boleh memutuskan persetujuan atas dokumen yang Anda ajukan atau buat sendiri.',
            self::APPROVAL_ALREADY_PENDING => 'Dokumen ini sudah punya permintaan persetujuan yang masih berjalan.',
            self::QTY_NOT_INTEGER => 'Jumlah untuk satuan ini harus bilangan bulat.',
        };
    }
}
