import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/theme/pakis_tokens.dart';
import '../approvals/approvals_screen.dart';
import '../attendance/home/attendance_home.dart';
import '../auth/mobile_user.dart';
import '../auth/session.dart';
import '../corrections/my_corrections_screen.dart';
import '../history/history_screen.dart';
import '../notifications/notifications_screen.dart';
import '../profile/profile_screen.dart';
import '../profile/user_avatar.dart';

/// Satu tab bawah (07 §5).
class HomeTab {
  const HomeTab(this.label, this.icon, this.activeIcon, this.builder);

  final String label;
  final IconData icon;
  final IconData activeIcon;
  final WidgetBuilder builder;
}

/// Tab menurut permission token (05 §5.3): Persetujuan hanya untuk pemegang
/// `attendance_corrections.approve`. Notifikasi = lonceng di header (layar penuh), agar tab
/// bawah tetap ≤ 5 di layar 360 dp.
List<HomeTab> homeTabsFor(MobileUser? user) => [
  if (user?.can('attendance.self') ?? false) ...[
    HomeTab('Presensi', Icons.fingerprint, Icons.fingerprint, (_) => const AttendanceHome()),
    HomeTab('Riwayat', Icons.history, Icons.history, (_) => const HistoryScreen()),
  ],
  if (user?.can('attendance_corrections.create') ?? false)
    HomeTab('Koreksi', Icons.edit_calendar_outlined, Icons.edit_calendar, (_) => const MyCorrectionsScreen()),
  if (user?.can('attendance_corrections.approve') ?? false)
    HomeTab('Persetujuan', Icons.fact_check_outlined, Icons.fact_check, (_) => const ApprovalsScreen()),
  HomeTab('Profil', Icons.person_outline, Icons.person, (_) => const ProfileScreen()),
];

/// Beranda setelah masuk: header nama · jabatan · lokasi + lonceng, isi tab, tab bawah.
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  String _current = 'Presensi';

  /// Tab dibangun saat pertama dibuka (daftar tidak memuat data sebelum dilihat), lalu
  /// dipertahankan agar posisi gulir & filter tidak hilang saat berpindah tab.
  final _visited = <String>{};

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final session = ref.watch(sessionProvider).value;
    final user = session is SignedIn ? session.user : null;
    final tabs = homeTabsFor(user);
    var index = tabs.indexWhere((t) => t.label == _current);
    if (index < 0) {
      index = 0;
    }
    _visited.add(tabs[index].label);

    // Ikon status bar putih di atas header hijau tua.
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light.copyWith(statusBarColor: Colors.transparent),
      child: Scaffold(
        backgroundColor: tokens.bg,
        body: SafeArea(
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ColoredBox(
                color: tokens.primaryStrong,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 8, 20),
                  child: Row(
                    children: [
                      if (user != null) ...[
                        UserAvatar(
                          name: user.name,
                          radius: 22,
                          background: Colors.white.withValues(alpha: 0.2),
                          foreground: Colors.white,
                        ),
                        const SizedBox(width: 12),
                      ],
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
                      const NotificationBell(),
                    ],
                  ),
                ),
              ),
              Expanded(
                child: IndexedStack(
                  index: index,
                  children: [
                    for (final tab in tabs)
                      _visited.contains(tab.label)
                          ? Padding(
                              padding: EdgeInsets.only(top: tab.label == 'Presensi' ? 0 : 12),
                              child: tab.builder(context),
                            )
                          : const SizedBox.shrink(),
                  ],
                ),
              ),
            ],
          ),
        ),
        // Saat keluar (sesi sudah null, router belum pindah) hanya tersisa Profil:
        // NavigationBar butuh ≥ 2 tujuan.
        bottomNavigationBar: tabs.length < 2
            ? null
            : NavigationBar(
                height: 64,
                selectedIndex: index,
                labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
                onDestinationSelected: (i) => setState(() => _current = tabs[i].label),
                destinations: [
                  for (final tab in tabs)
                    NavigationDestination(icon: Icon(tab.icon), selectedIcon: Icon(tab.activeIcon), label: tab.label),
                ],
              ),
      ),
    );
  }
}
