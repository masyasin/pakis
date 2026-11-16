import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/format.dart';

void main() {
  test('wallClock: jam shift & waktu server tanpa konversi zona (05 §5.4)', () {
    expect(Fmt.wallClock('07:30'), '07.30');
    expect(Fmt.wallClock('2026-11-03T07:37:02+07:00'), '07.37');
    expect(Fmt.wallClock(null), isNull);
    expect(Fmt.wallClock('kosong'), isNull);
  });

  test('isoWithOffset: client_time ISO-8601 dengan offset zona HP', () {
    final local = DateTime(2026, 11, 3, 7, 41, 12);
    final value = Fmt.isoWithOffset(local);

    expect(value, startsWith('2026-11-03T07:41:12'));
    expect(value, matches(RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$')));
    expect(DateTime.parse(value).toUtc(), local.toUtc(), reason: 'offset sesuai zona HP');
  });
}
