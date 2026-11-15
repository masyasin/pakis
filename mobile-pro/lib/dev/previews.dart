// Pratinjau komponen untuk Flutter Widget Preview (`fvm flutter widget-preview start`).
//
// Hanya untuk melihat tampilan saat pengembangan: berkas ini tidak di-import oleh main.dart,
// jadi tidak ikut ke APK. Pratinjau berjalan sebagai Flutter web di Chrome, sehingga kanal
// native (ANDROID_ID, buka URL) dan penyimpanan aman diganti versi palsu di memori.
// Alur nyata device_uid & tombol unduh tetap diuji di emulator/HP dan di test/.
import 'dart:async';

import 'package:flutter/widget_previews.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../core/api/api_error.dart';
import '../core/config/app_config.dart';
import '../core/device/native_device.dart';
import '../core/format.dart';
import '../core/listing/listing.dart';
import '../core/providers.dart';
import '../core/storage/secure_store.dart';
import '../core/theme/app_theme.dart';
import '../features/startup/splash_screen.dart';
import '../features/startup/startup_controller.dart';
import '../features/startup/update_required_screen.dart';
import '../shared/widgets/empty_state.dart';
import '../shared/widgets/filter_sheet.dart';
import '../shared/widgets/paged_list.dart';
import '../shared/widgets/status_badge.dart';

/// Ukuran layar HP acuan (07 §5, cek 360 dp).
const previewPhone = Size(360, 800);

// ---------------------------------------------------------------------------------------------
// Pembungkus tema (07 §1.1). Fungsi top-level agar bisa dipakai sebagai argumen const @Preview.

Widget lightApp(Widget child) => _app(child, Brightness.light, BrandPreview.defaultColor);

Widget darkApp(Widget child) => _app(child, Brightness.dark, BrandPreview.defaultColor);

/// Warna utama kustom dari config (`branding.primary_color`), contoh biru.
Widget customColorApp(Widget child) => _app(child, Brightness.light, BrandPreview.customColor);

Widget customColorDarkApp(Widget child) => _app(child, Brightness.dark, BrandPreview.customColor);

abstract final class BrandPreview {
  static const defaultColor = '#2F6B3B';
  static const customColor = '#1F4E79';
}

/// Format tanggal Indonesia butuh data locale dimuat dulu (biasanya di main()).
final Future<void> _formatReady = Fmt.init();

Widget _app(Widget child, Brightness brightness, String primary) => FutureBuilder<void>(
  future: _formatReady,
  builder: (context, snapshot) => MaterialApp(
    debugShowCheckedModeBanner: false,
    theme: AppTheme.build(brightness, primaryHex: primary),
    locale: const Locale('id', 'ID'),
    supportedLocales: const [Locale('id', 'ID')],
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    home: snapshot.connectionState == ConnectionState.done ? child : const SizedBox.shrink(),
  ),
);

// ---------------------------------------------------------------------------------------------
// Label status & keadaan kosong

@Preview(group: 'Komponen', name: 'StatusBadge — terang', wrapper: lightApp)
Widget statusBadgesLight() => const _StatusBadges();

@Preview(group: 'Komponen', name: 'StatusBadge — gelap', wrapper: darkApp)
Widget statusBadgesDark() => const _StatusBadges();

class _StatusBadges extends StatelessWidget {
  const _StatusBadges();

  @override
  Widget build(BuildContext context) => Scaffold(
    body: Padding(
      padding: const EdgeInsets.all(16),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final status in [...statusLabels.keys, 'status_baru']) StatusBadge(status: status),
        ],
      ),
    ),
  );
}

@Preview(group: 'Komponen', name: 'EmptyState — kosong', size: Size(360, 360), wrapper: lightApp)
Widget emptyStateEmpty() => Scaffold(
  body: EmptyState(
    title: 'Belum ada absen',
    message: 'Riwayat absen Anda akan tampil di sini.',
    actionLabel: 'Muat Ulang',
    onAction: () {},
  ),
);

@Preview(group: 'Komponen', name: 'EmptyState — galat', size: Size(360, 360), wrapper: darkApp)
Widget emptyStateError() => Scaffold(
  body: EmptyState(
    icon: Icons.cloud_off_outlined,
    title: 'Gagal memuat data',
    message: const ApiError(code: ApiErrorCode.networkOffline).message,
    actionLabel: 'Coba Lagi',
    onAction: () {},
  ),
);

// ---------------------------------------------------------------------------------------------
// Daftar standar mobile

final _demoSpec = ListingSpec.fromJson({
  'key': 'demo_attendance',
  'search_placeholder': 'Cari nama pegawai',
  'per_page_options': [20, 50],
  'default_sort': '-work_date',
  'sorts': [
    {'key': 'work_date', 'label': 'Tanggal'},
    {'key': 'name', 'label': 'Nama'},
  ],
  'filters': [
    {
      'key': 'status',
      'type': 'multi_select',
      'label': 'Status',
      'options': [
        {'value': 'present', 'label': 'Hadir'},
        {'value': 'late', 'label': 'Telat'},
        {'value': 'absent', 'label': 'Tidak hadir'},
      ],
    },
    {
      'key': 'work_date',
      'type': 'date_range',
      'label': 'Tanggal',
      'default': {'from': '2026-11-01', 'to': '2026-11-30'},
    },
    {'key': 'is_corrected', 'type': 'boolean', 'label': 'Dikoreksi'},
  ],
});

typedef _DemoRow = ({String name, String date, String status});

