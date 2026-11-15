import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/router.dart';
import '../../core/api/api_error.dart';
import '../../core/theme/pakis_tokens.dart';
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

    final tokens = context.tokens;

    return AuthScaffold(
      title: _needsOtp ? 'Verifikasi dua langkah' : 'Selamat datang',
      subtitle: _needsOtp
          ? 'Satu langkah lagi untuk menjaga akun Anda.'
          : config?.loginTagline ?? 'Masuk untuk mulai absen hari ini.',
      child: AutofillGroup(
        child: AuthCard(
          children: [
            Text(
              _needsOtp ? 'Kode verifikasi' : 'Masuk ke akun Anda',
              style: text.titleMedium?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 4),
            Text(
              _needsOtp
                  ? 'Masukkan 6 digit dari aplikasi authenticator, atau salah satu kode pemulihan.'
                  : 'Gunakan akun dari admin atau HRD kantor.',
              style: text.bodyMedium?.copyWith(color: tokens.muted),
            ),
            const SizedBox(height: 18),
            if (offline)
              const FormNotice('Tidak ada koneksi. Memakai pengaturan terakhir yang tersimpan; masuk butuh internet.'),
            if (message != null)
              FormNotice(message)
            else if (info != null)
              FormNotice(info, isError: false)
            else if (warning != null)
              FormNotice(warning),
            if (!_needsOtp) ...[
              TextField(
                controller: _login,
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                enableSuggestions: false,
                autofillHints: const [AutofillHints.username],
                textInputAction: TextInputAction.next,
                decoration: authInputDecoration(
                  context,
                  label: 'Username atau email',
                  icon: Icons.person_outline,
                  errorText: fieldErrors['login']?.first,
                ),
              ),
              const SizedBox(height: 14),
              PasswordField(
                controller: _password,
                label: 'Password',
                errorText: fieldErrors['password']?.first,
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _submit(),
              ),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  onPressed: _busy ? null : () => context.go(Routes.forgot),
                  child: const Text('Lupa password?'),
                ),
              ),
            ] else ...[
              TextField(
                controller: _otp,
                autofocus: true,
                keyboardType: TextInputType.visiblePassword,
                autocorrect: false,
                enableSuggestions: false,
                autofillHints: const [AutofillHints.oneTimeCode],
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _submit(),
                style: text.titleMedium?.copyWith(letterSpacing: 2),
                decoration: authInputDecoration(
                  context,
                  label: 'Kode verifikasi',
                  icon: Icons.shield_outlined,
                  errorText: fieldErrors['otp']?.first,
                ),
              ),
              const SizedBox(height: 18),
            ],
            AuthPrimaryButton(
              label: _needsOtp ? 'Verifikasi & Masuk' : 'Masuk',
              icon: Icons.arrow_forward_rounded,
              busy: _busy,
              onPressed: _submit,
            ),
            if (_needsOtp) ...[
              const SizedBox(height: 8),
              TextButton(
                onPressed: _busy
                    ? null
                    : () => setState(() {
                        _needsOtp = false;
                        _otp.clear();
                        _error = null;
                        _localError = null;
                      }),
                child: Text('Bukan ${_login.text.trim()}? Ganti akun'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
