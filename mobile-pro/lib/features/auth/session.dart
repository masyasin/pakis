import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_error.dart';
import '../../core/offline/offline_providers.dart';
import '../../core/providers.dart';
import '../../core/storage/secure_store.dart';
import 'mobile_user.dart';

/// Status sesi Pakis Pro (T-032).
sealed class SessionState {
  const SessionState();
}

/// Belum masuk. [notice] = pesan untuk layar Masuk (mis. "Sesi berakhir").
class SignedOut extends SessionState {
  const SignedOut({this.notice});

  final String? notice;
}

class SignedIn extends SessionState {
  const SignedIn(this.user);

  final MobileUser user;
}

/// Kerja latar yang hidup selama sesi (T-042: sinkron antrean absen offline). Dipisah sebagai
/// provider agar test bisa menggantinya tanpa membuka DB SQLCipher & kanal konektivitas.
abstract interface class SessionHooks {
  Future<void> signedIn();

  Future<void> signedOut();
}

class SyncSessionHooks implements SessionHooks {
  SyncSessionHooks(this._ref);

  final Ref _ref;

  @override
  Future<void> signedIn() async {
    // Gagal membuka antrean (mis. SQLCipher tidak tersedia) tidak boleh menggagalkan login:
    // absen online tetap jalan, antrean dicoba lagi saat aplikasi dibuka berikutnya.
    try {
      final sync = await _ref.read(syncServiceProvider.future);
      sync.start(online: _ref.read(onlineStreamProvider));
    } catch (_) {}
  }

  @override
  Future<void> signedOut() async {
    try {
      (await _ref.read(syncServiceProvider.future)).stop();
    } catch (_) {}
  }
}

final sessionHooksProvider = Provider<SessionHooks>((ref) => SyncSessionHooks(ref));

/// Sesi: token + pengguna tersimpan di [SecureStore] (SEC-020, tidak pernah SharedPreferences).
///
/// Dibuka ulang dari penyimpanan saat aplikasi dimulai, lalu disegarkan lewat `GET /me` di latar.
/// Server tidak terjangkau → tetap masuk (absen offline butuh sesi); token ditolak (401) →
/// [ApiClient.onSessionInvalid] → keluar dengan pesan.
class SessionController extends AsyncNotifier<SessionState> {
  static const expiredNotice = 'Sesi Anda sudah berakhir. Silakan masuk lagi ke akun.';

  @override
  Future<SessionState> build() async {
    ref.listen(sessionEventsProvider, (_, event) {
      if (event == null) {
        return;
      }
      if (event.passwordChange) {
        _markPasswordChangeRequired();
      } else {
        unawaited(_clear(notice: event.error.code == ApiErrorCode.deviceMismatch ? _mismatchNotice : expiredNotice));
      }
    });

    final store = ref.read(secureStoreProvider);
    final (token, userJson) = await (store.read(StoreKeys.authToken), store.read(StoreKeys.authUser)).wait;
    if (token == null || token.isEmpty || userJson == null) {
      return const SignedOut();
    }

    final MobileUser user;
    try {
      user = MobileUser.fromJson((jsonDecode(userJson) as Map).cast<String, Object?>());
    } catch (_) {
      await _forget();
      return const SignedOut();
    }

    unawaited(ref.read(sessionHooksProvider).signedIn());
    // Setelah build selesai: segarkan data (jabatan/lokasi bisa berubah) tanpa menahan layar.
    unawaited(Future<void>.microtask(refresh));
    return SignedIn(user);
  }

  static const _mismatchNotice = 'Sesi ini tidak berlaku di HP ini lagi. Silakan masuk lagi ke akun.';

  /// `POST /auth/login` (05 §5.3). Melempar [ApiError] untuk ditampilkan layar Masuk
  /// (`MFA_REQUIRED` → minta kode, `DEVICE_PENDING` → layar menunggu, dst.).
  Future<void> login({required String login, required String password, String? otp}) async {
    final native = ref.read(nativeDeviceProvider);
    final (uid, info) = await (ref.read(deviceIdProvider).uid(), native.deviceInfo()).wait;

    final response = await ref
        .read(apiClientProvider)
        .post(
          '/api/v1/auth/login',
          data: {
            'login': login.trim(),
            'password': password,
            if (otp != null && otp.trim().isNotEmpty) 'otp': otp.trim(),
            'device': {'uid': uid, 'model': info.model, 'os_version': info.osVersion},
          },
        );

    final data = response.object;
    final token = data['token'];
    final userJson = data['user'];
    if (token is! String || token.isEmpty || userJson is! Map) {
      throw const ApiError(code: ApiErrorCode.unknown);
    }

    final user = MobileUser.fromJson(userJson.cast<String, Object?>());
    final store = ref.read(secureStoreProvider);
    await store.write(StoreKeys.authToken, token);
    await _saveUser(user);
    state = AsyncData(SignedIn(user));
    unawaited(ref.read(sessionHooksProvider).signedIn());
  }

