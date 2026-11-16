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

  /// Jam dinding `HH.mm` dari string server tanpa mengubah zona: `07:30` (jam shift) atau
  /// `2026-11-03T07:37:02+07:00` (sudah dalam `general.timezone`, 05 §5.4). Null bila bentuk lain.
  static String? wallClock(String? value) {
    if (value == null) {
      return null;
    }
    final match = RegExp(r'(?:T|^)(\d{2}):(\d{2})').firstMatch(value);
    return match == null ? null : '${match[1]}.${match[2]}';
  }

  /// ISO-8601 dengan offset zona HP, mis. `2026-11-03T07:41:12+07:00` (05 §5.4 `client_time`).
  static String isoWithOffset(DateTime value) {
    String two(int n) => n.toString().padLeft(2, '0');
    final offset = value.timeZoneOffset;
    final sign = offset.isNegative ? '-' : '+';
    final minutes = offset.inMinutes.abs();
    return '${toIsoDate(value)}T${two(value.hour)}:${two(value.minute)}:${two(value.second)}'
        '$sign${two(minutes ~/ 60)}:${two(minutes % 60)}';
  }

  static String toIsoDate(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';
}
