import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/core/theme/app_theme.dart';
import 'package:pakis_pro/core/theme/brand_color.dart';
import 'package:pakis_pro/core/theme/pakis_tokens.dart';

void main() {
  test('warna bawaan → token desain 07 §1.1 apa adanya (sama dengan web)', () {
    expect(BrandColor.palette('#2F6B3B'), isNull);
    expect(PakisTokens.resolve(Brightness.light, '#2f6b3b').primary, const Color(0xFF2F6B3B));
    expect(PakisTokens.resolve(Brightness.dark, '#2F6B3B').primary, const Color(0xFF79B98B));
    expect(PakisTokens.resolve(Brightness.dark, '#2F6B3B').primaryStrong, const Color(0xFF2C5A37));
  });

  // Nilai acuan dari backend: `BrandTheme::palette($hex)` (PHP), dijalankan 15 Nov 2026.
  const backend = {
    '#1F4E79': ['#183C5D', '#3B87CD', '#1B4469'],
    '#5B2C83': ['#482368', '#A06BCD', '#502774'],
    '#8B1E1E': ['#6E1818', '#DA5454', '#7A1A1A'],
    '#7A4B00': ['#563500', '#B77100', '#663E00'],
  };

  for (final MapEntry(key: hex, value: expected) in backend.entries) {
    test('warna kustom $hex identik dengan web (BrandTheme.php)', () {
      final p = BrandColor.palette(hex)!;

      expect(p.lightPrimary, BrandColor.toColor(hex));
      expect(p.lightPrimaryStrong, BrandColor.toColor(expected[0]));
      expect(p.darkPrimary, BrandColor.toColor(expected[1]));
      expect(p.darkPrimaryStrong, BrandColor.toColor(expected[2]));
    });
  }

  test('kontras AA (BR-021): putih di atas primer terang, teks gelap di atas primer gelap', () {
    for (final hex in backend.keys) {
      expect(BrandColor.contrast(hex, '#FFFFFF'), greaterThanOrEqualTo(4.5));
      final dark = BrandColor.darkPrimary(hex);
      expect(BrandColor.contrast(dark, '#121814'), greaterThanOrEqualTo(4.5));
      expect(BrandColor.contrast(dark, '#0E1A11'), greaterThanOrEqualTo(4.5));
    }
  });

  test('warna tidak sah / kontras kurang → token bawaan', () {
    for (final bad in ['#FFFF00', 'red', '#12345', '', '#000;}']) {
      expect(BrandColor.palette(bad), isNull, reason: bad);
      expect(PakisTokens.resolve(Brightness.light, bad).primary, PakisTokens.light.primary);
    }
  });

  test('ThemeData memakai token: AppBar = primary-strong, latar = bg, radius 12', () {
    final theme = AppTheme.build(Brightness.light, primaryHex: '#1F4E79');

    expect(theme.colorScheme.primary, const Color(0xFF1F4E79));
    expect(theme.appBarTheme.backgroundColor, const Color(0xFF183C5D));
    expect(theme.scaffoldBackgroundColor, PakisTokens.light.bg);
    expect(theme.extension<PakisTokens>(), isNotNull);
  });

  test('warna status AA terhadap latarnya (terang & gelap)', () {
    String hex(Color c) => '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0').toUpperCase()}';
    for (final tokens in [PakisTokens.light, PakisTokens.dark]) {
      for (final tone in StatusTone.values) {
        expect(
          BrandColor.contrast(hex(tokens.status[tone]!), hex(tokens.statusBg[tone]!)),
          greaterThanOrEqualTo(4.5),
          reason: tone.name,
        );
      }
    }
  });
}
