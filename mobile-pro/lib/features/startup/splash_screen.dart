import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/theme/pakis_tokens.dart';
import 'startup_controller.dart';

/// Splash: memuat `/config`, atau menampilkan galat + "Coba Lagi" bila belum pernah online.
class SplashScreen extends ConsumerWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final state = ref.watch(startupProvider).value;
    final failed = state is StartupFailed ? state : null;

    return Scaffold(
      backgroundColor: tokens.primaryStrong,
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.eco, size: 64, color: Colors.white),
                const SizedBox(height: 12),
                Text(
                  'Pakis Pro',
                  style: text.headlineMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 32),
                if (failed == null) ...[
                  const CircularProgressIndicator(color: Colors.white),
                  const SizedBox(height: 16),
                  Text('Memuat pengaturan…', style: text.bodyLarge?.copyWith(color: Colors.white)),
                ] else ...[
                  Text(
                    failed.error.message,
                    style: text.bodyLarge?.copyWith(color: Colors.white),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: tokens.primaryStrong),
                    onPressed: () => ref.read(startupProvider.notifier).retry(),
                    child: const Text('Coba Lagi'),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
