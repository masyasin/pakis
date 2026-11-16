import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/listing/listing.dart';
import '../../core/providers.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/paged_list.dart';
import 'field_trip.dart';
import 'field_trip_detail_screen.dart';
import 'field_trip_form_screen.dart';

/// Tugas luar kota milik sendiri (`my_field_trips`) + "Ajukan". Dibuka dari kartu di tab
/// Presensi, bukan tab bawah: tab sudah 5 untuk penyetuju (360 dp), dan tugas menentukan
/// lokasi presensi sehingga paling dekat dengan beranda presensi.
class MyFieldTripsScreen extends ConsumerStatefulWidget {
  const MyFieldTripsScreen({super.key});

  static Future<void> open(BuildContext context) =>
      Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const MyFieldTripsScreen()));

  @override
  ConsumerState<MyFieldTripsScreen> createState() => _MyFieldTripsScreenState();
}

class _MyFieldTripsScreenState extends ConsumerState<MyFieldTripsScreen> {
  int _version = 0;

  Future<void> _submit() async {
    final created = await FieldTripFormScreen.open(context);
    if (created != null && mounted) {
      setState(() => _version++);
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Pengajuan ${created.number} terkirim, menunggu persetujuan.')));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Tugas luar kota')),
    floatingActionButton: FloatingActionButton.extended(
      onPressed: _submit,
      icon: const Icon(Icons.add),
      label: const Text('Ajukan'),
    ),
    body: SafeArea(
      child: Padding(
        padding: const EdgeInsets.only(top: 8),
        child: PagedList<FieldTrip>(
          key: ValueKey(_version),
          fetch: (q) => ref
              .read(apiClientProvider)
              .get(fieldTripsPath, query: q.toQueryParameters())
              .then((r) => ListingPage.fromResponse(r, FieldTrip.fromJson)),
          emptyTitle: 'Belum ada tugas luar kota',
          emptyMessage: 'Akan ke luar kota atau menginap untuk tugas? Ketuk "Ajukan".',
          itemBuilder: (context, t) => FieldTripTile(
            trip: t,
            onTap: () => FieldTripDetailScreen.open(context, trip: t),
          ),
        ),
      ),
    ),
  );
}

class FieldTripTile extends StatelessWidget {
  const FieldTripTile({required this.trip, required this.onTap, this.showEmployees = false, super.key});

  final FieldTrip trip;
  final VoidCallback onTap;
  final bool showEmployees;

  @override
  Widget build(BuildContext context) => ListItemCard(
    icon: Icons.luggage_outlined,
    title: showEmployees && trip.employees.isNotEmpty ? trip.employees.first.split(' · ').first : trip.destination,
    subtitle: [
      if (showEmployees) trip.destination,
      fieldTripPeriod(trip),
      '${trip.number} · ${trip.ruleLabel}',
    ].join('\n'),
    status: trip.status,
    onTap: onTap,
  );
}
