import 'dart:math' as math;

import 'package:material_ui/material_ui.dart';

/// Warna primer turunan dari `branding.primary_color` (BR-021, 07 §1.1).
class BrandPalette {
  const BrandPalette({
    required this.lightPrimary,
    required this.lightPrimaryStrong,
    required this.darkPrimary,
    required this.darkPrimaryStrong,
  });

  final Color lightPrimary;
  final Color lightPrimaryStrong;
  final Color darkPrimary;
  final Color darkPrimaryStrong;
}

/// Port `ContrastRatio` + bagian warna primer `BrandTheme` dari backend, dengan rumus dan
/// pembulatan yang sama, sehingga warna kustom di HP identik dengan web
/// (diuji terhadap keluaran PHP di `test/core/theme/brand_color_test.dart`).
abstract final class BrandColor {
  static const minContrast = 4.5;
  static const defaultPrimary = '#2F6B3B';
  static const _darkBackground = '#121814';

  /// `--primary-foreground` mode gelap: teks gelap di atas tombol primer.
  static const _darkPrimaryForeground = '#0E1A11';

  static final _hex = RegExp(r'^#[0-9A-F]{6}$');

  /// Palet untuk warna kustom, atau null bila warna = bawaan (pakai token desain apa adanya,
  /// seperti web), tidak sah, atau tidak lolos kontras. Null → tema memakai token 07 §1.1.
  static BrandPalette? palette(String hex) {
    final value = hex.trim().toUpperCase();
    if (value == defaultPrimary || !_hex.hasMatch(value) || !isAcceptable(value)) {
      return null;
    }

    return BrandPalette(
      lightPrimary: toColor(value),
      lightPrimaryStrong: toColor(_shift(value, -0.07)),
      darkPrimary: toColor(darkPrimary(value)),
      darkPrimaryStrong: toColor(_shift(value, -0.04)),
    );
  }

  /// BR-021: ≥ 4.5:1 terhadap putih dan varian gelapnya ≥ 4.5:1 terhadap latar gelap.
  static bool isAcceptable(String hex) => contrast(hex, '#FFFFFF') >= minContrast && darkVariant(hex) != null;

  static double contrast(String foreground, String background) {
    final a = _luminance(foreground);
    final b = _luminance(background);
    return (math.max(a, b) + 0.05) / (math.min(a, b) + 0.05);
  }

  /// `ContrastRatio::darkVariant`: lightness dinaikkan 0.02 per langkah sampai ≥ 4.5:1.
  static String? darkVariant(String hex) {
    final [h, s, l] = toHsl(hex);
    for (var lightness = l; lightness <= 0.95; lightness += 0.02) {
      final candidate = fromHsl(h, s, lightness);
      if (contrast(candidate, _darkBackground) >= minContrast) {
        return candidate;
      }
    }
    return null;
  }

  /// `BrandTheme::darkPrimary`: dinaikkan lagi bila teks gelap di atas tombol < 4.5:1.
  static String darkPrimary(String hex) {
    var dark = darkVariant(hex) ?? hex;
    while (contrast(dark, _darkPrimaryForeground) < minContrast && toHsl(dark)[2] < 0.95) {
      dark = _shift(dark, 0.01);
    }
    return dark;
  }

  static Color toColor(String hex) => Color(0xFF000000 | int.parse(hex.substring(1), radix: 16));

  static List<double> toHsl(String hex) {
    final [r, g, b] = _rgb(hex).map((c) => c / 255).toList();
    final maxV = math.max(r, math.max(g, b));
    final minV = math.min(r, math.min(g, b));
    final l = (maxV + minV) / 2;

    if (maxV == minV) {
      return [0, 0, l];
    }

    final d = maxV - minV;
    final s = l > 0.5 ? d / (2 - maxV - minV) : d / (maxV + minV);
    final double h;
    if (maxV == r) {
      h = (g - b) / d + (g < b ? 6 : 0);
    } else if (maxV == g) {
      h = (b - r) / d + 2;
    } else {
      h = (r - g) / d + 4;
    }
    return [h / 6, s, l];
  }

  static String fromHsl(double h, double s, double l) {
    double r;
    double g;
    double b;
    if (s == 0) {
      r = g = b = l;
    } else {
      final q = l < 0.5 ? l * (1 + s) : l + s - l * s;
      final p = 2 * l - q;
      r = _hue(p, q, h + 1 / 3);
      g = _hue(p, q, h);
      b = _hue(p, q, h - 1 / 3);
    }
    String channel(double v) => _phpRound(v * 255).toRadixString(16).padLeft(2, '0').toUpperCase();
    return '#${channel(r)}${channel(g)}${channel(b)}';
  }

  /// Geser lightness HSL, dijepit 0.04–0.96 (sama dengan backend).
  static String _shift(String hex, double delta) {
    final [h, s, l] = toHsl(hex);
    return fromHsl(h, s, (l + delta).clamp(0.04, 0.96));
  }

  static double _hue(double p, double q, double t) {
    t += t < 0 ? 1 : (t > 1 ? -1 : 0);
    if (t < 1 / 6) {
      return p + (q - p) * 6 * t;
    }
    if (t < 1 / 2) {
      return q;
    }
    if (t < 2 / 3) {
      return p + (q - p) * (2 / 3 - t) * 6;
    }
    return p;
  }

  static double _luminance(String hex) {
    final channels = _rgb(hex).map((c) {
      final v = c / 255;
      return v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
    }).toList();
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
  }

  static List<int> _rgb(String hex) {
    final v = hex.substring(1);
    return [for (var i = 0; i < 6; i += 2) int.parse(v.substring(i, i + 2), radix: 16)];
  }

  /// `round()` PHP: setengah menjauhi nol. `double.round()` Dart sama untuk nilai positif.
  static int _phpRound(double v) => v.round();
}
