import 'dart:async';
import 'dart:typed_data';

import 'package:material_ui/material_ui.dart';

import 'selfie_camera.dart';
import 'selfie_compressor.dart';

/// Langkah foto pada alur absen (07 §5): kamera depan dengan bingkai wajah, tanpa galeri.
/// Kembali (`Navigator.pop`) dengan JPEG ≤ 100 KB, atau null bila dibatalkan.
class SelfieCaptureScreen extends StatefulWidget {
  const SelfieCaptureScreen({required this.camera, this.compressor = const SelfieCompressor(), super.key});

  final SelfieCamera camera;
  final SelfieCompressor compressor;

  static Future<Uint8List?> open(BuildContext context, {SelfieCamera? camera}) => Navigator.of(context).push(
    MaterialPageRoute<Uint8List>(
      fullscreenDialog: true,
      builder: (_) => SelfieCaptureScreen(camera: camera ?? DeviceSelfieCamera()),
    ),
  );

  @override
  State<SelfieCaptureScreen> createState() => _SelfieCaptureScreenState();
}

class _SelfieCaptureScreenState extends State<SelfieCaptureScreen> with WidgetsBindingObserver {
  bool _ready = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_open());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(widget.camera.close());
    super.dispose();
  }

  /// Kamera dilepas saat aplikasi ke latar belakang (aplikasi lain bisa memakainya) dan
  /// dibuka lagi saat kembali.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.inactive && _ready) {
      setState(() => _ready = false);
      unawaited(widget.camera.close());
    } else if (state == AppLifecycleState.resumed && !_ready && _error == null) {
      unawaited(_open());
    }
  }

  Future<void> _open() async {
    setState(() => _error = null);
    try {
      await widget.camera.open();
      if (mounted) {
        setState(() => _ready = true);
      }
    } on CameraProblem catch (e) {
      if (mounted) {
        setState(() => _error = e.message);
      }
    }
  }

  Future<void> _capture() async {
    setState(() => _busy = true);
    try {
      final photo = await widget.camera.capture();
      final selfie = await widget.compressor.compress(photo);
      if (mounted) {
        Navigator.of(context).pop(selfie);
      }
    } on CameraProblem catch (e) {
      if (mounted) {
        setState(() => _error = e.message);
      }
    } on StateError {
      if (mounted) {
        setState(() => _error = 'Foto gagal diproses. Coba ambil ulang.');
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: const Text('Foto selfie'),
        leading: IconButton(
          tooltip: 'Batal',
          icon: const Icon(Icons.close),
          onPressed: () => Navigator.of(context).pop(),
        ),
      ),
      body: SafeArea(
        child: _error != null
            ? Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.no_photography_outlined, color: Colors.white, size: 56),
                      const SizedBox(height: 16),
                      Text(
                        _error!,
                        style: text.bodyLarge?.copyWith(color: Colors.white),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 16),
                      FilledButton(onPressed: _open, child: const Text('Coba Lagi')),
                    ],
                  ),
                ),
              )
            : Column(
                children: [
                  Expanded(
                    child: Stack(
                      fit: StackFit.expand,
                      children: [
                        if (_ready) Center(child: widget.camera.preview()),
                        if (!_ready) const Center(child: CircularProgressIndicator(color: Colors.white)),
                        const IgnorePointer(child: CustomPaint(painter: _FaceFramePainter())),
                      ],
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      'Posisikan wajah di dalam bingkai, lalu ketuk tombol.',
                      style: text.bodyLarge?.copyWith(color: Colors.white),
                      textAlign: TextAlign.center,
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.only(bottom: 24),
                    child: Semantics(
                      button: true,
                      label: 'Ambil foto',
                      child: SizedBox.square(
                        dimension: 76,
                        child: FilledButton(
                          style: FilledButton.styleFrom(
                            shape: const CircleBorder(side: BorderSide(color: Colors.white, width: 4)),
                            padding: EdgeInsets.zero,
                          ),
                          onPressed: _ready && !_busy ? _capture : null,
                          child: _busy
                              ? const SizedBox.square(
                                  dimension: 28,
                                  child: CircularProgressIndicator(strokeWidth: 3, color: Colors.white),
                                )
                              : const Icon(Icons.photo_camera, size: 32),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

/// Bingkai wajah oval dengan latar luar digelapkan.
class _FaceFramePainter extends CustomPainter {
  const _FaceFramePainter();

  @override
  void paint(Canvas canvas, Size size) {
    final oval = Rect.fromCenter(
      center: size.center(Offset.zero),
      width: size.width * 0.68,
      height: size.width * 0.68 * 1.3,
    );
    final shade = Path()
      ..addRect(Offset.zero & size)
      ..addOval(oval)
      ..fillType = PathFillType.evenOdd;
    canvas
      ..drawPath(shade, Paint()..color = Colors.black.withValues(alpha: 0.45))
      ..drawOval(
        oval,
        Paint()
          ..color = Colors.white
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3,
      );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
