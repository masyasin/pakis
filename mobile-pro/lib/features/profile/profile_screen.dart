import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/device/native_device.dart';
import '../../core/providers.dart';
import '../../core/theme/pakis_tokens.dart';
import '../../shared/widgets/list_item_card.dart';
import '../attendance/attendance_providers.dart';
import '../auth/mobile_user.dart';
import '../auth/session.dart';
import 'profile_password_screen.dart';
import 'user_avatar.dart';

final deviceInfoProvider = FutureProvider.autoDispose<DeviceInfo?>((ref) async {
  try {
    return await ref.watch(nativeDeviceProvider).deviceInfo();
  } on Object {
    return null;
  }
});

/// Tab Profil (07 §5): data diri, ubah HP/foto, ubah password, info perangkat, keluar dari
/// sesi web lain, Keluar.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  static const mePath = '/api/v1/me';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider).value;
    final user = session is SignedIn ? session.user : null;
    if (user == null) {
      return const SizedBox.shrink();
    }
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final device = ref.watch(deviceInfoProvider).value;

    Widget section(String title) => Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
      child: Text(title.toUpperCase(), style: text.labelMedium?.copyWith(color: tokens.muted, letterSpacing: 0.6)),
    );

    return ListView(
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              UserAvatar(name: user.name, radius: 32, background: tokens.primary, foreground: tokens.onPrimary),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(user.username, style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                    if (user.employee != null)
                      Text(user.employee!.employeeNumber, style: text.bodyMedium?.copyWith(color: tokens.muted)),
                  ],
                ),
              ),
            ],
          ),
        ),
        section('Data diri'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Column(
            children: [
              if (user.employee?.position != null) DetailRow(label: 'Jabatan', value: user.employee!.position!),
              if (user.employee?.location != null) DetailRow(label: 'Lokasi', value: user.employee!.location!),
              DetailRow(label: 'Email', value: user.email ?? '-'),
              DetailRow(label: 'Nomor HP', value: user.phone ?? '-'),
            ],
          ),
        ),
        section('Akun'),
        ListItemCard(
          icon: Icons.phone_android_outlined,
          title: 'Ubah nomor HP',
          subtitle: 'Perlu password saat ini',
          onTap: () => _changePhone(context, ref, user),
        ),
        ListItemCard(
          icon: Icons.account_circle_outlined,
          title: 'Ganti foto profil',
          subtitle: user.hasAvatar ? 'Foto sudah ada · ambil foto baru dengan kamera' : 'Ambil foto dengan kamera',
          onTap: () => _changeAvatar(context, ref),
        ),
        ListItemCard(
          icon: Icons.lock_outline,
          title: 'Ubah password',
          subtitle: 'Sesi web dan HP lain akan dikeluarkan',
          onTap: () =>
              Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const ProfilePasswordScreen())),
        ),
        ListItemCard(
          icon: Icons.devices_other_outlined,
          title: 'Keluar dari sesi web lain',
          subtitle: 'Bila Anda lupa keluar di komputer lain',
          onTap: () => _revokeOthers(context, ref),
        ),
        section('Perangkat'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Column(
            children: [
              DetailRow(label: 'HP', value: device == null ? '-' : '${device.model} · ${device.osVersion}'),
              DetailRow(label: 'Versi Pakis Pro', value: ref.watch(appVersionProvider)),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: SizedBox(
            height: 48,
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(foregroundColor: tokens.danger),
              onPressed: () => _confirmLogout(context, ref),
              icon: const Icon(Icons.logout),
              label: const Text('Keluar'),
            ),
          ),
        ),
      ],
    );
  }

  /// Ganti pesan sebelumnya, bukan mengantre di belakangnya.
  static void _snack(BuildContext context, String message) => ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));

  Future<void> _changePhone(BuildContext context, WidgetRef ref, MobileUser user) async {
    final saved = await showDialog<bool>(
      context: context,
      builder: (_) => _PhoneDialog(initial: user.phone ?? ''),
    );
    if (saved == true && context.mounted) {
      _snack(context, 'Nomor HP diperbarui.');
    }
  }

  Future<void> _changeAvatar(BuildContext context, WidgetRef ref) async {
    final photo = await ref.read(selfieCaptureProvider)(context);
    if (photo == null || !context.mounted) {
      return;
    }
    try {
      await uploadAvatar(ref, photo);
      if (context.mounted) {
        _snack(context, 'Foto profil diperbarui.');
      }
    } on ApiError catch (e) {
      if (context.mounted) {
        _snack(context, e.fieldErrors['avatar']?.join(' ') ?? e.message);
      }
    }
  }

  Future<void> _revokeOthers(BuildContext context, WidgetRef ref) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Keluar dari sesi web lain?'),
        content: const Text('Semua sesi PAKIS ONE web milik Anda dikeluarkan. Pakis Pro di HP ini tetap masuk.'),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Keluarkan')),
        ],
      ),
    );
    if (ok != true) {
      return;
    }
    try {
      final response = await ref.read(apiClientProvider).post('$mePath/sessions/revoke-others');
      final revoked = (response.object['revoked'] as num?)?.toInt() ?? 0;
      if (context.mounted) {
        _snack(context, revoked == 0 ? 'Tidak ada sesi web lain yang aktif.' : '$revoked sesi web dikeluarkan.');
      }
    } on ApiError catch (e) {
      if (context.mounted) {
        _snack(context, e.message);
      }
    }
  }

  Future<void> _confirmLogout(BuildContext context, WidgetRef ref) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Keluar dari Pakis Pro?'),
        content: const Text(
          'Anda perlu masuk lagi ke akun untuk presensi. Presensi yang belum terkirim tetap tersimpan di HP.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Keluar')),
        ],
      ),
    );
    if (ok == true) {
      await ref.read(sessionProvider.notifier).logout();
    }
  }
}

