import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../core/providers.dart';
import '../core/theme/app_theme.dart';
import '../core/theme/brand_color.dart';
import '../features/startup/startup_controller.dart';
import 'router.dart';

class PakisProApp extends ConsumerWidget {
  const PakisProApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Warna utama dari `/config` (`branding.primary_color`); selama splash memakai bawaan.
    final primary = ref.watch(currentConfigProvider)?.primaryColor ?? BrandColor.defaultPrimary;

    return MaterialApp.router(
      title: 'Pakis Pro',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.build(Brightness.light, primaryHex: primary),
      darkTheme: AppTheme.build(Brightness.dark, primaryHex: primary),
      themeMode: ref.watch(themeModeProvider),
      locale: const Locale('id', 'ID'),
      supportedLocales: const [Locale('id', 'ID')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      routerConfig: ref.watch(routerProvider),
    );
  }
}
