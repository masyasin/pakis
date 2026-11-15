import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../startup/startup_controller.dart';
import 'session.dart';
import 'widgets/auth_scaffold.dart';

/// Wajib ganti password (07 §5, SEC-006): penuh layar, tidak bisa dilewati. Tombol Back sistem
/// diblokir dan router mengembalikan ke sini; satu-satunya jalan lain adalah Keluar.
class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  bool _busy = false;
  ApiError? _error;
  String? _localError;

  @override
  void dispose() {
    _current.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  int _minLength() {
    final startup = ref.read(startupProvider).value;
    return startup is StartupReady ? startup.config.auth.passwordMinLength : 10;
  }

  Future<void> _submit() async {
    final min = _minLength();
    final local = _current.text.isEmpty
        ? 'Isi password saat ini.'
        : _password.text.length < min
        ? 'Password baru minimal $min karakter.'
        : _password.text != _confirmation.text
        ? 'Ulangi password baru belum sama.'
        : null;
    setState(() {
      _localError = local;
      _error = null;
    });
    if (local != null) {
      return;
    }

    setState(() => _busy = true);
    try {
      await ref
          .read(sessionProvider.notifier)
          .changePassword(current: _current.text, password: _password.text, confirmation: _confirmation.text);
      // Router pindah ke beranda saat `must_change_password` menjadi false.
    } on ApiError catch (e) {
      if (mounted) {
        setState(() => _error = e);
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = ref.watch(sessionProvider).value;
    final name = session is SignedIn ? session.user.name : null;
    final fieldErrors = _error?.fieldErrors ?? const {};
    final message = _localError ?? (_error != null && fieldErrors.isEmpty ? _error!.message : null);
    final min = _minLength();

    return AuthScaffold(
      title: 'Ganti password',
      subtitle: name == null
          ? 'Demi keamanan, buat password baru sebelum melanjutkan.'
          : 'Halo $name, buat password baru sebelum melanjutkan.',
      canPop: false,
      child: AuthCard(
        children: [
          if (message != null) FormNotice(message),
          PasswordField(
            controller: _current,
            label: 'Password saat ini',
            autofillHints: const [AutofillHints.password],
            errorText: fieldErrors['current_password']?.first,
          ),
          const SizedBox(height: 16),
          PasswordField(
            controller: _password,
            label: 'Password baru',
            autofillHints: const [AutofillHints.newPassword],
            helperText: 'Minimal $min karakter, tidak memuat username. Boleh berupa kalimat.',
            errorText: fieldErrors['password']?.first,
          ),
          const SizedBox(height: 16),
          PasswordField(
            controller: _confirmation,
            label: 'Ulangi password baru',
            autofillHints: const [AutofillHints.newPassword],
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: 24),
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                : const Text('Simpan Password'),
          ),
          const SizedBox(height: 8),
          TextButton(
            style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            onPressed: _busy ? null : () => ref.read(sessionProvider.notifier).logout(),
            child: const Text('Keluar'),
          ),
        ],
      ),
    );
  }
}
