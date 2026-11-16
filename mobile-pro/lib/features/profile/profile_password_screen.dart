import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/theme/pakis_tokens.dart';
import '../auth/session.dart';
import '../startup/startup_controller.dart';

/// Ubah password dari Profil (FR-004, `PUT /me/password`). Berbeda dengan layar wajib ganti
/// password (SEC-006): bisa ditutup. Token HP ini tetap berlaku; sesi lain dicabut server.
class ProfilePasswordScreen extends ConsumerStatefulWidget {
  const ProfilePasswordScreen({super.key});

  @override
  ConsumerState<ProfilePasswordScreen> createState() => _ProfilePasswordScreenState();
}

class _ProfilePasswordScreenState extends ConsumerState<ProfilePasswordScreen> {
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  Map<String, List<String>> _errors = const {};
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _current.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final min = ref.read(currentConfigProvider)?.auth.passwordMinLength ?? 10;
    final local = <String, List<String>>{
      if (_current.text.isEmpty) 'current_password': ['Password saat ini wajib diisi.'],
      if (_password.text.length < min) 'password': ['Password baru minimal $min karakter.'],
      if (_confirmation.text != _password.text) 'password_confirmation': ['Konfirmasi password tidak sama.'],
    };
    setState(() {
      _errors = local;
      _error = null;
    });
    if (local.isNotEmpty) {
      return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(sessionProvider.notifier)
          .changePassword(current: _current.text, password: _password.text, confirmation: _confirmation.text);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Password diperbarui.')));
        Navigator.of(context).pop();
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
  Widget build(BuildContext context) {
    final min = ref.watch(currentConfigProvider)?.auth.passwordMinLength ?? 10;
    TextField field(TextEditingController c, String label, String key, {String? helper}) => TextField(
      controller: c,
      obscureText: true,
      decoration: InputDecoration(labelText: label, helperText: helper, errorText: _errors[key]?.join(' ')),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Ubah password')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(_error!, style: TextStyle(color: context.tokens.danger)),
              ),
            field(_current, 'Password saat ini', 'current_password'),
            const SizedBox(height: 12),
            field(_password, 'Password baru', 'password', helper: 'Minimal $min karakter'),
            const SizedBox(height: 12),
            field(_confirmation, 'Ulangi password baru', 'password_confirmation'),
            const SizedBox(height: 24),
            SizedBox(
              height: 48,
              child: FilledButton(onPressed: _busy ? null : _save, child: const Text('Simpan Password')),
            ),
          ],
        ),
      ),
    );
  }
}
