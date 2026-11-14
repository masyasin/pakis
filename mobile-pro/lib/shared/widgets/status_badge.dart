import 'package:material_ui/material_ui.dart';

import '../../core/theme/pakis_tokens.dart';

/// Label status yang sama di web & mobile (07 §6).
///
/// Satu peta untuk semua domain (absensi, koreksi, perangkat, pegawai): nilai yang sama
/// (`pending`, `rejected`, `active`) memang berlabel dan berwarna sama di 07 §6.
const statusLabels = <String, (String, StatusTone)>{
  // Absensi harian
  'present': ('Hadir', StatusTone.success),
  'late': ('Telat', StatusTone.warning),
  'early_leave': ('Pulang cepat', StatusTone.warning),
  'no_checkout': ('Tidak absen pulang', StatusTone.warning),
  'absent': ('Tidak hadir', StatusTone.danger),
  'holiday': ('Libur', StatusTone.info),
  'off': ('Tidak dijadwalkan', StatusTone.muted),
  // Koreksi & perangkat
  'pending': ('Menunggu', StatusTone.warning),
  'approved': ('Disetujui', StatusTone.success),
  'rejected': ('Ditolak', StatusTone.danger),
  'cancelled': ('Dibatalkan', StatusTone.muted),
  'active': ('Aktif', StatusTone.success),
  'revoked': ('Dicabut', StatusTone.danger),
  'replaced': ('Diganti', StatusTone.muted),
  // Pegawai
  'inactive': ('Nonaktif', StatusTone.muted),
};

class StatusBadge extends StatelessWidget {
  const StatusBadge({required this.status, super.key});

  final String status;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    // Nilai yang belum dikenal tetap tampil (apa adanya) dengan warna netral.
    final (label, tone) = statusLabels[status] ?? (status, StatusTone.muted);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(color: tokens.statusBg[tone], borderRadius: BorderRadius.circular(999)),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelMedium
            ?.copyWith(color: tokens.status[tone], fontWeight: FontWeight.w600),
      ),
    );
  }
}
