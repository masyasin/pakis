import 'dart:async';
import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../../core/api/api_error.dart';
import '../../../core/format.dart';
import '../../../core/location/location_service.dart';
import '../../../core/offline/offline_providers.dart';
import '../../../core/theme/pakis_tokens.dart';
import '../../../shared/widgets/status_badge.dart';
import '../../startup/startup_controller.dart';
import '../attendance_providers.dart';
import '../attendance_recorder.dart';

/// Langkah setelah foto pada alur presensi (07 §5): cek GPS (spinner ≤ 15 s) → kirim → hasil.
///
/// Hasil diterapkan ke beranda ([AttendanceHomeController.apply]) begitu diketahui, jadi tombol
/// beranda sudah berganti walau pengguna menutup layar ini dengan tombol kembali HP.
class AttendanceSubmitScreen extends ConsumerStatefulWidget {
  const AttendanceSubmitScreen({required this.type, this.selfie, super.key});

  /// `in` / `out`.
  final String type;
  final Uint8List? selfie;

  static Future<void> open(BuildContext context, {required String type, Uint8List? selfie}) => Navigator.of(context)
      .push(
        MaterialPageRoute<void>(
          builder: (_) => AttendanceSubmitScreen(type: type, selfie: selfie),
        ),
      );

  @override
  ConsumerState<AttendanceSubmitScreen> createState() => _AttendanceSubmitScreenState();
}

enum _Step { locating, sending, done }

class _AttendanceSubmitScreenState extends ConsumerState<AttendanceSubmitScreen> {
  _Step _step = _Step.locating;
  LocationProblem? _problem;
  RecordOutcome? _outcome;

  String get _label => widget.type == 'in' ? 'Masuk' : 'Pulang';

  @override
  void initState() {
    super.initState();
    unawaited(_run());
  }

