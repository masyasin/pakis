import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../../core/format.dart';
import '../../../core/offline/sync_service.dart';
import '../../../core/providers.dart';
import '../../../core/theme/pakis_tokens.dart';
import '../../../shared/widgets/status_badge.dart';
import '../../startup/startup_controller.dart';
import '../attendance_models.dart';
import '../attendance_providers.dart';
import '../attendance_recorder.dart';
import '../result/attendance_submit_screen.dart';

/// Isi tab Presensi di beranda (07 §5): kartu shift hari ini, status, tombol bulat besar
/// "Masuk"/"Pulang", indikator antrean offline, GPS, dan acuan waktu.
class AttendanceHome extends ConsumerStatefulWidget {
  const AttendanceHome({super.key});

  @override
  ConsumerState<AttendanceHome> createState() => _AttendanceHomeState();
}

class _AttendanceHomeState extends ConsumerState<AttendanceHome> {
  bool _starting = false;
  SyncService? _sync;
  int _lastPending = 0;

  @override
  void dispose() {
    _sync?.pending.removeListener(_onPendingChanged);
    super.dispose();
  }

  /// Antrean berkurang = presensi tertunda baru diterima server: muat ulang status hari ini.
  void _onPendingChanged() {
    final now = _sync?.pending.value ?? 0;
    if (now < _lastPending) {
      unawaited(ref.read(attendanceHomeProvider.notifier).refresh());
    }
    _lastPending = now;
  }

  void _bindSync(SyncService? sync) {
    if (identical(sync, _sync)) {
      return;
    }
    _sync?.pending.removeListener(_onPendingChanged);
    _sync = sync;
    _lastPending = sync?.pending.value ?? 0;
    sync?.pending.addListener(_onPendingChanged);
  }

  Future<void> _start(String type) async {
    if (_starting) {
      return;
    }
    setState(() => _starting = true);
    try {
      final requireSelfie = ref.read(currentConfigProvider)?.attendance.requireSelfie ?? true;
      final selfie = requireSelfie ? await ref.read(selfieCaptureProvider)(context) : null;
      if (requireSelfie && selfie == null) {
        return; // dibatalkan di kamera
      }
      if (!mounted) {
        return;
      }
      await AttendanceSubmitScreen.open(context, type: type, selfie: selfie);
      if (mounted && ref.read(attendanceHomeProvider).value?.lastOutcome is RecordRejected?) {
        // Ditolak / dibatalkan: status server mungkin berbeda dengan dugaan HP.
        unawaited(ref.read(attendanceHomeProvider.notifier).refresh());
      }
    } finally {
      if (mounted) {
        setState(() => _starting = false);
      }
    }
  }

  Future<void> _refresh() async {
    final sync = _sync;
    if (sync != null && sync.pending.value > 0) {
      await sync.sync(force: true);
    }
    await ref.read(attendanceHomeProvider.notifier).refresh();
  }

  @override
  Widget build(BuildContext context) {
    final home = ref.watch(attendanceHomeProvider);
    final sync = ref.watch(optionalSyncServiceProvider).value;
    _bindSync(sync);

    final data = home.value;
    if (data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    final today = data.today;
    // Tanpa data server sama sekali (pertama kali dibuka tanpa sinyal) presensi tetap boleh:
    // server yang menilai. Tebakan awal = Masuk.
    final nextAction = today == null ? 'in' : today.nextAction;

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        children: [
          if (data.isStale) _StaleBanner(data: data),
          _ShiftCard(today: today),
          const SizedBox(height: 24),
          Center(
            child: _AttendanceButton(
              type: nextAction,
              busy: _starting,
              onPressed: nextAction == null ? null : () => _start(nextAction),
            ),
          ),
          const SizedBox(height: 8),
          _StatusLine(today: today, nextAction: nextAction),
          const SizedBox(height: 24),
          if (sync != null) _QueueCard(sync: sync),
          _LocationCard(outcome: data.lastOutcome, record: today?.attendance),
          const _ClockCard(),
        ],
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: tokens.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: tokens.border),
        ),
        child: Padding(padding: const EdgeInsets.all(16), child: child),
      ),
    );
  }
}

class _StaleBanner extends StatelessWidget {
  const _StaleBanner({required this.data});

