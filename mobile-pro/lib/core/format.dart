import 'package:intl/date_symbol_data_local.dart';
import 'package:intl/intl.dart';

/// Format Indonesia (07 §7): `3 Nov 2026`, `Selasa, 3 November 2026`, `07.41`, `1.250`.
abstract final class Fmt {
  static const locale = 'id_ID';

  /// Wajib dipanggil sekali sebelum memformat tanggal (di `main()` dan di test).
  static Future<void> init() async {
    await initializeDateFormatting(locale);
    Intl.defaultLocale = locale;
  }

  static String date(DateTime value) => DateFormat('d MMM y', locale).format(value);

  static String longDate(DateTime value) => DateFormat('EEEE, d MMMM y', locale).format(value);

  static String time(DateTime value) => DateFormat('HH.mm', locale).format(value);

  static String number(num value) => NumberFormat.decimalPattern(locale).format(value);

  /// `2026-11-03` → `3 Nov 2026`. Tanggal lokal murni (tanpa zona), nilai lain dikembalikan apa adanya.
  static String isoDate(String value) {
    final parsed = parseIsoDate(value);
    return parsed == null ? value : date(parsed);
  }

  static DateTime? parseIsoDate(String value) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(value);
    if (match == null) {
      return null;
    }
    return DateTime(int.parse(match[1]!), int.parse(match[2]!), int.parse(match[3]!));
  }

  static String toIsoDate(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';
}
