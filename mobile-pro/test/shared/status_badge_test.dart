import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/core/theme/app_theme.dart';
import 'package:pakis_pro/core/theme/pakis_tokens.dart';
import 'package:pakis_pro/shared/widgets/empty_state.dart';
import 'package:pakis_pro/shared/widgets/status_badge.dart';

void main() {
  test('label & nada sesuai 07 §6', () {
    expect(statusLabels['present'], ('Hadir', StatusTone.success));
    expect(statusLabels['late'], ('Telat', StatusTone.warning));
    expect(statusLabels['early_leave'], ('Pulang cepat', StatusTone.warning));
    expect(statusLabels['no_checkout'], ('Tidak absen pulang', StatusTone.warning));
    expect(statusLabels['absent'], ('Tidak hadir', StatusTone.danger));
    expect(statusLabels['holiday'], ('Libur', StatusTone.info));
    expect(statusLabels['off'], ('Tidak dijadwalkan', StatusTone.muted));
    expect(statusLabels['pending'], ('Menunggu', StatusTone.warning));
    expect(statusLabels['approved'], ('Disetujui', StatusTone.success));
    expect(statusLabels['rejected'], ('Ditolak', StatusTone.danger));
    expect(statusLabels['cancelled'], ('Dibatalkan', StatusTone.muted));
    expect(statusLabels['active'], ('Aktif', StatusTone.success));
    expect(statusLabels['revoked'], ('Dicabut', StatusTone.danger));
    expect(statusLabels['replaced'], ('Diganti', StatusTone.muted));
    expect(statusLabels['inactive'], ('Nonaktif', StatusTone.muted));
  });

  Future<void> pump(WidgetTester tester, Widget child, {Brightness brightness = Brightness.light}) => tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.build(brightness),
      home: Scaffold(body: child),
    ),
  );

  testWidgets('badge memakai warna status AA dari token', (tester) async {
    await pump(tester, const StatusBadge(status: 'late'), brightness: Brightness.dark);

    final text = tester.widget<Text>(find.text('Telat'));
    expect(text.style?.color, PakisTokens.dark.status[StatusTone.warning]);
  });

  testWidgets('status tak dikenal tetap tampil apa adanya', (tester) async {
    await pump(tester, const StatusBadge(status: 'status_baru'));

    expect(find.text('status_baru'), findsOneWidget);
  });

  testWidgets('EmptyState dengan aksi', (tester) async {
    var tapped = false;
    await pump(
      tester,
      EmptyState(title: 'Kosong', message: 'Belum ada data.', actionLabel: 'Muat Ulang', onAction: () => tapped = true),
    );

    await tester.tap(find.text('Muat Ulang'));
    expect(tapped, isTrue);
  });
}
