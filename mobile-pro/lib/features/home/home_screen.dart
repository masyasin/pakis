import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/theme/pakis_tokens.dart';
import '../../shared/widgets/empty_state.dart';
import '../auth/session.dart';

/// Beranda setelah masuk. Sementara (T-032): header nama · jabatan · lokasi dan tombol Keluar.
/// Kartu shift, tombol absen, dan tab bawah dibangun di T-042/T-043 (07 §5).
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  Future<void> _confirmLogout(BuildContext context, WidgetRef ref) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Keluar dari Pakis Pro?'),
        content: const Text('Anda perlu masuk lagi untuk absen. Absen yang belum terkirim tetap tersimpan di HP.'),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Keluar')),
        ],
      ),
    );
    if (ok == true) {
      await ref.read(sessionProvider.notifier).logout();
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final session = ref.watch(sessionProvider).value;
    final user = session is SignedIn ? session.user : null;

    // Ikon status bar putih di atas header hijau tua.
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light.copyWith(statusBarColor: Colors.transparent),
      child: Scaffold(
        backgroundColor: tokens.bg,
        body: SafeArea(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ColoredBox(
                color: tokens.primaryStrong,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 8, 20),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              user?.name ?? 'Pakis Pro',
                              style: text.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w700),
                            ),
                            if (user?.subtitle != null)
                              Text(
                                user!.subtitle!,
                                style: text.bodyMedium?.copyWith(color: Colors.white.withValues(alpha: 0.85)),
                              ),
                          ],
                        ),
                      ),
                      IconButton(
                        tooltip: 'Keluar',
                        icon: const Icon(Icons.logout, color: Colors.white),
                        onPressed: () => _confirmLogout(context, ref),
                      ),
                    ],
                  ),
                ),
              ),
              const Expanded(
                child: EmptyState(
                  icon: Icons.fingerprint,
                  title: 'Absen segera hadir',
                  message: 'Anda sudah masuk. Tombol absen tampil di sini setelah modul absensi aktif.',
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
