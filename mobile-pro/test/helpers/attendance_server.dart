import 'package:dio/dio.dart';

import 'fakes.dart';

/// Server absen palsu yang meniru 03 §4.5: `UNIQUE (client_uuid)` → kiriman ulang 200 replayed.
class FakeAttendanceServer {
  final stored = <String, Map<String, String>>{};
  final selfies = <String, int>{};
  var online = true;

  /// Simulasi MOB-07: server menyimpan, lalu jaringan putus sebelum jawaban sampai di HP.
  var dropResponseOnce = false;
  FakeReply? Function(Map<String, String> fields)? override;

  /// Isi `data` respons sukses (bawaan: hari hadir minimal).
  Map<String, Object?>? Function(Map<String, String> fields)? respond;

  FakeReply handle(RequestOptions options) {
    if (!online) {
      return const FakeReply.offline();
    }
    final form = options.data! as FormData;
    final fields = {for (final f in form.fields) f.key: f.value};
    final custom = override?.call(fields);
    if (custom != null) {
      return custom;
    }
    final uuid = fields['client_uuid']!;
    final replayed = stored.containsKey(uuid);
    stored.putIfAbsent(uuid, () => fields);
    for (final f in form.files) {
      selfies[uuid] = f.value.length;
    }
    if (dropResponseOnce) {
      dropResponseOnce = false;
      return const FakeReply.offline();
    }
    return FakeReply(replayed ? 200 : 201, {
      'data': respond?.call(fields) ?? {'work_date': '2026-11-03', 'status': 'present'},
      'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ', 'replayed': replayed},
    });
  }
}
