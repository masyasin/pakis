import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/router.dart';
import '../../core/api/api_error.dart';
import '../startup/startup_controller.dart';
import 'session.dart';
import 'widgets/auth_scaffold.dart';

/// Masuk (07 §5, FR-002/FR-003): username/email + password, lalu kode MFA bila server meminta
/// (`MFA_REQUIRED`). `DEVICE_PENDING` → layar menunggu persetujuan perangkat.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _login = TextEditingController();
  final _password = TextEditingController();
  final _otp = TextEditingController();

  bool _busy = false;
  bool _needsOtp = false;
  ApiError? _error;

  /// Galat isian kosong (dicek di HP, tanpa request).
  String? _localError;

  @override
  void dispose() {
    _login.dispose();
    _password.dispose();
    _otp.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) {
      return;
    }
    final missing = _login.text.trim().isEmpty || _password.text.isEmpty
        ? 'Isi username/email dan password.'
        : _needsOtp && _otp.text.trim().isEmpty
        ? 'Masukkan kode verifikasi.'
        : null;
    setState(() {
      _localError = missing;
      _error = null;
    });
    if (missing != null) {
      return;
    }

    setState(() => _busy = true);
    ref.read(loginNoticeProvider.notifier).set(null);

    try {
      await ref
          .read(sessionProvider.notifier)
          .login(login: _login.text, password: _password.text, otp: _needsOtp ? _otp.text : null);
      ref.read(pendingLoginProvider.notifier).set(null);
      // Router pindah sendiri ke beranda / ganti password saat sesi berubah.
    } on ApiError catch (e) {
      if (!mounted) {
        return;
      }
      switch (e.code) {
        case ApiErrorCode.mfaRequired:
          setState(() => _needsOtp = true);
        case ApiErrorCode.devicePending:
          ref.read(pendingLoginProvider.notifier).set(PendingLogin(_login.text, _password.text));
          context.go(Routes.devicePending);
        default:
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
    final text = Theme.of(context).textTheme;
    final startup = ref.watch(startupProvider).value;
    final offline = startup is StartupReady && startup.fromCache;
    final session = ref.watch(sessionProvider).value;
    // Info sukses (mis. password baru tersimpan) didahulukan; pesan sesi berakhir = peringatan.
    final info = ref.watch(loginNoticeProvider);
    final warning = session is SignedOut ? session.notice : null;
    final error = _error;
    final fieldErrors = error?.fieldErrors ?? const {};
    final message = _localError ?? (error != null && fieldErrors.isEmpty ? error.message : null);
    final config = startup is StartupReady ? startup.config : null;

    return AuthScaffold(
      title: 'Masuk',
      subtitle: config?.loginTagline ?? 'Absen kerja ${config?.companyName ?? 'CV Pakis Jaya Abadi'}',
      child: AutofillGroup(
        child: AuthCard(
          children: [
            if (offline)
              const FormNotice('Tidak ada koneksi. Memakai pengaturan terakhir yang tersimpan; masuk butuh internet.'),
            if (message != null)
              FormNotice(message)
            else if (info != null)
              FormNotice(info, isError: false)
            else if (warning != null)
              FormNotice(warning),
            TextField(
              controller: _login,
              enabled: !_needsOtp,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
              enableSuggestions: false,
              autofillHints: const [AutofillHints.username],
              textInputAction: TextInputAction.next,
              decoration: InputDecoration(labelText: 'Username atau email', errorText: fieldErrors['login']?.first),
            ),
            const SizedBox(height: 16),
            if (!_needsOtp)
              PasswordField(
                controller: _password,
                label: 'Password',
                errorText: fieldErrors['password']?.first,
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _submit(),
              )
            else ...[
              Text('Verifikasi dua langkah', style: text.titleSmall),
              const SizedBox(height: 4),
              Text(
                'Masukkan 6 digit dari aplikasi authenticator, atau salah satu kode pemulihan.',
                style: text.bodyMedium,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _otp,
                autofocus: true,
                keyboardType: TextInputType.visiblePassword,
                autocorrect: false,
                enableSuggestions: false,
                autofillHints: const [AutofillHints.oneTimeCode],
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _submit(),
                decoration: InputDecoration(labelText: 'Kode verifikasi', errorText: fieldErrors['otp']?.first),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton(
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                  : Text(_needsOtp ? 'Verifikasi & Masuk' : 'Masuk'),
            ),
            const SizedBox(height: 8),
            if (_needsOtp)
              TextButton(
                style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                onPressed: _busy
                    ? null
                    : () => setState(() {
                        _needsOtp = false;
                        _otp.clear();
                        _error = null;
                        _localError = null;
                      }),
                child: const Text('Ganti akun'),
              )
            else
              TextButton(
                style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                onPressed: _busy ? null : () => context.go(Routes.forgot),
                child: const Text('Lupa password?'),
              ),
          ],
        ),
      ),
    );
  }
}