  final AttendanceHomeData data;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final fetched = data.fetchedAt;
    final text = fetched == null
        ? 'Tidak terhubung ke server. Presensi tetap bisa disimpan di HP dan dikirim otomatis nanti.'
        : 'Tidak terhubung ke server. Data jadwal dari pukul ${Fmt.time(fetched)}. '
              'Presensi tetap bisa disimpan di HP.';
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: DecoratedBox(
        decoration: BoxDecoration(color: tokens.statusBg[StatusTone.info], borderRadius: BorderRadius.circular(12)),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Icon(Icons.cloud_off_outlined, color: tokens.status[StatusTone.info]),
              const SizedBox(width: 12),
              Expanded(
                child: Text(text, style: TextStyle(color: tokens.status[StatusTone.info])),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ShiftCard extends StatelessWidget {
  const _ShiftCard({required this.today});

  final AttendanceToday? today;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final today = this.today;
    final date = today == null ? null : Fmt.parseIsoDate(today.workDate);
    final attendance = today?.attendance;
    final shift = today?.shift ?? attendance?.shift;

    final String title;
    if (today == null || !today.isKnown) {
      title = 'Jadwal belum termuat';
    } else if (shift != null) {
      title =
          'Shift ${shift.name} · ${Fmt.wallClock(shift.start) ?? shift.start}–${Fmt.wallClock(shift.end) ?? shift.end}';
    } else if (today.scheduleKind == 'holiday') {
      title = today.holiday == null ? 'Hari libur' : 'Libur: ${today.holiday}';
    } else {
      title = 'Tidak ada jadwal kerja hari ini';
    }

    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  date == null ? 'HARI INI' : Fmt.longDate(date).toUpperCase(),
                  style: text.labelMedium?.copyWith(color: tokens.muted, letterSpacing: 0.6),
                ),
              ),
              if (attendance != null && attendance.status.isNotEmpty) StatusBadge(status: attendance.status),
            ],
          ),
          const SizedBox(height: 8),
          Text(title, style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _Clock(label: 'Masuk', value: Fmt.wallClock(attendance?.checkInAt)),
              ),
              Icon(Icons.arrow_forward, color: tokens.muted, size: 18),
              Expanded(
                child: _Clock(label: 'Pulang', value: Fmt.wallClock(attendance?.checkOutAt)),
              ),
            ],
          ),
          if (attendance?.locationName != null) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Icon(Icons.place_outlined, size: 18, color: tokens.muted),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    [
                      attendance!.locationName!,
                      if (attendance.distanceM != null) '${Fmt.number(attendance.distanceM!)} m',
                    ].join(' · '),
                    style: text.bodyMedium?.copyWith(color: tokens.muted),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _Clock extends StatelessWidget {
  const _Clock({required this.label, required this.value});

  final String label;
  final String? value;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Column(
      children: [
        Text(label, style: text.labelMedium?.copyWith(color: context.tokens.muted)),
        Text(value ?? '—', style: text.headlineSmall?.copyWith(fontWeight: FontWeight.w700)),
      ],
    );
  }
}

/// Tombol bulat besar ≥ 48 dp (07 §5) dengan cincin lembut, ikon sidik jari.
class _AttendanceButton extends StatelessWidget {
  const _AttendanceButton({required this.type, required this.busy, required this.onPressed});

  final String? type;
  final bool busy;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final label = switch (type) {
      'in' => 'Masuk',
      'out' => 'Pulang',
      _ => 'Selesai',
    };
    final enabled = onPressed != null && !busy;
    final color = type == 'out' ? tokens.accent : tokens.primary;
    final onColor = type == 'out' ? tokens.onAccent : tokens.onPrimary;

    return Semantics(
      button: true,
      enabled: enabled,
      label: type == null ? 'Presensi hari ini tidak tersedia' : 'Presensi $label',
      excludeSemantics: true,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: (enabled ? color : tokens.muted).withValues(alpha: 0.16),
        ),
        child: SizedBox.square(
          dimension: 168,
          child: FilledButton(
            key: const ValueKey('attendance-button'),
            style: FilledButton.styleFrom(
              shape: const CircleBorder(),
              padding: EdgeInsets.zero,
              backgroundColor: color,
              foregroundColor: onColor,
            ),
            onPressed: enabled ? onPressed : null,
            child: busy
                ? SizedBox.square(dimension: 40, child: CircularProgressIndicator(color: onColor))
                : Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(type == null ? Icons.check : Icons.fingerprint, size: 64),
                      const SizedBox(height: 4),
                      Text(
                        label,
                        style: Theme.of(context).textTheme.titleLarge
                            ?.copyWith(color: enabled ? onColor : null, fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
          ),
        ),
      ),
    );
  }
}

class _StatusLine extends StatelessWidget {
  const _StatusLine({required this.today, required this.nextAction});

  final AttendanceToday? today;
  final String? nextAction;

