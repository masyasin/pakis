import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/router.dart';
import '../../core/api/api_error.dart';
import '../../core/theme/pakis_tokens.dart';
import 'session.dart';
import 'widgets/auth_scaffold.dart';

/// "Menunggu persetujuan perangkat" (07 §5, BR-040): HP baru perlu disetujui admin di web.
/// "Coba Lagi" mengulang login dengan kredensial di memori; server tidak menggandakan permintaan.
class DevicePendingScreen extends ConsumerStatefulWidget {
  const DevicePendingScreen({super.key});

  @override
  ConsumerState<DevicePendingScreen> createState() => _DevicePendingScreenState();
}

class _DevicePendingScreenState extends ConsumerState<DevicePendingScreen> {
  bool _busy = false;
  String? _message;

  Future<void> _retry() async {
    final pending = ref.read(pendingLoginProvider);
    if (pending == null) {
      _backToLogin();
      return;
    }

    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      await ref.read(sessionProvider.notifier).login(login: pending.login, password: pending.password);
      ref.read(pendingLoginProvider.notifier).set(null);
    } on ApiError catch (e) {
      if (!mounted) {
        return;
      }
      setState(
        () => _message = e.code == ApiErrorCode.devicePending
            ? 'Perangkat masih menunggu persetujuan. Hubungi admin atau HRD, lalu coba lagi.'
            : e.message,
      );
      // Selain "masih menunggu" (mis. password diganti, MFA diminta): ulangi dari layar Masuk.
      if (e.code != ApiErrorCode.devicePending &&
          e.code != ApiErrorCode.networkOffline &&
          e.code != ApiErrorCode.timeout) {
        _backToLogin();
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  void _backToLogin() {
    ref.read(pendingLoginProvider.notifier).set(null);
    context.go(Routes.login);
  }

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;

    return AuthScaffold(
      title: 'Menunggu persetujuan perangkat',
      subtitle: 'HP ini belum terdaftar untuk akun Anda.',
      child: AuthCard(
        children: [
          Icon(Icons.phonelink_lock_outlined, size: 56, color: tokens.primary),
          const SizedBox(height: 12),
          Text(
            'Satu akun hanya bisa absen dari satu HP. Minta admin atau HRD menyetujui HP ini di menu '
            'Perangkat, lalu ketuk Coba Lagi. HP lama otomatis keluar setelah disetujui.',
            style: text.bodyLarge,
            textAlign: TextAlign.center,
          ),
          if (_message != null) ...[const SizedBox(height: 16), FormNotice(_message!)],
          const SizedBox(height: 24),
          FilledButton.icon(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            onPressed: _busy ? null : _retry,
            icon: _busy
                ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2.5))
                : const Icon(Icons.refresh),
            label: const Text('Coba Lagi'),
          ),
          const SizedBox(height: 8),
          TextButton(
            style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            onPressed: _busy ? null : _backToLogin,
            child: const Text('Kembali ke Masuk'),
          ),
        ],
      ),
    );
  }
}
