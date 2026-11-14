import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/providers.dart';
import '../../core/theme/pakis_tokens.dart';
import 'startup_controller.dart';

/// Layar wajib update (BR-027, MOB-08). Tidak ada tombol kembali, lewati, atau tutup:
/// tombol Back sistem diblokir dan router selalu mengarahkan kembali ke sini.
class UpdateRequiredScreen extends ConsumerWidget {
  const UpdateRequiredScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final config = ref.watch(currentConfigProvider);
    final gate = ref.watch(updateGateProvider);
    final version = ref.watch(appVersionProvider);

    // URL dari 426 (`details.download_url`) didahulukan karena paling baru.
    final gateUrl = gate?.details['download_url'];
    final url = gateUrl is String && gateUrl.isNotEmpty ? gateUrl : config?.mobile.downloadUrl;
    final minVersion = config?.mobile.minVersion;

    return PopScope(
      canPop: false,
      child: Scaffold(
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Icon(Icons.system_update, size: 72, color: tokens.primary),
                const SizedBox(height: 16),
                Text('Perbarui aplikasi', style: text.headlineSmall, textAlign: TextAlign.center),
                const SizedBox(height: 12),
                Text(
                  config?.mobile.updateMessage ??
                      'Versi Pakis Pro ini sudah tidak didukung. Pasang versi terbaru untuk melanjutkan.',
                  style: text.bodyLarge,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                Text(
                  minVersion == null ? 'Versi terpasang $version' : 'Versi terpasang $version · minimal $minVersion',
                  style: text.bodyMedium?.copyWith(color: tokens.muted),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 32),
                FilledButton.icon(
                  icon: const Icon(Icons.download),
                  label: const Text('Unduh Versi Terbaru'),
                  onPressed: url == null
                      ? null
                      : () async {
                          final opened = await ref.read(nativeDeviceProvider).openUrl(url);
                          if (!opened && context.mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(content: Text('Tautan unduhan tidak bisa dibuka. Hubungi admin.')),
                            );
                          }
                        },
                ),
                if (url == null) ...[
                  const SizedBox(height: 12),
                  Text(
                    'Tautan unduhan belum diatur. Hubungi admin untuk mendapatkan versi terbaru.',
                    style: text.bodyMedium?.copyWith(color: tokens.muted),
                    textAlign: TextAlign.center,
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
