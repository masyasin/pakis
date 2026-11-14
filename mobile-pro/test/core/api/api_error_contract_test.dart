import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/api_error.dart';

/// Kode galat klien = tabel 05 §2.3, dua arah (padanan `tests/Contract/ApiErrorFormatTest.php`).
/// Dokumen dibaca langsung dari repo, jadi kode baru di server yang lupa ditambah di HP → merah.
void main() {
  Set<String> documentedCodes() {
    final doc = File('../docs/05-API-CONTRACT.md').readAsStringSync();
    final start = doc.indexOf('### 2.3 Kode Galat');
    final end = doc.indexOf('### 2.4', start);
    expect(start, greaterThanOrEqualTo(0), reason: 'Bagian 05 §2.3 tidak ditemukan');
    return RegExp(
      r'^\| `([A-Z_]+)` \|',
      multiLine: true,
    ).allMatches(doc.substring(start, end)).map((m) => m.group(1)!).toSet();
  }

  final clientCodes = ApiErrorCode.values.where((c) => c.server).map((c) => c.wire).toSet();

  test('setiap kode di 05 §2.3 punya pesan Indonesia di klien', () {
    final missing = documentedCodes().difference(clientCodes);
    expect(missing, isEmpty, reason: 'Tambahkan ke ApiErrorCode: $missing');
  });

  test('klien tidak punya kode server yang tidak ada di 05 §2.3', () {
    final extra = clientCodes.difference(documentedCodes());
    expect(extra, isEmpty, reason: 'Kode tidak terdokumentasi: $extra');
  });

  test('kode khusus klien tidak bentrok dengan kode server', () {
    final clientOnly = ApiErrorCode.values.where((c) => !c.server).map((c) => c.wire).toSet();
    expect(clientOnly.intersection(documentedCodes()), isEmpty);
  });

  test('setiap kode punya pesan dan nilai wire unik', () {
    for (final code in ApiErrorCode.values) {
      expect(code.defaultMessage.trim(), isNotEmpty, reason: code.wire);
    }
    expect(ApiErrorCode.values.map((c) => c.wire).toSet().length, ApiErrorCode.values.length);
  });
}
