import 'package:material_ui/material_ui.dart';

import 'brand_color.dart';

/// Nada warna label status (07 §6).
enum StatusTone { success, warning, danger, info, muted }

/// Token desain 07 §1.1 (terang/gelap) + warna label status AA yang sama dengan web
/// (`backend/resources/css/app.css` `--status-*`). Diakses lewat `context.tokens`.
@immutable
class PakisTokens extends ThemeExtension<PakisTokens> {
  const PakisTokens({
    required this.primary,
    required this.onPrimary,
    required this.primaryStrong,
    required this.accent,
    required this.onAccent,
    required this.bg,
    required this.surface,
    required this.border,
    required this.text,
    required this.muted,
    required this.danger,
    required this.info,
    required this.success,
    required this.status,
    required this.statusBg,
  });

  static const light = PakisTokens(
    primary: Color(0xFF2F6B3B),
    onPrimary: Color(0xFFFFFFFF),
    primaryStrong: Color(0xFF24552F),
    accent: Color(0xFFA9661A),
    onAccent: Color(0xFFFFFFFF),
    bg: Color(0xFFF3F6F2),
    surface: Color(0xFFFFFFFF),
    border: Color(0xFFD8E2DA),
    text: Color(0xFF16241B),
    muted: Color(0xFF56675B),
    danger: Color(0xFFB3261E),
    info: Color(0xFF24609B),
    success: Color(0xFF2E7D4F),
    status: {
      StatusTone.success: Color(0xFF22603C),
      StatusTone.warning: Color(0xFF7A4A10),
      StatusTone.danger: Color(0xFF9A1F18),
      StatusTone.info: Color(0xFF1D4E80),
      StatusTone.muted: Color(0xFF4A5A4F),
    },
    statusBg: {
      StatusTone.success: Color(0xFFE3F1E8),
      StatusTone.warning: Color(0xFFFBEFDC),
      StatusTone.danger: Color(0xFFFBE4E2),
      StatusTone.info: Color(0xFFE2EEF8),
      StatusTone.muted: Color(0xFFECF0ED),
    },
  );

  static const dark = PakisTokens(
    primary: Color(0xFF79B98B),
    onPrimary: Color(0xFF0E1A11),
    primaryStrong: Color(0xFF2C5A37),
    accent: Color(0xFFE0AC63),
    onAccent: Color(0xFF1A1206),
    bg: Color(0xFF121814),
    surface: Color(0xFF1A221D),
    border: Color(0xFF2F3C33),
    text: Color(0xFFE5EDE7),
    muted: Color(0xFFA3B3A8),
    danger: Color(0xFFF08A80),
    info: Color(0xFF85B7E8),
    success: Color(0xFF6FC28D),
    status: {
      StatusTone.success: Color(0xFF8FD3A6),
      StatusTone.warning: Color(0xFFEBC183),
      StatusTone.danger: Color(0xFFF6A39B),
      StatusTone.info: Color(0xFFA3C8EE),
      StatusTone.muted: Color(0xFFB5C3B9),
    },
    statusBg: {
      StatusTone.success: Color(0xFF17301F),
      StatusTone.warning: Color(0xFF33260F),
      StatusTone.danger: Color(0xFF3A1714),
      StatusTone.info: Color(0xFF13283D),
      StatusTone.muted: Color(0xFF252E28),
    },
  );

  final Color primary;
  final Color onPrimary;

  /// Header (latar AppBar).
  final Color primaryStrong;
  final Color accent;
  final Color onAccent;
  final Color bg;
  final Color surface;
  final Color border;
  final Color text;
  final Color muted;
  final Color danger;
  final Color info;
  final Color success;
  final Map<StatusTone, Color> status;
  final Map<StatusTone, Color> statusBg;

  /// Token untuk satu mode, dengan warna primer dari config bila kustom.
  static PakisTokens resolve(Brightness brightness, String primaryHex) {
    final base = brightness == Brightness.dark ? dark : light;
    final palette = BrandColor.palette(primaryHex);
    if (palette == null) {
      return base;
    }
    return brightness == Brightness.dark
        ? base.copyWith(primary: palette.darkPrimary, primaryStrong: palette.darkPrimaryStrong)
        : base.copyWith(primary: palette.lightPrimary, primaryStrong: palette.lightPrimaryStrong);
  }

  @override
  PakisTokens copyWith({Color? primary, Color? primaryStrong}) => PakisTokens(
    primary: primary ?? this.primary,
    onPrimary: onPrimary,
    primaryStrong: primaryStrong ?? this.primaryStrong,
    accent: accent,
    onAccent: onAccent,
    bg: bg,
    surface: surface,
    border: border,
    text: text,
    muted: muted,
    danger: danger,
    info: info,
    success: success,
    status: status,
    statusBg: statusBg,
  );

  /// Token tidak dianimasikan antar tema (ColorScheme sudah dianimasikan oleh MaterialApp).
  @override
  PakisTokens lerp(PakisTokens? other, double t) => other != null && t >= 0.5 ? other : this;
}

extension PakisTokensContext on BuildContext {
  PakisTokens get tokens => Theme.of(this).extension<PakisTokens>() ?? PakisTokens.light;
}
