import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

val prepareCampusLfApiBridge by tasks.registering {
    group = "campuslf"
    description = "Forwards the local Laravel API to connected Android devices."

    doLast {
        val localPropertiesFile = rootProject.file("local.properties")
        if (!localPropertiesFile.isFile) {
            logger.lifecycle("CampusLF: Android SDK path is unavailable; skipping the local API bridge.")
            return@doLast
        }

        val localProperties = Properties()
        localPropertiesFile.inputStream().use { localProperties.load(it) }
        val sdkDirectory = localProperties.getProperty("sdk.dir")
            ?: throw GradleException("Android SDK path is missing from android/local.properties.")
        val adbName = if (System.getProperty("os.name").startsWith("Windows", ignoreCase = true)) {
            "adb.exe"
        } else {
            "adb"
        }
        val adb = File(sdkDirectory, "platform-tools/$adbName")
        if (!adb.isFile) {
            throw GradleException("Android Debug Bridge was not found at ${adb.absolutePath}.")
        }

        val devicesProcess = ProcessBuilder(adb.absolutePath, "devices")
            .redirectErrorStream(true)
            .start()
        val devicesOutput = devicesProcess.inputStream.bufferedReader().readText()
        if (devicesProcess.waitFor() != 0) {
            throw GradleException("Could not list Android devices: $devicesOutput")
        }

        val deviceIds = devicesOutput.lineSequence().mapNotNull { line ->
            Regex("""^\s*(\S+)\s+device\s*$""").find(line)?.groupValues?.get(1)
        }.toList()

        if (deviceIds.isEmpty()) {
            logger.lifecycle("CampusLF: No authorized Android device found; skipping the local API bridge.")
            return@doLast
        }

        deviceIds.forEach { deviceId ->
            val bridgeProcess = ProcessBuilder(
                adb.absolutePath,
                "-s",
                deviceId,
                "reverse",
                "tcp:18000",
                "tcp:8000",
            ).redirectErrorStream(true).start()
            val bridgeOutput = bridgeProcess.inputStream.bufferedReader().readText()
            if (bridgeProcess.waitFor() != 0) {
                throw GradleException("Could not forward the Laravel API to Android device $deviceId: $bridgeOutput")
            }
            logger.lifecycle("CampusLF: Forwarded device port 18000 to local API port 8000 for $deviceId.")
        }
    }
}

android {
    namespace = "edu.tmc.tmc_campuslf_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "edu.tmc.tmc_campuslf_mobile"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        debug {
            manifestPlaceholders["usesCleartextTraffic"] = "true"
        }
        release {
            manifestPlaceholders["usesCleartextTraffic"] = "false"
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

tasks.configureEach {
    if (name == "preDebugBuild") {
        dependsOn(prepareCampusLfApiBridge)
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
