import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/providers.dart';
import '../auth/session.dart';

/// Foto profil milik sendiri (`GET /api/v1/me/avatar`, `no-store`), hanya di memori. Null bila
/// akun tanpa foto (`has_avatar` false / 404) atau belum bisa dimuat (offline): tampil inisial.
/// Setelah mengganti foto panggil `ref.invalidate(avatarProvider)`.
final avatarProvider = FutureProvider<Uint8List?>((ref) async {
  final key = ref.watch(
    sessionProvider.select(
      (s) => switch (s.value) {
        SignedIn(:final user) when user.hasAvatar => user.id,
        _ => null,
      },
    ),
  );
  if (key == null) {
    return null;
  }
  try {
    final file = await ref.read(apiClientProvider).getBytes('/api/v1/me/avatar');
    return file.isImage ? file.bytes : null;
  } on ApiError {
    return null;
  }
});

/// Foto profil atau inisial nama.
class UserAvatar extends ConsumerWidget {
  const UserAvatar({
    required this.name,
    required this.radius,
    required this.background,
    required this.foreground,
    super.key,
  });

  final String name;
  final double radius;
  final Color background;
  final Color foreground;

  static String initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) {
      return '?';
    }
    return (parts.first[0] + (parts.length > 1 ? parts.last[0] : '')).toUpperCase();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final photo = ref.watch(avatarProvider).value;
    return Semantics(
      label: photo == null ? 'Inisial $name' : 'Foto profil $name',
      excludeSemantics: true,
      child: CircleAvatar(
        radius: radius,
        backgroundColor: background,
        foregroundColor: foreground,
        backgroundImage: photo == null ? null : MemoryImage(photo),
        child: photo == null
            ? Text(
                initials(name),
                style: TextStyle(color: foreground, fontSize: radius * 0.7, fontWeight: FontWeight.w700),
              )
            : null,
      ),
    );
  }
}
