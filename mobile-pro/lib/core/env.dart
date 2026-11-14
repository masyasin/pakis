/// Nilai saat build (`--dart-define`). Ini parameter teknis klien, bukan setting bisnis:
/// setting bisnis selalu datang dari `GET /api/v1/config` (registri server, `08` §2).
abstract final class AppEnv {
  /// Alamat backend. Bawaan = backend lokal dilihat dari emulator Android (`10.0.2.2`).
  /// Build rilis wajib mengisi `--dart-define=API_BASE_URL=https://...` (T-047); cleartext
  /// ditolak di build rilis oleh manifest (SEC-067).
  static const apiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8000');

  /// Batas tunggu jaringan. Teknis (sinyal 3G lemah di lapangan, MOB-10), bukan aturan bisnis.
  static const connectTimeout = Duration(seconds: 15);
  static const receiveTimeout = Duration(seconds: 30);
}
