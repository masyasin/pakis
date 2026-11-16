import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/providers.dart';
import '../../core/theme/pakis_tokens.dart';
import '../attendance/capture/selfie_capture_screen.dart';
import '../startup/startup_controller.dart';
import 'correction.dart';
import 'my_corrections_screen.dart';

/// Memotret lampiran koreksi (kamera belakang, tanpa galeri). Diganti di test.
typedef AttachmentCapture = Future<Uint8List?> Function(BuildContext context, int maxBytes);

final attachmentCaptureProvider = Provider<AttachmentCapture>(
  (ref) =>
      (context, maxBytes) => SelfieCaptureScreen.openDocument(context, maxBytes: maxBytes),
);

/// Batas foto lampiran di HP: sesuai config, tetapi ≤ 500 KB agar terkirim di sinyal lemah.
int attachmentMaxBytes(int configKb) => (configKb < 500 ? configKb : 500) * 1024;

/// Form "Ajukan koreksi" (07 §5, 05 §5.7): tanggal maks `correction_max_days` ke belakang,
/// jenis, jam, alasan, catatan, lampiran foto. Kembali dengan koreksi yang dibuat.
class CorrectionFormScreen extends ConsumerStatefulWidget {
  const CorrectionFormScreen({this.initialDate, super.key});

  final DateTime? initialDate;

  static Future<Correction?> open(BuildContext context, {DateTime? initialDate}) =>
      Navigator.of(context)
          .push(MaterialPageRoute<Correction>(builder: (_) => CorrectionFormScreen(initialDate: initialDate)));

  @override
  ConsumerState<CorrectionFormScreen> createState() => _CorrectionFormScreenState();
}

class _CorrectionFormScreenState extends ConsumerState<CorrectionFormScreen> {
  late DateTime _date;
  String _type = 'check_in';
  TimeOfDay? _checkIn;
  TimeOfDay? _checkOut;
  String? _reason;
  final _note = TextEditingController();
  Uint8List? _attachment;
  bool _busy = false;
  Map<String, List<String>> _fieldErrors = const {};
  String? _error;

  @override
  void initState() {
    super.initState();
    final today = DateUtils.dateOnly(DateTime.now());
    _date = widget.initialDate == null ? today : DateUtils.dateOnly(widget.initialDate!);
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  int get _maxDays => ref.read(currentConfigProvider)?.attendance.correctionMaxDays ?? 7;

  bool get _needsIn => _type == 'check_in' || _type == 'both';

  bool get _needsOut => _type == 'check_out' || _type == 'both';

  Future<void> _pickDate() async {
    final today = DateUtils.dateOnly(DateTime.now());
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: today.subtract(Duration(days: _maxDays)),
      lastDate: today,
      helpText: 'Tanggal presensi',
    );
    if (picked != null) {
      setState(() => _date = picked);
    }
  }

  Future<void> _pickTime({required bool checkIn}) async {
    final picked = await showTimePicker(
      context: context,
      initialTime: (checkIn ? _checkIn : _checkOut) ?? TimeOfDay(hour: checkIn ? 8 : 16, minute: 0),
      helpText: checkIn ? 'Jam masuk' : 'Jam pulang',
      builder: (context, child) =>
          MediaQuery(data: MediaQuery.of(context).copyWith(alwaysUse24HourFormat: true), child: child!),
    );
    if (picked != null) {
      setState(() => checkIn ? _checkIn = picked : _checkOut = picked);
    }
  }

  Future<void> _takeAttachment() async {
    final kb = ref.read(currentConfigProvider)?.attendance.correctionAttachmentMaxKb ?? 2048;
    final photo = await ref.read(attachmentCaptureProvider)(context, attachmentMaxBytes(kb));
    if (photo != null && mounted) {
      setState(() => _attachment = photo);
    }
  }

  static String _hm(TimeOfDay t) => '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

  /// Validasi di HP agar pengguna tidak menunggu server untuk galat yang jelas; aturan jam
  /// terhadap jendela shift (BR-060) tetap di server.
  Map<String, List<String>> _validate() => {
    if (_needsIn && _checkIn == null) 'proposed_check_in': ['Jam masuk wajib diisi.'],
    if (_needsOut && _checkOut == null) 'proposed_check_out': ['Jam pulang wajib diisi.'],
    if (_reason == null) 'reason_code': ['Pilih alasan koreksi.'],
    if (_note.text.trim().length < 10) 'note': ['Keterangan minimal 10 karakter.'],
  };

