import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/router.dart';
import '../../core/api/api_error.dart';
import '../../core/theme/pakis_tokens.dart';
import '../startup/startup_controller.dart';
import 'password_recovery.dart';
import 'session.dart';
import 'widgets/auth_scaffold.dart';

/// Lupa password (07 §5, FR-005): identitas + kanal dari `auth.forgot_channels`. Email → kartu
/// "cek email" (tautan dibuka di browser); WhatsApp/Telegram → layar kode OTP.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  late final TextEditingController _login = TextEditingController(text: ref.read(forgotLoginProvider) ?? '');
  String? _channel;
  bool _busy = false;
  bool _emailSent = false;
  ApiError? _error;
  String? _localError;

  @override
  void dispose() {
    _login.dispose();
    super.dispose();
  }

  List<String> _channels() {
    final startup = ref.watch(startupProvider).value;
    final configured = startup is StartupReady ? startup.config.auth.forgotChannels : const <String>[];
    return configured.where(PasswordRecovery.channelLabels.containsKey).toList();
  }

  Future<void> _submit(String channel) async {
    if (_login.text.trim().isEmpty) {
      setState(() => _localError = 'Isi username atau email Anda.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _localError = null;
    });
    try {
      await ref.read(passwordRecoveryProvider).request(login: _login.text, channel: channel);
      ref.read(forgotLoginProvider.notifier).set(_login.text.trim());
      if (!mounted) {
        return;
      }
      if (channel == 'email') {
        setState(() => _emailSent = true);
      } else {
        context.go(Routes.forgotCode);
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
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final channels = _channels();
    final channel = _channel ?? (channels.isEmpty ? null : channels.first);
    final fieldErrors = _error?.fieldErrors ?? const {};
    final message = _localError ?? (_error != null && fieldErrors.isEmpty ? _error!.message : null);

    return AuthScaffold(
      title: 'Lupa password',
      subtitle: 'Kami kirim petunjuk untuk membuat password baru.',
      onBack: () => context.go(Routes.login),
      child: AuthCard(
        children: _emailSent
            ? [
                Icon(Icons.mark_email_read_outlined, size: 56, color: tokens.primary),
                const SizedBox(height: 12),
                Text('Cek email Anda', style: text.titleLarge, textAlign: TextAlign.center),
                const SizedBox(height: 8),
                Text(
                  'Jika akun ditemukan dan emailnya terverifikasi, tautan untuk membuat password baru sudah '
                  'dikirim. Buka tautan itu, lalu kembali ke sini untuk masuk ke akun.',
                  style: text.bodyLarge,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 24),
                AuthPrimaryButton(label: 'Kembali ke Masuk', onPressed: () => context.go(Routes.login)),
              ]
            : [
                if (message != null) FormNotice(message),
                if (channels.isEmpty)
                  const FormNotice('Reset password lewat aplikasi belum diaktifkan. Hubungi admin atau HRD.')
                else ...[
                  TextField(
                    controller: _login,
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                    enableSuggestions: false,
                    textInputAction: TextInputAction.done,
                    decoration: authInputDecoration(
                      context,
                      label: 'Username atau email',
                      icon: Icons.person_outline,
                      errorText: fieldErrors['login']?.first,
                    ),
                  ),
                  if (channels.length > 1) ...[
                    const SizedBox(height: 16),
                    Text('Kirim lewat', style: text.titleSmall),
                    const SizedBox(height: 8),
                    SegmentedButton<String>(
                      segments: [
                        for (final c in channels)
                          ButtonSegment(value: c, label: Text(PasswordRecovery.channelLabels[c]!)),
                      ],
                      selected: {?channel},
                      onSelectionChanged: (value) => setState(() => _channel = value.first),
                    ),
                  ],
                  const SizedBox(height: 8),
                  Text(
                    channel == 'email'
                        ? 'Tautan dikirim ke email akun Anda.'
                        : 'Kode dikirim ke ${PasswordRecovery.channelLabels[channel]} yang terdaftar di akun Anda.',
                    style: text.bodyMedium?.copyWith(color: tokens.muted),
                  ),
                  const SizedBox(height: 24),
                  AuthPrimaryButton(
                    label: channel == 'email' ? 'Kirim Tautan' : 'Kirim Kode',
                    icon: Icons.send_rounded,
                    busy: _busy,
                    onPressed: channel == null ? null : () => _submit(channel),
                  ),
                ],
              ],
      ),
    );
  }
}
