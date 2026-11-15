import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/providers.dart';

/// Lupa password lewat API (05 §5.1; FR-005, FR-006). Server menjawab sama untuk akun yang ada
/// maupun tidak (SEC-004), jadi layar juga tidak pernah memberi tahu apakah akunnya ditemukan.
class PasswordRecovery {
  const PasswordRecovery(this._api);

  final ApiClient _api;

  /// Kanal yang dikenal layar; urutan tampil mengikuti `auth.forgot_channels` dari `/config`.
  static const channelLabels = {'email': 'Email', 'whatsapp': 'WhatsApp', 'telegram': 'Telegram'};

  Future<void> request({required String login, required String channel}) =>
      _api.post('/api/v1/auth/password/forgot', data: {'login': login.trim(), 'channel': channel});

  Future<void> resetWithCode({
    required String login,
    required String code,
    required String password,
    required String confirmation,
  }) => _api.post(
    '/api/v1/auth/password/reset-otp',
    data: {'login': login.trim(), 'code': code.trim(), 'password': password, 'password_confirmation': confirmation},
  );
}

final passwordRecoveryProvider = Provider<PasswordRecovery>((ref) => PasswordRecovery(ref.watch(apiClientProvider)));
