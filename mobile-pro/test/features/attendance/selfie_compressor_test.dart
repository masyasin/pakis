import 'dart:math';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/features/attendance/capture/selfie_compressor.dart';

/// Encoder palsu yang meniru flutter_image_compress: skala max(1, min(w/min, h/min)),
/// ukuran berkas ≈ piksel × faktor kualitas.
class FakeEncoder implements JpegEncoder {
  FakeEncoder(this.width, this.height, {this.bytesPerPixelAt100 = 0.6});

  final int width;
  final int height;
  final double bytesPerPixelAt100;
  final calls = <({int minSide, int quality, int outW, int outH})>[];

  @override
  Future<({int width, int height})> size(Uint8List bytes) async => (width: width, height: height);

  @override
  Future<Uint8List> encode(Uint8List bytes, {required int minSide, required int quality}) async {
    final scale = max(1.0, min(width / minSide, height / minSide));
    final w = (width / scale).round();
    final h = (height / scale).round();
    calls.add((minSide: minSide, quality: quality, outW: w, outH: h));
    return Uint8List((w * h * bytesPerPixelAt100 * quality / 100).round());
  }
}

void main() {
  final photo = Uint8List(10);

  test('kriteria T-042: sisi terpanjang 720 px, ≤ 100 KB (foto potret kamera depan)', () async {
    final encoder = FakeEncoder(1080, 1920);
    final out = await SelfieCompressor(encoder).compress(photo);

    final last = encoder.calls.last;
    expect(max(last.outW, last.outH), 720);
    expect(last.outW, 405);
    expect(out.lengthInBytes, lessThanOrEqualTo(SelfieLimits.maxBytes));
    expect(encoder.calls.first.quality, 85, reason: 'kualitas terbaik dicoba lebih dulu');
  });

  test('foto lanskap juga sisi terpanjang 720 px', () async {
    final encoder = FakeEncoder(1920, 1080);
    await SelfieCompressor(encoder).compress(photo);
    final last = encoder.calls.last;
    expect((last.outW, last.outH), (720, 405));
  });

  test('foto kecil tidak diperbesar', () async {
    final encoder = FakeEncoder(480, 640);
    await SelfieCompressor(encoder).compress(photo);
    expect((encoder.calls.last.outW, encoder.calls.last.outH), (480, 640));
  });

  test('foto "berat" (banyak detail): kualitas diturunkan, lalu dimensi, sampai ≤ 100 KB', () async {
    final encoder = FakeEncoder(1080, 1920, bytesPerPixelAt100: 2.5);
    final out = await SelfieCompressor(encoder).compress(photo);

    expect(out.lengthInBytes, lessThanOrEqualTo(SelfieLimits.maxBytes));
    expect(encoder.calls.map((c) => c.quality), contains(45));
    expect(max(encoder.calls.last.outW, encoder.calls.last.outH), lessThan(720));
  });
}
