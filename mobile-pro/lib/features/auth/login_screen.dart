import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../shared/widgets/empty_state.dart';
import '../startup/startup_controller.dart';

/// Tujuan setelah splash lolos gerbang versi.
///
/// TODO(T-032): ganti dengan layar Login (07 §5: username/email, password, MFA, perangkat
/// menunggu). T-030 hanya menyiapkan rute dan fondasinya.
class LoginScreen extends ConsumerWidget {
  const LoginScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(startupProvider).value;
    final config = state is StartupReady ? state.config : null;
    final offline = state is StartupReady && state.fromCache;

    return Scaffold(
      appBar: AppBar(title: Text(config?.appName ?? 'Pakis Pro')),
      body: Column(
        children: [
          if (offline)
            MaterialBanner(
              content: const Text('Tidak ada koneksi. Memakai pengaturan terakhir yang tersimpan.'),
              leading: const Icon(Icons.cloud_off_outlined),
              actions: [
                TextButton(onPressed: () => ref.read(startupProvider.notifier).retry(), child: const Text('Coba Lagi')),
              ],
            ),
          const Expanded(
            child: EmptyState(
              icon: Icons.login,
              title: 'Layar masuk sedang disiapkan',
              message: 'Aplikasi sudah terhubung ke server dan versinya didukung.',
            ),
          ),
        ],
      ),
    );
  }
}
