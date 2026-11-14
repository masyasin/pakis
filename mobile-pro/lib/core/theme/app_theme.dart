import 'package:material_ui/material_ui.dart';

import 'pakis_tokens.dart';

/// Tema Material dari token 07 §1.1: radius 12, spacing kelipatan 4, target sentuh ≥ 48 dp
/// (07 §5), font sistem (tanpa unduhan font).
abstract final class AppTheme {
  static const radius = 12.0;

  static ThemeData build(Brightness brightness, {String primaryHex = '#2F6B3B'}) {
    final t = PakisTokens.resolve(brightness, primaryHex);
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(radius));
    const minTouch = Size(64, 48);

    final scheme = ColorScheme(
      brightness: brightness,
      primary: t.primary,
      onPrimary: t.onPrimary,
      secondary: t.accent,
      onSecondary: t.onAccent,
      error: t.danger,
      onError: brightness == Brightness.dark ? const Color(0xFF1A0806) : Colors.white,
      surface: t.surface,
      onSurface: t.text,
      onSurfaceVariant: t.muted,
      outline: t.border,
      outlineVariant: t.border,
      surfaceContainerLowest: t.surface,
      surfaceContainerLow: t.surface,
      surfaceContainer: t.surface,
      surfaceContainerHigh: t.surface,
      surfaceContainerHighest: t.bg,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: t.bg,
      extensions: [t],
      materialTapTargetSize: MaterialTapTargetSize.padded,
      appBarTheme: AppBarTheme(
        backgroundColor: t.primaryStrong,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        color: t.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: shape.copyWith(side: BorderSide(color: t.border)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(minimumSize: minTouch, shape: shape),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: minTouch,
          shape: shape,
          side: BorderSide(color: t.border),
        ),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(minimumSize: minTouch)),
      inputDecorationTheme: InputDecorationThemeData(
        filled: true,
        fillColor: t.surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radius),
          borderSide: BorderSide(color: t.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radius),
          borderSide: BorderSide(color: t.border),
        ),
      ),
      chipTheme: ChipThemeData(
        shape: shape,
        side: BorderSide(color: t.border),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: t.surface,
        showDragHandle: true,
        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(radius * 2))),
      ),
      dividerTheme: DividerThemeData(color: t.border),
    );
  }
}