  Future<void> _run() async {
    setState(() {
      _step = _Step.locating;
      _problem = null;
      _outcome = null;
    });

    final LocationFix fix;
    try {
      // Batas akurasi dari /config (BR-064), sama dengan yang dipakai server.
      final maxAccuracy = ref.read(currentConfigProvider)?.attendance.maxAccuracyM ?? 100;
      fix = await ref.read(locationServiceProvider).acquire(maxAccuracyM: maxAccuracy);
    } on LocationProblem catch (e) {
      if (mounted) {
        setState(() {
          _problem = e;
          _step = _Step.done;
        });
      }
      return;
    }

    if (!mounted) {
      return;
    }
    setState(() => _step = _Step.sending);
    final recorder = await ref.read(attendanceRecorderProvider.future);
    final outcome = await recorder.record(type: widget.type, fix: fix, selfie: widget.selfie);
    if (outcome is! RecordRejected) {
      ref.read(attendanceHomeProvider.notifier).apply(outcome);
    }
    if (mounted) {
      setState(() {
        _outcome = outcome;
        _step = _Step.done;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final busy = _step != _Step.done;
    return PopScope(
      // Jangan tinggalkan layar saat kiriman berjalan: hasilnya belum diketahui.
      canPop: !busy,
      child: Scaffold(
        appBar: AppBar(title: Text('Presensi $_label'), automaticallyImplyLeading: !busy),
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: switch (_step) {
              _Step.locating => const _Busy(
                title: 'Mengambil lokasi…',
                message: 'Tunggu sebentar, paling lama 15 detik. Tetap di tempat terbuka.',
              ),
              _Step.sending => const _Busy(title: 'Mengirim presensi…'),
              _Step.done => _problem != null ? _problemView(_problem!) : _outcomeView(_outcome!),
            },
          ),
        ),
      ),
    );
  }

  Widget _problemView(LocationProblem problem) => _ResultView(
    icon: Icons.location_off_outlined,
    tone: StatusTone.warning,
    title: 'Lokasi belum didapat',
    message: problem.message,
    actions: [
      FilledButton(onPressed: _run, child: const Text('Coba Lagi')),
      if (problem.opensSettings)
        OutlinedButton(
          onPressed: () => ref.read(locationServiceProvider).openSettings(problem),
          child: const Text('Buka Pengaturan'),
        ),
      TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
    ],
  );

  Widget _outcomeView(RecordOutcome outcome) {
    final done = FilledButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Selesai'));
    switch (outcome) {
      case RecordSent(:final record, :final replayed):
        final time = Fmt.wallClock(widget.type == 'in' ? record?.checkInAt : record?.checkOutAt);
        final late = record != null && widget.type == 'in' && record.lateMinutes > 0;
        return _ResultView(
          icon: Icons.check_circle_outline,
          tone: late ? StatusTone.warning : StatusTone.success,
          title: 'Presensi ${_label.toLowerCase()} tercatat${time == null ? '' : ' pukul $time'}',
          badge: record?.status.isNotEmpty == true ? record!.status : null,
          message: [
            if (late) 'Telat ${Fmt.number(record.lateMinutes)} menit.',
            if (record?.locationName != null)
              '${record!.locationName}${record.distanceM == null ? '' : ' · ${Fmt.number(record.distanceM!)} m'}',
            if (replayed) 'Presensi ini sudah tercatat sebelumnya.',
            if (record == null) 'Terkirim bersama presensi yang tertunda di HP.',
          ].join('\n'),
          actions: [done],
        );
      case RecordQueued():
        return _ResultView(
          icon: Icons.cloud_off_outlined,
          tone: StatusTone.info,
          title: 'Tersimpan di HP, akan dikirim otomatis',
          message:
              'Sinyal ke server belum ada. Presensi dikirim sendiri saat HP kembali online, dengan jam saat '
              'Anda menekan tombol. Jangan hapus data aplikasi Pakis Pro.',
          actions: [done],
        );
      case RecordRejected(:final error):
        final retry = _retryable.contains(error.code);
        return _ResultView(
          icon: Icons.error_outline,
          tone: StatusTone.danger,
          title: 'Presensi ${_label.toLowerCase()} belum tercatat',
          message: [error.message, if (!retry && _correctable.contains(error.code)) _correctionHint].join('\n\n'),
          actions: [
            if (retry) FilledButton(onPressed: _run, child: const Text('Coba Lagi')),
            if (retry)
              TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Kembali'))
            else
              FilledButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Kembali')),
          ],
        );
    }
  }

  /// Bisa berhasil bila diulang dari tempat lain / sinyal lebih baik (foto yang sama dipakai).
  static const _retryable = {
    ApiErrorCode.attendanceOutOfRadius,
    ApiErrorCode.attendanceLowAccuracy,
    ApiErrorCode.rateLimited,
    ApiErrorCode.payloadTooLarge,
  };

  /// Presensi tidak bisa direkam ulang: jalan keluarnya koreksi presensi (BR-069).
  static const _correctable = {
    ApiErrorCode.attendanceOutsideWindow,
    ApiErrorCode.attendanceNotCheckedIn,
    ApiErrorCode.attendanceNoSchedule,
    ApiErrorCode.attendanceOfflineTooOld,
  };

  static const _correctionHint = 'Bila jam kerja Anda sebenarnya berbeda, ajukan koreksi presensi untuk tanggal itu.';
}

class _Busy extends StatelessWidget {
  const _Busy({required this.title, this.message});

  final String title;
  final String? message;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox.square(dimension: 48, child: CircularProgressIndicator()),
          const SizedBox(height: 24),
          Text(title, style: text.titleMedium, textAlign: TextAlign.center),
          if (message != null) ...[
            const SizedBox(height: 8),
            Text(
              message!,
              style: text.bodyMedium?.copyWith(color: context.tokens.muted),
              textAlign: TextAlign.center,
            ),
          ],
        ],
      ),
    );
  }
}

class _ResultView extends StatelessWidget {
  const _ResultView({
    required this.icon,
    required this.tone,
    required this.title,
    required this.message,
    required this.actions,
    this.badge,
  });

  final IconData icon;
  final StatusTone tone;
  final String title;
  final String message;
  final String? badge;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    return Center(
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: DecoratedBox(
                decoration: BoxDecoration(color: tokens.statusBg[tone], shape: BoxShape.circle),
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Icon(icon, size: 56, color: tokens.status[tone]),
                ),
              ),
            ),
            const SizedBox(height: 24),
            Text(
              title,
              style: text.titleLarge?.copyWith(fontWeight: FontWeight.w700),
              textAlign: TextAlign.center,
            ),
            if (badge != null) ...[const SizedBox(height: 12), Center(child: StatusBadge(status: badge!))],
            if (message.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(
                message,
                style: text.bodyLarge?.copyWith(color: tokens.muted),
                textAlign: TextAlign.center,
              ),
            ],
            const SizedBox(height: 32),
            for (final action in actions) ...[SizedBox(height: 48, child: action), const SizedBox(height: 8)],
          ],
        ),
      ),
    );
  }
}
