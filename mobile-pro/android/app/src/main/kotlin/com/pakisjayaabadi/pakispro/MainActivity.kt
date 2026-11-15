package com.pakisjayaabadi.pakispro

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.security.MessageDigest

/**
 * Kanal native kecil `pakis_pro/device` (keputusan Mas, 15 Nov 2026): menggantikan paket
 * `crypto`/`url_launcher` yang tidak ada di 03 §5, dan ANDROID_ID yang tidak lagi disediakan
 * device_info_plus. Rumus device_uid tetap di Dart (lib/core/device/device_id.dart) agar teruji.
 */
class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL).setMethodCallHandler { call, result ->
            when (call.method) {
                // Settings.Secure.ANDROID_ID: tetap selama aplikasi bertanda tangan sama di
                // pengguna & HP yang sama (Android 8+), berubah setelah factory reset.
                "androidId" -> result.success(
                    Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID),
                )

                "sha256Hex" -> {
                    val input = call.argument<String>("input")
                    if (input == null) {
                        result.error("BAD_ARGS", "input wajib diisi", null)
                    } else {
                        result.success(sha256Hex(input))
                    }
                }

                // Model & versi Android untuk halaman Perangkat admin (T-032, pengganti device_info_plus).
                "deviceInfo" -> result.success(
                    mapOf(
                        "model" to listOf(Build.MANUFACTURER, Build.MODEL)
                            .filter { it.isNotBlank() }
                            .distinct()
                            .joinToString(" ")
                            .take(100),
                        "os_version" to "Android ${Build.VERSION.RELEASE}".take(40),
                    ),
                )

                // Folder privat yang tidak ikut cadangan Android: DB antrean offline (T-042).
                "noBackupDir" -> result.success(noBackupFilesDir.absolutePath)

                // Hanya https: URL unduh APK berasal dari setting server, jangan sampai membuka
                // skema lain (intent://, file://) bila nilainya dirusak.
                "openUrl" -> {
                    val uri = call.argument<String>("url")?.let(Uri::parse)
                    if (uri == null || uri.scheme != "https") {
                        result.success(false)
                    } else {
                        try {
                            startActivity(Intent(Intent.ACTION_VIEW, uri))
                            result.success(true)
                        } catch (e: ActivityNotFoundException) {
                            result.success(false)
                        }
                    }
                }

                else -> result.notImplemented()
            }
        }
    }

    private fun sha256Hex(input: String): String =
        MessageDigest.getInstance("SHA-256")
            .digest(input.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }

    companion object {
        private const val CHANNEL = "pakis_pro/device"
    }
}