  Future<void> _submit() async {
    final errors = _validate();
    setState(() {
      _fieldErrors = errors;
      _error = null;
    });
    if (errors.isNotEmpty) {
      return;
    }
    setState(() => _busy = true);
    try {
      final response = await ref
          .read(apiClientProvider)
          .post(
            MyCorrectionsScreen.path,
            data: FormData.fromMap({
              'work_date': Fmt.toIsoDate(_date),
              'type': _type,
              if (_needsIn) 'proposed_check_in': _hm(_checkIn!),
              if (_needsOut) 'proposed_check_out': _hm(_checkOut!),
              'reason_code': _reason,
              'note': _note.text.trim(),
              if (_attachment != null)
                'attachment': MultipartFile.fromBytes(
                  _attachment!,
                  filename: 'lampiran.jpg',
                  contentType: DioMediaType('image', 'jpeg'),
                ),
            }),
          );
      if (mounted) {
        Navigator.of(context).pop(Correction.fromJson(response.object));
      }
    } on ApiError catch (e) {
      if (!mounted) {
        return;
      }
      setState(() {
        _fieldErrors = e.fieldErrors;
        _error = e.fieldErrors.isEmpty ? e.message : null;
      });
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  String? _err(String field) => _fieldErrors[field]?.join(' ');

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;

    Widget picker({required String label, required String value, required VoidCallback onTap, String? error}) =>
        Padding(
          padding: const EdgeInsets.only(bottom: 16),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(8),
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: label,
                errorText: error,
                suffixIcon: const Icon(Icons.arrow_drop_down),
              ),
              child: Text(value),
            ),
          ),
        );

    return Scaffold(
      appBar: AppBar(title: const Text('Ajukan koreksi')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 16),
                child: Text(_error!, style: text.bodyMedium?.copyWith(color: tokens.danger)),
              ),
            picker(label: 'Tanggal', value: Fmt.longDate(_date), onTap: _pickDate, error: _err('work_date')),
            Text('Jenis koreksi', style: text.labelLarge),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: [
                for (final e in correctionTypeLabels.entries) ButtonSegment(value: e.key, label: Text(e.value)),
              ],
              selected: {_type},
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() => _type = s.first),
            ),
            const SizedBox(height: 16),
            if (_needsIn)
              picker(
                label: 'Jam masuk',
                value: _checkIn == null ? 'Pilih jam' : _hm(_checkIn!).replaceAll(':', '.'),
                onTap: () => _pickTime(checkIn: true),
                error: _err('proposed_check_in'),
              ),
            if (_needsOut)
              picker(
                label: 'Jam pulang',
                value: _checkOut == null ? 'Pilih jam' : _hm(_checkOut!).replaceAll(':', '.'),
                onTap: () => _pickTime(checkIn: false),
                error: _err('proposed_check_out'),
              ),
            _ReasonField(value: _reason, error: _err('reason_code'), onChanged: (v) => setState(() => _reason = v)),
            const SizedBox(height: 16),
            TextField(
              controller: _note,
              maxLength: 500,
              maxLines: 3,
              decoration: InputDecoration(
                labelText: 'Keterangan',
                hintText: 'Mis. lupa menekan Masuk karena langsung rapat',
                errorText: _err('note'),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: Text(
                    _attachment == null
                        ? 'Lampiran foto (opsional)'
                        : 'Lampiran foto siap (${Fmt.number((_attachment!.lengthInBytes / 1024).round())} KB)',
                    style: text.bodyMedium,
                  ),
                ),
                if (_attachment != null)
                  IconButton(
                    tooltip: 'Hapus lampiran',
                    icon: const Icon(Icons.close),
                    onPressed: () => setState(() => _attachment = null),
                  ),
                TextButton.icon(
                  onPressed: _busy ? null : _takeAttachment,
                  icon: const Icon(Icons.photo_camera_outlined),
                  label: Text(_attachment == null ? 'Foto' : 'Ulangi'),
                ),
              ],
            ),
            if (_err('attachment') != null)
              Text(_err('attachment')!, style: text.bodySmall?.copyWith(color: tokens.danger)),
            const SizedBox(height: 24),
            SizedBox(
              height: 48,
              child: FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Kirim Koreksi'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Pilihan alasan dari API (`correctionReasonsProvider`); galat → pesan + Coba Lagi.
class _ReasonField extends ConsumerWidget {
  const _ReasonField({required this.value, required this.error, required this.onChanged});

  final String? value;
  final String? error;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final reasons = ref.watch(correctionReasonsProvider);
    return switch (reasons) {
      AsyncData(value: final items) => DropdownButtonFormField<String>(
        initialValue: items.any((r) => r.code == value) ? value : null,
        isExpanded: true,
        decoration: InputDecoration(labelText: 'Alasan', errorText: error),
        items: [for (final r in items) DropdownMenuItem(value: r.code, child: Text(r.name))],
        onChanged: onChanged,
      ),
      AsyncError(:final error) => InputDecorator(
        decoration: InputDecoration(labelText: 'Alasan', errorText: this.error),
        child: Row(
          children: [
            Expanded(
              child: Text(
                error is ApiError ? 'Daftar alasan gagal dimuat. ${error.message}' : 'Daftar alasan gagal dimuat.',
                style: TextStyle(color: context.tokens.danger),
              ),
            ),
            TextButton(onPressed: () => ref.invalidate(correctionReasonsProvider), child: const Text('Coba Lagi')),
          ],
        ),
      ),
      _ => const InputDecorator(
        decoration: InputDecoration(labelText: 'Alasan'),
        child: LinearProgressIndicator(),
      ),
    };
  }
}
