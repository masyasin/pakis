import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Kunci rilis (SEC-067): dari android/key.properties (lokal, di-ignore git) atau variabel
// lingkungan CI (secret). Keystore produksi dibuat & dicadangkan offline oleh Owner.
val keystoreProperties = Properties().apply {
    val file = rootProject.file("key.properties")
    if (file.exists()) {
        FileInputStream(file).use { load(it) }
    }
}

fun signingValue(property: String, env: String): String? =
    keystoreProperties.getProperty(property) ?: System.getenv(env)?.takeIf { it.isNotEmpty() }

val releaseStoreFile = signingValue("storeFile", "PAKIS_KEYSTORE_PATH")

// Hanya untuk mengukur/menguji build rilis (CI ukuran APK, uji lokal). APK hasilnya bertanda
// tangan DEBUG dan tidak boleh dibagikan ke pegawai.
val debugSignRelease = System.getenv("PAKIS_DEBUG_SIGN_RELEASE") == "true"

android {
    namespace = "com.pakisjayaabadi.pakispro"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // Final (dikonfirmasi Mas, 15 Nov 2026). Jangan diganti: HP pegawai yang sudah memasang
        // Pakis Pro tidak bisa memperbarui ke APK dengan applicationId lain (harus pasang ulang).
        applicationId = "com.pakisjayaabadi.pakispro"
        // Android 8.0+ (NFR-008, 03 §5).
        minSdk = 26
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (releaseStoreFile != null) {
            create("release") {
                storeFile = file(releaseStoreFile)
                storePassword = signingValue("storePassword", "PAKIS_KEYSTORE_PASSWORD")
                keyAlias = signingValue("keyAlias", "PAKIS_KEY_ALIAS")
                keyPassword = signingValue("keyPassword", "PAKIS_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            // R8 (minify + shrink resources) sudah diaktifkan Flutter untuk rilis; obfuscation
            // Dart lewat `--obfuscate --split-debug-info` (lihat mobile-pro/CLAUDE.md "Rilis APK").
            signingConfig = when {
                releaseStoreFile != null -> signingConfigs.getByName("release")
                debugSignRelease -> signingConfigs.getByName("debug")
                else -> null
            }
        }
    }
}

// Build rilis tanpa kunci rilis harus gagal jelas, bukan menghasilkan APK tak bertanda tangan
// atau bertanda tangan debug yang tidak sengaja terbagikan.
tasks.configureEach {
    if (name.matches(Regex("(assemble|bundle|package)Release"))) {
        doFirst {
            if (releaseStoreFile == null && !debugSignRelease) {
                throw GradleException(
                    "Kunci rilis tidak ada. Isi android/key.properties atau PAKIS_KEYSTORE_* " +
                        "(lihat mobile-pro/CLAUDE.md \"Rilis APK\"). Untuk uji lokal saja: PAKIS_DEBUG_SIGN_RELEASE=true.",
                )
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
