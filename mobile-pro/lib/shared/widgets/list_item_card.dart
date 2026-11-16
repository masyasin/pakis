import 'package:material_ui/material_ui.dart';

import '../../core/theme/pakis_tokens.dart';
import 'status_badge.dart';

/// Item daftar mobile (pola prototipe, mobile-pro/CLAUDE.md): ikon dalam kotak lembut bernada
/// status di kiri, judul tebal, keterangan abu-abu, [StatusBadge] di kanan.
class ListItemCard extends StatelessWidget {
  const ListItemCard({
    required this.icon,
    required this.title,
    super.key,
    this.subtitle,
    this.status,
    this.tone,
    this.onTap,
    this.emphasized = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;

  /// Kunci status 07 §6 untuk badge (null = tanpa badge).
  final String? status;

  /// Nada kotak ikon; bawaan mengikuti [status].
  final StatusTone? tone;
  final VoidCallback? onTap;

  /// Mis. notifikasi belum dibaca: judul lebih tegas + garis tepi primer.
  final bool emphasized;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final t = tone ?? statusLabels[status]?.$2 ?? StatusTone.muted;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      child: Material(
        color: tokens.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: emphasized ? tokens.primary : tokens.border),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 64),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  DecoratedBox(
                    decoration: BoxDecoration(color: tokens.statusBg[t], borderRadius: BorderRadius.circular(10)),
                    child: Padding(
                      padding: const EdgeInsets.all(8),
                      child: Icon(icon, color: tokens.status[t]),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          style: text.titleSmall?.copyWith(fontWeight: emphasized ? FontWeight.w800 : FontWeight.w700),
                        ),
                        if (subtitle != null && subtitle!.isNotEmpty)
                          Text(subtitle!, style: text.bodyMedium?.copyWith(color: tokens.muted)),
                      ],
                    ),
                  ),
                  if (status != null) ...[const SizedBox(width: 8), StatusBadge(status: status!)],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Baris label–nilai di layar detail.
class DetailRow extends StatelessWidget {
  const DetailRow({required this.label, required this.value, super.key});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(label, style: text.bodyMedium?.copyWith(color: context.tokens.muted)),
          ),
          Expanded(child: Text(value, style: text.bodyMedium)),
        ],
      ),
    );
  }
}