  /// `GET /me`: perbarui data pengguna. Galat jaringan diabaikan (tetap memakai data tersimpan);
  /// 401/perangkat ditangani [ApiClient.onSessionInvalid].
  Future<void> refresh() async {
    if (state.value is! SignedIn) {
      return;
    }
    try {
      final response = await ref.read(apiClientProvider).get('/api/v1/me');
      final user = MobileUser.fromJson(response.object);
      if (state.value is SignedIn) {
        await _saveUser(user);
        state = AsyncData(SignedIn(user));
      }
    } on ApiError {
      // Offline / server sibuk / sesi tidak berlaku: sudah ditangani di tempat lain.
    }
  }

  /// `PUT /me/password` (FR-004). Token HP ini tetap berlaku; token lain dicabut server.
  Future<void> changePassword({required String current, required String password, required String confirmation}) async {
    final response = await ref
        .read(apiClientProvider)
        .put(
          '/api/v1/me/password',
          data: {'current_password': current, 'password': password, 'password_confirmation': confirmation},
        );
    final user = MobileUser.fromJson(response.object);
    await _saveUser(user);
    state = AsyncData(SignedIn(user));
  }

  /// Keluar: cabut token di server bila terjangkau, lalu hapus sesi lokal apa pun hasilnya.
  Future<void> logout() async {
    try {
      await ref.read(apiClientProvider).post('/api/v1/auth/logout');
    } on ApiError {
      // Offline atau token sudah mati: sesi lokal tetap dihapus.
    }
    await _clear();
  }

  Future<void> _clear({String? notice}) async {
    if (state.value is SignedOut && notice == null) {
      return;
    }
    await ref.read(sessionHooksProvider).signedOut();
    await _forget();
    state = AsyncData(SignedOut(notice: notice));
  }

  Future<void> _forget() async {
    final store = ref.read(secureStoreProvider);
    await store.delete(StoreKeys.authToken);
    await store.delete(StoreKeys.authUser);
  }

  void _markPasswordChangeRequired() {
    final current = state.value;
    if (current is SignedIn && !current.user.mustChangePassword) {
      state = AsyncData(SignedIn(current.user.copyWith(mustChangePassword: true)));
    }
  }

  Future<void> _saveUser(MobileUser user) =>
      ref.read(secureStoreProvider).write(StoreKeys.authUser, jsonEncode(user.toJson()));
}

final sessionProvider = AsyncNotifierProvider<SessionController, SessionState>(SessionController.new);

/// Kredensial login terakhir yang menunggu persetujuan perangkat, HANYA di memori (tidak pernah
/// disimpan), agar tombol "Coba Lagi" di layar menunggu tidak meminta password lagi.
class PendingLogin {
  const PendingLogin(this.login, this.password);

  final String login;
  final String password;
}

class PendingLoginController extends Notifier<PendingLogin?> {
  @override
  PendingLogin? build() => null;

  void set(PendingLogin? value) => state = value;
}

final pendingLoginProvider = NotifierProvider<PendingLoginController, PendingLogin?>(PendingLoginController.new);

/// Identitas yang sedang memakai alur lupa password (layar kanal → layar kode OTP).
class ForgotLoginController extends Notifier<String?> {
  @override
  String? build() => null;

  void set(String? value) => state = value;
}

final forgotLoginProvider = NotifierProvider<ForgotLoginController, String?>(ForgotLoginController.new);

/// Pesan sekali tampil di layar Masuk (mis. "Password baru tersimpan").
class LoginNoticeController extends Notifier<String?> {
  @override
  String? build() => null;

  void set(String? value) => state = value;
}

final loginNoticeProvider = NotifierProvider<LoginNoticeController, String?>(LoginNoticeController.new);
