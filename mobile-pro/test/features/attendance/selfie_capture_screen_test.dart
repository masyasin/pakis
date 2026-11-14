import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/core/theme/app_theme.dart';
import 'package:pakis_pro/features/attendance/capture/selfie_camera.dart';
import 'package:pakis_pro/features/attendance/capture/selfie_capture_screen.dart';
import 'package:pakis_pro/features/attendance/capture/selfie_compressor.dart';

import 'selfie_compressor_test.dart' show FakeEncoder;

class FakeCamera implements SelfieCamera {
  CameraProblem? openError;
  var opened = 0;
  var closed = 0;

  @override
  Future<void> open() async {
    opened++;
    if (openError != null) {
      throw openError!;
    }
  }

  @override
  Widget preview() => const ColoredBox(color: Colors.grey, child: SizedBox.expand());

  @override
  Future<Uint8List> capture() async => Uint8List(4);

  @override
  Future<void> close() async => closed++;
}

void main() {
  Future<Uint8List?> run(WidgetTester tester, FakeCamera camera) async {
    Uint8List? result;
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.build(Brightness.light),
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () async => result = await Navigator.of(context).push(
                MaterialPageRoute<Uint8List>(
                  builder: (_) =>
                      SelfieCaptureScreen(camera: camera, compressor: SelfieCompressor(FakeEncoder(1080, 1920))),
                ),
              ),
              child: const Text('mulai'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('mulai'));
    await tester.pumpAndSettle();
    return result;
  }

  testWidgets('ambil foto → kembali dengan selfie terkompresi, kamera ditutup', (tester) async {
    final camera = FakeCamera();
    await run(tester, camera);

    expect(find.text('Posisikan wajah di dalam bingkai, lalu ketuk tombol.'), findsOneWidget);
    await tester.tap(find.bySemanticsLabel('Ambil foto'));
    await tester.pumpAndSettle();

    expect(find.byType(SelfieCaptureScreen), findsNothing);
    expect(camera.closed, greaterThanOrEqualTo(1));
  });

  testWidgets('izin kamera ditolak → arahan + Coba Lagi', (tester) async {
    final camera = FakeCamera()..openError = const CameraProblem(CameraProblemKind.denied);
    await run(tester, camera);

    expect(find.textContaining('perlu izin kamera'), findsOneWidget);
    camera.openError = null;
    await tester.tap(find.text('Coba Lagi'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Posisikan wajah'), findsOneWidget);
    expect(camera.opened, 2);
  });

  testWidgets('tidak ada tombol galeri / pilih berkas (SEC-022)', (tester) async {
    await run(tester, FakeCamera());

    expect(find.byIcon(Icons.photo_library), findsNothing);
    expect(find.textContaining('Galeri'), findsNothing);
  });
}