/// Server palsu: 45 baris dummy (bukan data pegawai asli), cari + filter status diterapkan.
Future<ListingPage<_DemoRow>> _demoFetch(ListingQuery query) async {
  await Future<void>.delayed(const Duration(milliseconds: 400));
  const statuses = ['present', 'late', 'present', 'absent', 'present', 'holiday'];
  var rows = <_DemoRow>[
    for (var i = 1; i <= 45; i++)
      (name: 'Pegawai Demo $i', date: '2026-11-${(30 - i % 30).toString().padLeft(2, '0')}', status: statuses[i % 6]),
  ];
  if (query.q.isNotEmpty) {
    rows = rows.where((r) => r.name.toLowerCase().contains(query.q.toLowerCase())).toList();
  }
  final status = query.filters['status'];
  if (status is List && status.isNotEmpty) {
    rows = rows.where((r) => status.contains(r.status)).toList();
  }
  final filters = <String, Object?>{
    if (!query.filters.containsKey('work_date')) 'work_date': _demoSpec.filter('work_date')!.defaultValue,
    for (final e in query.filters.entries)
      if (!isEmptyFilterValue(e.value)) e.key: e.value,
  };
  final start = (query.page - 1) * query.perPage;
  return ListingPage(
    items: rows.skip(start).take(query.perPage).toList(),
    page: query.page,
    perPage: query.perPage,
    total: rows.length,
    lastPage: rows.isEmpty ? 1 : (rows.length / query.perPage).ceil(),
    spec: _demoSpec,
    sort: query.sort ?? _demoSpec.defaultSort,
    filters: filters,
  );
}

class _DemoList extends StatelessWidget {
  const _DemoList();

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Riwayat Absen')),
    body: PagedList<_DemoRow>(
      fetch: _demoFetch,
      emptyTitle: 'Belum ada absen',
      itemBuilder: (context, row) => ListTile(
        title: Text(row.name),
        subtitle: Text(Fmt.isoDate(row.date)),
        trailing: StatusBadge(status: row.status),
      ),
    ),
  );
}

@Preview(group: 'Daftar', name: 'PagedList — terang', size: previewPhone, wrapper: lightApp)
Widget pagedListLight() => const _DemoList();

@Preview(group: 'Daftar', name: 'PagedList — gelap', size: previewPhone, wrapper: darkApp)
Widget pagedListDark() => const _DemoList();

@Preview(group: 'Daftar', name: 'FilterSheet', size: previewPhone, wrapper: lightApp)
Widget filterSheet() => Scaffold(
  body: FilterSheet(
    spec: _demoSpec,
    filters: const {
      'status': ['late'],
      'work_date': {'from': '2026-11-01', 'to': '2026-11-30'},
    },
    sort: '-work_date',
  ),
);

// ---------------------------------------------------------------------------------------------
// Startup: splash & wajib update (BR-027)

final _demoConfig = AppConfig.fromJson({
  'app_name': 'PAKIS ONE',
  'company_name': 'CV Pakis Jaya Abadi',
  'primary_color': BrandPreview.defaultColor,
  'mobile': {
    'min_version': '1.2.0',
    'latest_version': '1.2.0',
    'download_url': 'https://pakis.example/unduh/pakis-pro.apk',
    'update_message': 'Versi baru Pakis Pro tersedia.',
  },
});

class _PreviewNative implements NativeDevice {
  @override
  Future<String?> androidId() async => 'pratinjau';

  @override
  Future<String> sha256Hex(String input) async => '0' * 64;

  @override
  Future<bool> openUrl(String url) async => false; // tidak ada browser native di pratinjau

  @override
  Future<DeviceInfo> deviceInfo() async => const DeviceInfo(model: 'Pratinjau', osVersion: 'Android');

  @override
  Future<String> privateDir() async => '';
}

class _PreviewStartup extends StartupController {
  _PreviewStartup(this._result);

  final StartupState? _result;

  /// null = tetap memuat (spinner splash).
  @override
  Future<StartupState> build() => _result == null ? Completer<StartupState>().future : Future.value(_result);
}

Widget _scope(Widget child, {StartupState? startup}) => ProviderScope(
  overrides: [
    secureStoreProvider.overrideWithValue(MemorySecureStore()),
    appVersionProvider.overrideWithValue('1.0.0'),
    nativeDeviceProvider.overrideWithValue(_PreviewNative()),
    startupProvider.overrideWith(() => _PreviewStartup(startup)),
  ],
  child: child,
);

@Preview(group: 'Startup', name: 'Splash — memuat', size: previewPhone, wrapper: lightApp)
Widget splashLoading() => _scope(const SplashScreen());

@Preview(group: 'Startup', name: 'Splash — offline tanpa config', size: previewPhone, wrapper: lightApp)
Widget splashFailed() =>
    _scope(const SplashScreen(), startup: const StartupFailed(ApiError(code: ApiErrorCode.networkOffline)));

@Preview(group: 'Startup', name: 'Wajib update — terang', size: previewPhone, wrapper: lightApp)
Widget updateRequiredLight() => _scope(const UpdateRequiredScreen(), startup: StartupUpdateRequired(_demoConfig));

@Preview(group: 'Startup', name: 'Wajib update — gelap', size: previewPhone, wrapper: darkApp)
Widget updateRequiredDark() => _scope(const UpdateRequiredScreen(), startup: StartupUpdateRequired(_demoConfig));

@Preview(group: 'Startup', name: 'Wajib update — warna kustom', size: previewPhone, wrapper: customColorApp)
Widget updateRequiredCustom() => _scope(const UpdateRequiredScreen(), startup: StartupUpdateRequired(_demoConfig));

@Preview(group: 'Startup', name: 'Splash — warna kustom gelap', size: previewPhone, wrapper: customColorDarkApp)
Widget splashCustomDark() => _scope(const SplashScreen());
