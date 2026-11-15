import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../../core/providers.dart';
import '../../../core/theme/pakis_tokens.dart';
import '../../startup/startup_controller.dart';

/// Kerangka layar sebelum masuk (07 §5) bergaya prototipe mobile (`konsep/…Mobile_v1.0.html`):
/// hero bergradien hijau (`.hero`: 140°, primary → hijau tua) dengan logo perusahaan dari
/// `/config`, lalu kartu form yang "mengambang" di atas hero. Bisa digulir agar keyboard tidak
/// menutupi tombol. Animasi masuk dimatikan bila HP diatur mengurangi animasi.
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

  /// Tombol kembali di hero; null = tanpa tombol.
  final VoidCallback? onBack;

  /// `false` = tombol Back sistem diblokir (wajib ganti password).
  final bool canPop;

  /// Seberapa jauh kartu naik menutupi hero.
  static const _overlap = 44.0;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final config = ref.watch(currentConfigProvider);
    final version = ref.watch(appVersionProvider);
    final dark = Theme.of(context).brightness == Brightness.dark;
    final logo = _resolveLogo(dark ? config?.logoDarkUrl ?? config?.logoUrl : config?.logoUrl, ref);

    // Ikon status bar putih di atas hero hijau (bawaan gelap tidak terbaca).
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light.copyWith(statusBarColor: Colors.transparent),
      child: PopScope(
        canPop: canPop,
        child: Scaffold(
          backgroundColor: tokens.bg,
          body: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _Hero(
                  title: title,
                  subtitle: subtitle,
                  onBack: onBack,
                  appName: 'Pakis Pro',
                  companyName: config?.companyName,
                  logoUrl: logo,
                  bottomPadding: _overlap + 28,
                ),
                Transform.translate(
                  offset: const Offset(0, -_overlap),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 18),
                    child: _EntranceAnimation(child: child),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.verified_user_outlined, size: 16, color: tokens.muted),
                          const SizedBox(width: 6),
                          Flexible(
                            child: Text(
                              'Koneksi aman · satu akun untuk satu HP',
                              style: text.bodySmall?.copyWith(color: tokens.muted),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        ['Pakis Pro v$version', if (config != null) config.companyName].join(' · '),
                        style: text.bodySmall?.copyWith(color: tokens.muted),
                        textAlign: TextAlign.center,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// URL logo dari `/config` dibuat server dengan host APP_URL. Di pengembangan lokal itu
  /// `localhost`, yang di emulator/HP berarti perangkat itu sendiri — ganti dengan host API.
  static String? _resolveLogo(String? url, WidgetRef ref) {
    if (url == null || url.isEmpty) {
      return null;
    }
    final parsed = Uri.tryParse(url);
    if (parsed == null || !parsed.hasScheme) {
      return null;
    }
    if (parsed.host == 'localhost' || parsed.host == '127.0.0.1') {
      final api = Uri.tryParse(ref.read(apiBaseUrlProvider));
      if (api == null) {
        return null;
      }
      return parsed.replace(scheme: api.scheme, host: api.host, port: api.hasPort ? api.port : null).toString();
    }
    return url;
  }
}

class _Hero extends StatelessWidget {
  const _Hero({
    required this.title,
    required this.appName,
    required this.bottomPadding,
    this.subtitle,
    this.onBack,
    this.companyName,
    this.logoUrl,
  });

  final String title;
  final String? subtitle;
  final VoidCallback? onBack;
  final String appName;
  final String? companyName;
  final String? logoUrl;
  final double bottomPadding;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    // Gradien prototipe: terang = primary → hijau tua; gelap = primaryStrong → lebih gelap.
    final start = dark ? tokens.primaryStrong : tokens.primary;
    final end = Color.lerp(tokens.primaryStrong, Colors.black, dark ? 0.45 : 0.3)!;
    final ink = Colors.white;
    final soft = Colors.white.withValues(alpha: 0.82);

    return ClipRRect(
      borderRadius: const BorderRadius.vertical(bottom: Radius.circular(28)),
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [start, end]),
        ),
        child: Stack(
          children: [
            // Ornamen: lingkaran & daun samar, murni dekorasi (tidak dibaca pembaca layar).
            Positioned(right: -60, top: -40, child: _Ring(size: 220, opacity: 0.08)),
            Positioned(left: -50, bottom: -70, child: _Ring(size: 180, opacity: 0.06)),
            Positioned(
              right: 18,
              bottom: bottomPadding - 8,
              child: ExcludeSemantics(
                child: Transform.rotate(
                  angle: -0.35,
                  child: Icon(Icons.eco, size: 96, color: Colors.white.withValues(alpha: 0.09)),
                ),
              ),
            ),
            SafeArea(
              bottom: false,
              child: Padding(
                padding: EdgeInsets.fromLTRB(20, 12, 20, bottomPadding),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        if (onBack != null) ...[
                          IconButton.filledTonal(
                            onPressed: onBack,
                            tooltip: 'Kembali',
                            style: IconButton.styleFrom(
                              backgroundColor: Colors.white.withValues(alpha: 0.16),
                              foregroundColor: ink,
                            ),
                            icon: const Icon(Icons.arrow_back),
                          ),
                          const SizedBox(width: 8),
                        ],
                        _LogoBadge(url: logoUrl),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                appName,
                                style: text.titleMedium?.copyWith(color: ink, fontWeight: FontWeight.w800),
                              ),
                              if (companyName != null)
                                Text(
                                  companyName!,
                                  style: text.bodySmall?.copyWith(color: soft),
                                  overflow: TextOverflow.ellipsis,
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 28),
                    Text(
                      title,
                      style: text.headlineMedium?.copyWith(
                        color: ink,
                        fontWeight: FontWeight.w800,
                        height: 1.15,
                        letterSpacing: -0.3,
                      ),
                    ),
                    if (subtitle != null) ...[
                      const SizedBox(height: 8),
                      Text(subtitle!, style: text.bodyLarge?.copyWith(color: soft, height: 1.35)),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Ring extends StatelessWidget {
  const _Ring({required this.size, required this.opacity});

  final double size;
  final double opacity;

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
    child: Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white.withValues(alpha: opacity), width: 28),
      ),
    ),
  );
}

/// Logo perusahaan dalam lencana putih (seperti ikon aplikasi); ikon daun bila belum ada logo
/// atau gambar gagal dimuat (offline).
class _LogoBadge extends StatelessWidget {
  const _LogoBadge({this.url});

  final String? url;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final fallback = Icon(Icons.eco, color: tokens.primary, size: 26);

    return Container(
      width: 46,
      height: 46,
      padding: const EdgeInsets.all(6),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.15), blurRadius: 12, offset: const Offset(0, 4))],
      ),
      child: url == null
          ? fallback
          : Image.network(
              url!,
              fit: BoxFit.contain,
              semanticLabel: 'Logo perusahaan',
              errorBuilder: (_, _, _) => fallback,
            ),
    );
  }
}

