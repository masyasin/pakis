import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/ulid.dart';

void main() {
  test('ULID sah: 26 karakter Crockford, diterima RequestId::fromClient', () {
    for (var i = 0; i < 200; i++) {
      final id = newUlid();
      expect(id, hasLength(26));
      expect(isUlid(id), isTrue, reason: id);
    }
  });

  test('10 karakter pertama = waktu (ms), sehingga urut menurut waktu', () {
    final a = newUlid(now: DateTime.utc(2026, 11, 15, 1), random: Random(1));
    final b = newUlid(now: DateTime.utc(2026, 11, 15, 2), random: Random(1));

    expect(a.substring(0, 10).compareTo(b.substring(0, 10)), lessThan(0));
    // Epoch 0 → awalan nol semua.
    expect(newUlid(now: DateTime.fromMillisecondsSinceEpoch(0, isUtc: true)).substring(0, 10), '0000000000');
  });

  test('menolak bukan-ULID', () {
    expect(isUlid('01JBX8Q6V7M3T2K9R4N5P6W8Y'), isFalse); // 25
    expect(isUlid('81JBX8Q6V7M3T2K9R4N5P6W8YZ'), isFalse); // melewati 48 bit
    expect(isUlid('01JBX8Q6V7M3T2K9R4N5P6W8YI'), isFalse); // I bukan Crockford
  });
}
