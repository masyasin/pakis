import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/listing/listing.dart';
import '../../core/providers.dart';
import '../../core/theme/pakis_tokens.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/paged_list.dart';

/// Notifikasi milik sendiri (05 §5.9).
class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.read,
    this.createdAt,
  });

  factory AppNotification.fromJson(Map<String, Object?> json) => AppNotification(
    id: json['id'] as String? ?? '',
    type: json['type'] as String? ?? '',
    title: json['title'] as String? ?? '',
    body: json['body'] as String? ?? '',
    read: json['read'] == true,
    createdAt: json['created_at'] as String?,
  );

  final String id;
  final String type;
  final String title;
  final String body;
  final bool read;
  final String? createdAt;

  AppNotification markRead() =>
      AppNotification(id: id, type: type, title: title, body: body, read: true, createdAt: createdAt);
}

const notificationsPath = '/api/v1/notifications';

/// Jumlah belum dibaca untuk lonceng = `meta.pagination.total` dengan `filter[read]=unread`.
/// Null bila belum bisa dimuat (offline): lonceng tampil tanpa angka.
final unreadNotificationsProvider = FutureProvider.autoDispose<int?>((ref) async {
  try {
    final response = await ref.watch(apiClientProvider).get(notificationsPath, query: {'filter[read]': 'unread'});
    final pagination = response.meta['pagination'];
    return pagination is Map ? (pagination['total'] as num?)?.toInt() : null;
  } on ApiError {
    return null;
  }
});

/// Lonceng di header beranda: membuka [NotificationsScreen], angka belum dibaca.
class NotificationBell extends ConsumerWidget {
  const NotificationBell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unread = ref.watch(unreadNotificationsProvider).value ?? 0;
    return IconButton(
      tooltip: unread > 0 ? 'Notifikasi, ${Fmt.number(unread)} belum dibaca' : 'Notifikasi',
      onPressed: () async {
        await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const NotificationsScreen()));
        ref.invalidate(unreadNotificationsProvider);
      },
      icon: Badge(
        isLabelVisible: unread > 0,
        label: Text(unread > 99 ? '99+' : Fmt.number(unread)),
        child: const Icon(Icons.notifications_outlined, color: Colors.white),
      ),
    );
  }
}

/// Layar Notifikasi (07 §5): daftar paging, ketuk = tandai dibaca, "Tandai semua dibaca".
class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key});

  @override
  ConsumerState<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  int _version = 0;

  /// Dibaca di sesi ini (tanpa memuat ulang daftar, agar posisi gulir tidak hilang).
  final _readNow = <String>{};

  Future<void> _open(AppNotification n) async {
    if (!n.read && !_readNow.contains(n.id)) {
      setState(() => _readNow.add(n.id));
      try {
        await ref.read(apiClientProvider).post('$notificationsPath/${n.id}/read');
      } on ApiError catch (e) {
        if (mounted) {
          setState(() => _readNow.remove(n.id));
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
        }
        return;
      }
    }
    if (!mounted) {
      return;
    }
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(n.title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              if (n.createdAt != null) Text(_when(n.createdAt!), style: TextStyle(color: context.tokens.muted)),
              const SizedBox(height: 12),
              Text(n.body),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _readAll() async {
    try {
      final response = await ref.read(apiClientProvider).post('$notificationsPath/read-all');
      final marked = (response.object['marked'] as num?)?.toInt() ?? 0;
      if (!mounted) {
        return;
      }
      setState(() {
        _readNow.clear();
        _version++;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            marked == 0 ? 'Semua notifikasi sudah dibaca.' : '${Fmt.number(marked)} notifikasi ditandai dibaca.',
          ),
        ),
      );
    } on ApiError catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  static String _when(String iso) {
    final date = iso.length >= 10 ? Fmt.isoDate(iso.substring(0, 10)) : iso;
    final time = Fmt.wallClock(iso);
    return time == null ? date : '$date $time';
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Notifikasi'),
      actions: [IconButton(tooltip: 'Tandai semua dibaca', onPressed: _readAll, icon: const Icon(Icons.done_all))],
    ),
    body: SafeArea(
      child: Padding(
        padding: const EdgeInsets.only(top: 8),
        child: PagedList<AppNotification>(
          key: ValueKey(_version),
          fetch: (q) => ref
              .read(apiClientProvider)
              .get(notificationsPath, query: q.toQueryParameters())
              .then((r) => ListingPage.fromResponse(r, AppNotification.fromJson)),
          emptyTitle: 'Belum ada notifikasi',
          itemBuilder: (context, raw) {
            final n = _readNow.contains(raw.id) ? raw.markRead() : raw;
            return ListItemCard(
              icon: n.read ? Icons.notifications_none : Icons.notifications_active_outlined,
              tone: n.read ? StatusTone.muted : StatusTone.info,
              title: n.title,
              subtitle: [n.body, if (n.createdAt != null) _when(n.createdAt!)].join('\n'),
              emphasized: !n.read,
              onTap: () => _open(n),
            );
          },
        ),
      ),
    ),
  );
}