/// Unggah foto profil. Multipart lewat `POST` + `_method=PUT`: PHP tidak mengurai body
/// multipart untuk `PUT`, sedangkan Laravel memetakan `_method` ke route `PUT /me`.
Future<void> uploadAvatar(WidgetRef ref, Uint8List photo) async {
  await ref
      .read(apiClientProvider)
      .post(
        ProfileScreen.mePath,
        data: FormData.fromMap({
          '_method': 'PUT',
          'avatar': MultipartFile.fromBytes(photo, filename: 'avatar.jpg', contentType: DioMediaType('image', 'jpeg')),
        }),
      );
  await ref.read(sessionProvider.notifier).refresh();
  // `has_avatar` bisa sudah true sebelumnya: muat ulang foto walau sesi tidak berubah.
  ref.invalidate(avatarProvider);
}

class _PhoneDialog extends ConsumerStatefulWidget {
  const _PhoneDialog({required this.initial});

  final String initial;

  @override
  ConsumerState<_PhoneDialog> createState() => _PhoneDialogState();
}

class _PhoneDialogState extends ConsumerState<_PhoneDialog> {
  late final _phone = TextEditingController(text: widget.initial);
  final _password = TextEditingController();
  Map<String, List<String>> _errors = const {};
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _errors = const {};
      _error = null;
    });
    try {
      await ref
          .read(apiClientProvider)
          .put(ProfileScreen.mePath, data: {'phone': _phone.text.trim(), 'current_password': _password.text});
      await ref.read(sessionProvider.notifier).refresh();
      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _errors = e.fieldErrors;
          _error = e.fieldErrors.isEmpty ? e.message : null;
        });
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Ubah nomor HP'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (_error != null) Text(_error!, style: TextStyle(color: context.tokens.danger)),
        TextField(
          controller: _phone,
          keyboardType: TextInputType.phone,
          decoration: InputDecoration(
            labelText: 'Nomor HP',
            hintText: '6281234567890',
            errorText: _errors['phone']?.join(' '),
          ),
        ),
        TextField(
          controller: _password,
          obscureText: true,
          decoration: InputDecoration(
            labelText: 'Password saat ini',
            errorText: _errors['current_password']?.join(' '),
          ),
        ),
      ],
    ),
    actions: [
      TextButton(onPressed: _busy ? null : () => Navigator.of(context).pop(false), child: const Text('Batal')),
      FilledButton(onPressed: _busy ? null : _save, child: const Text('Simpan')),
    ],
  );
}
