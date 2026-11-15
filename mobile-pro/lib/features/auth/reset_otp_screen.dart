import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/router.dart';
import '../../core/api/api_error.dart';
import '../startup/startup_controller.dart';
import 'password_recovery.dart';
import 'session.dart';
import 'widgets/auth_scaffold.dart';

/// Kode OTP + password baru (07 §5, FR-006). Panjang minimum dari `/config`; aturan lain
/// (tidak memuat username, bukan password bocor) dicek server dan tampil sebagai galat field.
class ResetOtpScreen extends ConsumerStatefulWidget {
  const ResetOtpScreen({super.key});

  @override
  ConsumerState<ResetOtpScreen> createState() => _ResetOtpScreenState();
}

class _ResetOtpScreenState extends ConsumerState<ResetOtpScreen> {
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  bool _busy = false;
  ApiError? _error;
  String? _localError;

  @override
  void dispose() {
    _code.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  int _minLength() {
    final startup = ref.read(startupProvider).value;
    return startup is StartupReady ? startup.config.auth.passwordMinLength : 10;
  }

  Future<void> _submit(String login) async {
    final min = _minLength();
    final local = _code.text.trim().isEmpty
        ? 'Masukkan kode yang Anda terima.'
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
          .read(passwordRecoveryProvider)
          .resetWithCode(login: login, code: _code.text, password: _password.text, confirmation: _confirmation.text);
      ref.read(forgotLoginProvider.notifier).set(null);
      ref.read(loginNoticeProvider.notifier).set('Password baru tersimpan. Silakan masuk ke akun.');
      if (mounted) {
        context.go(Routes.login);
      }
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
    final login = ref.watch(forgotLoginProvider);
    final fieldErrors = _error?.fieldErrors ?? const {};
    final message = _localError ?? (_error != null && fieldErrors.isEmpty ? _error!.message : null);
    final min = _minLength();

    return AuthScaffold(
      title: 'Masukkan kode',
      subtitle: login == null ? null : 'Kode dikirim untuk akun $login.',
      onBack: () => context.go(Routes.forgot),
      child: AuthCard(
        children: login == null
            ? [
                const FormNotice('Mulai lagi dari Lupa password.'),
                AuthPrimaryButton(label: 'Lupa Password', onPressed: () => context.go(Routes.forgot)),
              ]
            : [
                if (message != null) FormNotice(message),
                TextField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  autofillHints: const [AutofillHints.oneTimeCode],
                  textInputAction: TextInputAction.next,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(letterSpacing: 2),
                  decoration: authInputDecoration(
                    context,
                    label: 'Kode',
                    icon: Icons.pin_outlined,
                    errorText: fieldErrors['code']?.first,
                  ),
                ),
                const SizedBox(height: 16),
                PasswordField(
                  controller: _password,
                  label: 'Password baru',
                  autofillHints: const [AutofillHints.newPassword],
                  helperText: 'Minimal $min karakter. Boleh berupa kalimat.',
                  errorText: fieldErrors['password']?.first,
                ),
                const SizedBox(height: 16),
                PasswordField(
                  controller: _confirmation,
                  label: 'Ulangi password baru',
                  autofillHints: const [AutofillHints.newPassword],
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _submit(login),
                ),
                const SizedBox(height: 24),
                AuthPrimaryButton(
                  label: 'Simpan Password Baru',
                  icon: Icons.check_rounded,
                  busy: _busy,
                  onPressed: () => _submit(login),
                ),
              ],
      ),
    );
  }
}
