import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../../core/theme/pakis_tokens.dart';
import '../../startup/startup_controller.dart';

/// Kerangka layar sebelum masuk (07 §5): header `primaryStrong` bergaya prototipe (nama aplikasi
/// + perusahaan dari `/config`), lalu kartu isi. Bisa digulir agar keyboard tidak menutupi tombol.
class AuthScaffold extends ConsumerWidget {
  const AuthScaffold({
    required this.title,
    required this.child,
    this.subtitle,
    this.onBack,
    this.canPop = true,
    super.key,
  });

  final String title;
  final String? subtitle;
  final Widget child;

  /// Tombol kembali di header; null = tanpa tombol.
  final VoidCallback? onBack;

  /// `false` = tombol Back sistem diblokir (wajib ganti password).
  final bool canPop;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final config = ref.watch(currentConfigProvider);

    return PopScope(
      canPop: canPop,
      child: Scaffold(
        backgroundColor: tokens.bg,
        body: SafeArea(
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                ColoredBox(
                  color: tokens.primaryStrong,
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(onBack == null ? 24 : 8, 20, 24, 28),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (onBack != null)
                          IconButton(
                            onPressed: onBack,
                            icon: const Icon(Icons.arrow_back, color: Colors.white),
                            tooltip: 'Kembali',
                          ),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  const Icon(Icons.eco, color: Colors.white, size: 28),
                                  const SizedBox(width: 8),
                                  Text(
                                    'Pakis Pro',
                                    style: text.titleMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w700),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 16),
                              Text(
                                title,
                                style: text.headlineSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w700),
                              ),
                              if (subtitle != null) ...[
                                const SizedBox(height: 4),
                                Text(
                                  subtitle!,
                                  style: text.bodyMedium?.copyWith(color: Colors.white.withValues(alpha: 0.85)),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                Padding(padding: const EdgeInsets.fromLTRB(16, 20, 16, 24), child: child),
                if (config != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 24),
                    child: Text(
                      config.companyName,
                      style: text.bodySmall?.copyWith(color: tokens.muted),
                      textAlign: TextAlign.center,
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Kartu putih radius 12 bergaris tepi (gaya prototipe).
class AuthCard extends StatelessWidget {
  const AuthCard({required this.children, super.key});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: tokens.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: tokens.border),
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
      ),
    );
  }
}

/// Kotak pesan di atas form (galat atau info), dibacakan pembaca layar.
class FormNotice extends StatelessWidget {
  const FormNotice(this.message, {this.isError = true, super.key});

  final String message;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final tone = isError ? StatusTone.danger : StatusTone.success;
    return Semantics(
      liveRegion: true,
      child: Container(
        margin: const EdgeInsets.only(bottom: 16),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: tokens.statusBg[tone], borderRadius: BorderRadius.circular(8)),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(isError ? Icons.error_outline : Icons.check_circle_outline, color: tokens.status[tone], size: 20),
            const SizedBox(width: 8),
            Expanded(
              child: Text(message, style: TextStyle(color: tokens.status[tone])),
            ),
          ],
        ),
      ),
    );
  }
}

/// Field password dengan tombol tampil/sembunyi (target sentuh 48 dp).
class PasswordField extends StatefulWidget {
  const PasswordField({
    required this.controller,
    required this.label,
    this.errorText,
    this.helperText,
    this.autofillHints = const [AutofillHints.password],
    this.textInputAction = TextInputAction.next,
    this.onSubmitted,
    super.key,
  });

  final TextEditingController controller;
  final String label;
  final String? errorText;
  final String? helperText;
  final Iterable<String> autofillHints;
  final TextInputAction textInputAction;
  final ValueChanged<String>? onSubmitted;

  @override
  State<PasswordField> createState() => _PasswordFieldState();
}

class _PasswordFieldState extends State<PasswordField> {
  bool _visible = false;

  @override
  Widget build(BuildContext context) => TextField(
    controller: widget.controller,
    obscureText: !_visible,
    enableSuggestions: false,
    autocorrect: false,
    autofillHints: widget.autofillHints,
    textInputAction: widget.textInputAction,
    onSubmitted: widget.onSubmitted,
    decoration: InputDecoration(
      labelText: widget.label,
      errorText: widget.errorText,
      helperText: widget.helperText,
      helperMaxLines: 2,
      errorMaxLines: 3,
      suffixIcon: IconButton(
        tooltip: _visible ? 'Sembunyikan password' : 'Tampilkan password',
        icon: Icon(_visible ? Icons.visibility_off_outlined : Icons.visibility_outlined),
        onPressed: () => setState(() => _visible = !_visible),
      ),
    ),
  );
}
