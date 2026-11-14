import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter_image_compress/flutter_image_compress.dart';

/// Batas foto selfie (kriteria T-042): ≤ 100 KB, sisi terpanjang 720 px. Server menerima
/// hingga 300 KB (SEC-022); batas HP lebih ketat demi sinyal lemah di lapangan (MOB-10).
abstract final class SelfieLimits {
  static const maxBytes = 100 * 1024;
  static const longestSide = 720;
}

/// Plugin native di balik antarmuka agar algoritma bisa diuji di host.
abstract interface class JpegEncoder {
  Future<({int width, int height})> size(Uint8List bytes);

  /// Skala `max(1, min(w/minSide, h/minSide))` (perilaku flutter_image_compress), JPEG,
  /// orientasi EXIF dikoreksi, metadata dibuang.
  Future<Uint8List> encode(Uint8List bytes, {required int minSide, required int quality});
}

class NativeJpegEncoder implements JpegEncoder {
  const NativeJpegEncoder();

  @override
  Future<({int width, int height})> size(Uint8List bytes) async {
    final buffer = await ui.ImmutableBuffer.fromUint8List(bytes);
    final descriptor = await ui.ImageDescriptor.encoded(buffer);
    final result = (width: descriptor.width, height: descriptor.height);
    descriptor.dispose();
    buffer.dispose();
    return result;
  }

  @override
  Future<Uint8List> encode(Uint8List bytes, {required int minSide, required int quality}) =>
      FlutterImageCompress.compressWithList(
        bytes,
        minWidth: minSide,
        minHeight: minSide,
        quality: quality,
        format: CompressFormat.jpeg,
        keepExif: false, // tanpa GPS/model HP di EXIF (server juga membuangnya, SEC-022)
      );
}

/// Mengecilkan foto kamera menjadi selfie absen.
class SelfieCompressor {
  const SelfieCompressor([this._encoder = const NativeJpegEncoder()]);

  final JpegEncoder _encoder;

  static const _qualities = [85, 75, 65, 55, 45];

  Future<Uint8List> compress(Uint8List photo) async {
    final source = await _encoder.size(photo);
    final shorter = source.width < source.height ? source.width : source.height;
    final longer = source.width < source.height ? source.height : source.width;

    // Plugin mengecilkan sampai sisi PENDEK = minSide. Agar sisi PANJANG = 720 px:
    // minSide = 720 × pendek / panjang.
    var longest = SelfieLimits.longestSide;
    Uint8List? last;
    while (longest >= 240) {
      final minSide = (longest * shorter / longer).round().clamp(1, longest);
      for (final quality in _qualities) {
        last = await _encoder.encode(photo, minSide: minSide, quality: quality);
        if (last.lengthInBytes <= SelfieLimits.maxBytes) {
          return last;
        }
      }
      // Masih terlalu besar pada kualitas terendah (jarang): perkecil dimensi.
      longest = (longest * 0.8).round();
    }
    throw StateError('Foto tidak bisa dikecilkan sampai ${SelfieLimits.maxBytes} byte (${last?.lengthInBytes})');
  }
}
