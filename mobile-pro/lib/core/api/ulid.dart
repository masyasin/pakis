import 'dart:math';

const _crockford = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

/// ULID untuk header `X-Request-Id` (05 §2.4).
///
/// Server hanya menerima ULID dari klien (`RequestId::fromClient`), nilai lain diganti ID
/// baru. Dengan ULID yang sah, ID yang sama muncul di log HP dan log server sehingga galat
/// pengguna bisa dilacak dari satu kode.
String newUlid({DateTime? now, Random? random}) {
  final rnd = random ?? Random.secure();
  var time = (now ?? DateTime.now()).millisecondsSinceEpoch;
  final chars = List<String>.filled(26, '0');

  // 48 bit waktu (ms) → 10 karakter, karakter pertama ≤ '7'.
  for (var i = 9; i >= 0; i--) {
    chars[i] = _crockford[time % 32];
    time ~/= 32;
  }
  // 80 bit acak → 16 karakter.
  for (var i = 10; i < 26; i++) {
    chars[i] = _crockford[rnd.nextInt(32)];
  }

  return chars.join();
}

final _ulidPattern = RegExp(r'^[0-7][0-9A-HJKMNP-TV-Z]{25}$');

bool isUlid(String value) => _ulidPattern.hasMatch(value);