  @override
  Widget build(BuildContext context) {
    final attendance = today?.attendance;
    final checkIn = Fmt.wallClock(attendance?.checkInAt);
    final checkOut = Fmt.wallClock(attendance?.checkOutAt);
    final String line;
    if (checkOut != null) {
      line = 'Presensi hari ini selesai. Pulang pukul $checkOut.';
    } else if (checkIn != null) {
      line = nextAction == 'out'
          ? 'Sudah masuk pukul $checkIn. Ketuk Pulang saat selesai kerja.'
          : 'Sudah masuk pukul $checkIn.';
    } else if (nextAction == null) {
      line = today?.scheduleKind == 'shift'
          ? 'Jam presensi untuk shift ini sudah lewat.'
          : 'Tidak ada presensi yang perlu dilakukan sekarang.';
    } else {
      line = 'Belum presensi. Ketuk Masuk saat tiba di lokasi kerja.';
    }
    return Text(
      line,
      textAlign: TextAlign.center,
      style: Theme.of(context).textTheme.bodyLarge?.copyWith(color: context.tokens.muted),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({required this.icon, required this.title, required this.message, this.tone, this.action});

  final IconData icon;
  final String title;
  final String message;
  final StatusTone? tone;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final t = tone ?? StatusTone.muted;
    return _Card(
      child: Row(
        children: [
          DecoratedBox(
            decoration: BoxDecoration(color: tokens.statusBg[t], borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: Icon(icon, color: tokens.status[t]),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                Text(message, style: text.bodyMedium?.copyWith(color: tokens.muted)),
              ],
            ),
          ),
          ?action,
        ],
      ),
    );
  }
}

/// "2 presensi menunggu sinyal" (07 §5) + kirim sekarang, dan presensi yang ditolak server.
class _QueueCard extends StatefulWidget {
  const _QueueCard({required this.sync});

  final SyncService sync;

  @override
  State<_QueueCard> createState() => _QueueCardState();
}

class _QueueCardState extends State<_QueueCard> {
  bool _syncing = false;

  Future<void> _syncNow() async {
    setState(() => _syncing = true);
    try {
      final result = await widget.sync.sync(force: true);
      if (!mounted) {
        return;
      }
      final message =
          result.stoppedBy?.message ??
          (result.remaining > 0
              ? 'Server belum terjangkau. Presensi dikirim otomatis saat sinyal kembali.'
              : 'Semua presensi tertunda sudah terkirim.');
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) {
        setState(() => _syncing = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: Listenable.merge([widget.sync.pending, widget.sync.rejected]),
    builder: (context, _) {
      final pending = widget.sync.pending.value;
      final rejected = widget.sync.rejected.value;
      return Column(
        children: [
          if (pending > 0)
            _InfoRow(
              icon: Icons.cloud_upload_outlined,
              tone: StatusTone.warning,
              title: '${Fmt.number(pending)} presensi menunggu sinyal',
              message: 'Tersimpan aman di HP dan dikirim otomatis.',
              action: TextButton(
                onPressed: _syncing ? null : _syncNow,
                child: _syncing
                    ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Kirim'),
              ),
            )
          else
            const _InfoRow(
              icon: Icons.cloud_done_outlined,
              tone: StatusTone.success,
              title: 'Semua presensi terkirim',
              message: 'Tidak ada presensi yang tertunda di HP.',
            ),
          if (rejected > 0)
            _InfoRow(
              icon: Icons.report_outlined,
              tone: StatusTone.danger,
              title: '${Fmt.number(rejected)} presensi tertunda ditolak server',
              message: 'Ajukan koreksi presensi untuk tanggal itu.',
            ),
        ],
      );
    },
  );
}

class _LocationCard extends ConsumerWidget {
  const _LocationCard({required this.outcome, required this.record});

  final RecordOutcome? outcome;
  final AttendanceRecord? record;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final maxAccuracy = ref.watch(currentConfigProvider)?.attendance.maxAccuracyM ?? 100;
    final accuracy = outcome?.accuracyM;
    final distance = record?.distanceM;
    final parts = [
      if (accuracy != null) 'Akurasi terakhir ${Fmt.number(accuracy.round())} m',
      if (distance != null) '${Fmt.number(distance)} m dari ${record?.locationName ?? 'lokasi kerja'}',
    ];
    return _InfoRow(
      icon: Icons.gps_fixed,
      tone: StatusTone.info,
      title: 'Lokasi (GPS)',
      message: parts.isEmpty
          ? 'Diambil hanya saat Anda menekan tombol. Batas akurasi ${Fmt.number(maxAccuracy)} m.'
          : '${parts.join(' · ')}. Batas akurasi ${Fmt.number(maxAccuracy)} m.',
    );
  }
}

/// Acuan waktu = jam server (ADR-010). Jam HP yang meleset tidak mengubah jam presensi.
class _ClockCard extends ConsumerWidget {
  const _ClockCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final offset = ref.watch(clockOffsetProvider);
    final String message;
    if (offset == null) {
      message = 'Jam presensi memakai jam server, bukan jam HP.';
    } else if (offset.abs() < 60) {
      message = 'Jam HP sesuai jam server.';
    } else {
      final minutes = Fmt.number((offset.abs() / 60).round());
      message =
          'Jam HP ${offset > 0 ? 'terlambat' : 'lebih cepat'} $minutes menit dari server. '
          'Presensi tetap memakai jam server.';
    }
    return _InfoRow(
      icon: Icons.schedule,
      tone: offset != null && offset.abs() >= 60 ? StatusTone.warning : StatusTone.muted,
      title: 'Acuan waktu',
      message: message,
    );
  }
}
