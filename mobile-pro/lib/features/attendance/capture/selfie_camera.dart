import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:material_ui/material_ui.dart';

enum CameraProblemKind { noCamera, denied, failed }

class CameraProblem implements Exception {
  const CameraProblem(this.kind);

  final CameraProblemKind kind;

  String get message => switch (kind) {
    CameraProblemKind.noCamera => 'Kamera depan tidak ditemukan di HP ini.',
    CameraProblemKind.denied =>
      'Pakis Pro perlu izin kamera untuk foto presensi. Buka Pengaturan → Aplikasi → Pakis Pro → Izin → Kamera.',
    CameraProblemKind.failed => 'Kamera tidak bisa dibuka. Tutup aplikasi kamera lain lalu coba lagi.',
  };
}

/// Kamera selfie di balik antarmuka, agar layar bisa diuji tanpa kamera fisik.
///
/// SEC-022: foto **hanya** dari kamera langsung. Tidak ada image picker / galeri di aplikasi
/// ini; jangan menambahkannya.
abstract interface class SelfieCamera {
  Future<void> open();
  Widget preview();
  Future<Uint8List> capture();
  Future<void> close();
}

/// Kamera perangkat. Bawaan kamera depan (selfie presensi); lampiran koreksi memakai kamera
/// belakang (`lens: CameraLensDirection.back`) untuk memotret dokumen.
class DeviceSelfieCamera implements SelfieCamera {
  DeviceSelfieCamera({this.lens = CameraLensDirection.front});

  final CameraLensDirection lens;
  CameraController? _controller;

  @override
  Future<void> open() async {
    final List<CameraDescription> cameras;
    try {
      cameras = await availableCameras();
    } on CameraException catch (e) {
      throw _problem(e);
    }
    final front = cameras.where((c) => c.lensDirection == lens);
    if (front.isEmpty) {
      throw const CameraProblem(CameraProblemKind.noCamera);
    }
    // 720p cukup untuk hasil akhir 720 px; resolusi lebih tinggi hanya memperlambat HP
    // RAM 2–3 GB (MOB-10).
    final controller = CameraController(
      front.first,
      ResolutionPreset.high,
      enableAudio: false,
      imageFormatGroup: ImageFormatGroup.jpeg,
    );
    try {
      await controller.initialize();
    } on CameraException catch (e) {
      await controller.dispose();
      throw _problem(e);
    }
    _controller = controller;
  }

  @override
  Widget preview() {
    final controller = _controller;
    return controller == null ? const SizedBox.shrink() : CameraPreview(controller);
  }

  @override
  Future<Uint8List> capture() async {
    final controller = _controller;
    if (controller == null) {
      throw const CameraProblem(CameraProblemKind.failed);
    }
    try {
      final file = await controller.takePicture();
      return await file.readAsBytes();
    } on CameraException catch (e) {
      throw _problem(e);
    }
  }

  @override
  Future<void> close() async {
    final controller = _controller;
    _controller = null;
    await controller?.dispose();
  }

  static CameraProblem _problem(CameraException e) =>
      CameraProblem(e.code.startsWith('CameraAccessDenied') ? CameraProblemKind.denied : CameraProblemKind.failed);
}
