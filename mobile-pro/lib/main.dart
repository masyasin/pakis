import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'app/app.dart';
import 'core/format.dart';
import 'core/providers.dart';
import 'core/storage/secure_store.dart';
import 'core/theme/theme_mode_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final store = FlutterSecureStore();
  // Tema dibaca sebelum runApp agar frame pertama sudah benar (07 §1.1).
  final (info, themeMode, _) = await (PackageInfo.fromPlatform(), ThemeModeStore(store).read(), Fmt.init()).wait;

  runApp(
    ProviderScope(
      overrides: [
        secureStoreProvider.overrideWithValue(store),
        appVersionProvider.overrideWithValue(info.version),
        initialThemeModeProvider.overrideWithValue(themeMode),
      ],
      child: const PakisProApp(),
    ),
  );
}
