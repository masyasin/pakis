import 'package:material_ui/material_ui.dart';

import '../storage/secure_store.dart';

/// Pilihan tema yang diingat (07 §1.1). Dibaca di `main()` sebelum `runApp` agar frame
/// pertama sudah memakai tema yang benar (tanpa kedip terang → gelap).
class ThemeModeStore {
  const ThemeModeStore(this._store);

  final SecureStore _store;

  Future<ThemeMode> read() async => switch (await _store.read(StoreKeys.themeMode)) {
    'light' => ThemeMode.light,
    'dark' => ThemeMode.dark,
    _ => ThemeMode.system,
  };

  Future<void> write(ThemeMode mode) => _store.write(StoreKeys.themeMode, mode.name);
}
