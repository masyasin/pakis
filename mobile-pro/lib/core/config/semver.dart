/// Semver `MAJOR.MINOR.PATCH` untuk gerbang versi (BR-027).
///
/// Cermin `App\Domain\Settings\Validation\Semver` di backend: pola sama, dan nilai tidak sah
/// diperlakukan sebagai `0.0.0`, sehingga HP dan server mengambil keputusan yang sama.
abstract final class Semver {
  static final _pattern = RegExp(r'^(0|[1-9]\d{0,3})\.(0|[1-9]\d{0,3})\.(0|[1-9]\d{0,3})$');

  static bool isValid(String? version) => version != null && _pattern.hasMatch(version);

  /// -1 bila [a] < [b], 0 bila sama, 1 bila [a] > [b].
  static int compare(String a, String b) {
    final pa = _parts(a);
    final pb = _parts(b);
    for (var i = 0; i < 3; i++) {
      if (pa[i] != pb[i]) {
        return pa[i] < pb[i] ? -1 : 1;
      }
    }
    return 0;
  }

  static bool lessThan(String a, String b) => compare(a, b) < 0;

  static List<int> _parts(String version) =>
      isValid(version) ? version.split('.').map(int.parse).toList() : const [0, 0, 0];
}
