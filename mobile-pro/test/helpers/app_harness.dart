import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/app/app.dart';
import 'package:pakis_pro/core/format.dart';
import 'package:pakis_pro/core/providers.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';
import 'package:pakis_pro/features/auth/session.dart';

import 'fakes.dart';

/// Menjalankan aplikasi utuh dengan server, penyimpanan, dan native palsu.
Future<ProviderContainer> pumpPakisPro(
  WidgetTester tester, {
  required FakeAdapter adapter,
  SecureStore? store,
  FakeNativeDevice? native,
  FakeSessionHooks? hooks,
  String version = '1.0.0',
  List<Override> overrides = const [],
}) async {
  await Fmt.init();
  tester.view
    ..physicalSize = const Size(1080, 2400)
    ..devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  final container = ProviderContainer(
    overrides: [
      secureStoreProvider.overrideWithValue(store ?? MemorySecureStore()),
      appVersionProvider.overrideWithValue(version),
      nativeDeviceProvider.overrideWithValue(native ?? FakeNativeDevice()),
      httpAdapterProvider.overrideWithValue(adapter),
      apiBaseUrlProvider.overrideWithValue('https://pakis.test'),
      sessionHooksProvider.overrideWithValue(hooks ?? FakeSessionHooks()),
      ...overrides,
    ],
  );
  addTearDown(container.dispose);

  await tester.pumpWidget(UncontrolledProviderScope(container: container, child: const PakisProApp()));
  await tester.pumpAndSettle();
  return container;
}