/// Muncul pelan dari bawah saat layar dibuka; langsung tampil bila animasi dimatikan di HP.
class _EntranceAnimation extends StatelessWidget {
  const _EntranceAnimation({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.of(context).disableAnimations) {
      return child;
    }
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: const Duration(milliseconds: 380),
      curve: Curves.easeOutCubic,
      builder: (context, t, child) => Opacity(
        opacity: t,
        child: Transform.translate(offset: Offset(0, 18 * (1 - t)), child: child),
      ),
      child: child,
    );
  }
}

/// Kartu form mengambang (radius 20, bayangan lembut) di atas hero.
class AuthCard extends StatelessWidget {
  const AuthCard({required this.children, super.key});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final dark = Theme.of(context).brightness == Brightness.dark;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: tokens.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: tokens.border.withValues(alpha: dark ? 1 : 0.6)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: dark ? 0.35 : 0.08),
            blurRadius: 28,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
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
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: tokens.statusBg[tone],
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: tokens.status[tone]!.withValues(alpha: 0.25)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(isError ? Icons.error_outline : Icons.check_circle_outline, color: tokens.status[tone], size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                message,
                style: TextStyle(color: tokens.status[tone], fontWeight: FontWeight.w600, height: 1.3),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Gaya isian layar sebelum masuk: berikon, latar lembut (`surface-2` prototipe), radius 14.
InputDecoration authInputDecoration(
  BuildContext context, {
  required String label,
  required IconData icon,
  String? errorText,
  String? helperText,
  Widget? suffixIcon,
}) {
  final tokens = context.tokens;
  final radius = BorderRadius.circular(14);
  return InputDecoration(
    labelText: label,
    errorText: errorText,
    helperText: helperText,
    helperMaxLines: 2,
    errorMaxLines: 3,
    prefixIcon: Icon(icon, color: tokens.muted),
    suffixIcon: suffixIcon,
    filled: true,
    fillColor: Color.lerp(tokens.surface, tokens.primary, 0.05),
    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
    border: OutlineInputBorder(
      borderRadius: radius,
      borderSide: BorderSide(color: tokens.border),
    ),
    enabledBorder: OutlineInputBorder(
      borderRadius: radius,
      borderSide: BorderSide(color: tokens.border),
    ),
    focusedBorder: OutlineInputBorder(
      borderRadius: radius,
      borderSide: BorderSide(color: tokens.primary, width: 2),
    ),
  );
}

/// Tombol utama layar sebelum masuk: tinggi 54, tebal, ikon di kanan.
class AuthPrimaryButton extends StatelessWidget {
  const AuthPrimaryButton({required this.label, required this.onPressed, this.busy = false, this.icon, super.key});

  final String label;
  final VoidCallback? onPressed;
  final bool busy;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => FilledButton(
    style: FilledButton.styleFrom(
      minimumSize: const Size.fromHeight(54),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700, letterSpacing: 0.2),
    ),
    onPressed: busy ? null : onPressed,
    child: busy
        ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Flexible: teks panjang / huruf besar (aksesibilitas) turun baris, bukan meluap.
              Flexible(child: Text(label, textAlign: TextAlign.center)),
              if (icon != null) ...[const SizedBox(width: 8), Icon(icon, size: 20)],
            ],
          ),
  );
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
    decoration: authInputDecoration(
      context,
      label: widget.label,
      icon: Icons.lock_outline,
      errorText: widget.errorText,
      helperText: widget.helperText,
      suffixIcon: IconButton(
        tooltip: _visible ? 'Sembunyikan password' : 'Tampilkan password',
        icon: Icon(_visible ? Icons.visibility_off_outlined : Icons.visibility_outlined),
        onPressed: () => setState(() => _visible = !_visible),
      ),
    ),
  );
}
