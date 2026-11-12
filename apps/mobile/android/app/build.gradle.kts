import java.util.Base64

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Staging Firebase credentials must never be silently bundled into production builds.
if (providers.environmentVariable("EVCS_FIREBASE_ENABLED").getOrElse("0") == "1") {
    apply(plugin = "com.google.gms.google-services")
}

val dartDefines = providers.gradleProperty("dart-defines").getOrElse("").split(",").map {
    String(Base64.getDecoder().decode(it), Charsets.UTF_8)
}
val productionBuild = "APP_ENVIRONMENT=production" in dartDefines
val uploadKeystore = providers.environmentVariable("VTSA_ANDROID_UPLOAD_KEYSTORE").orNull
val uploadStorePassword = providers.environmentVariable("VTSA_ANDROID_UPLOAD_STORE_PASSWORD").orNull
val uploadKeyAlias = providers.environmentVariable("VTSA_ANDROID_UPLOAD_KEY_ALIAS").orNull
val uploadKeyPassword = providers.environmentVariable("VTSA_ANDROID_UPLOAD_KEY_PASSWORD").orNull
require(!productionBuild || listOf(uploadKeystore, uploadStorePassword, uploadKeyAlias, uploadKeyPassword).all { !it.isNullOrBlank() }) {
    "Production Android builds require all four VTSA_ANDROID_UPLOAD_* signing settings."
}

android {
    namespace = "com.vtsa.vtsa_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.vtsa.vtsa_mobile"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        manifestPlaceholders["GOOGLE_MAPS_API_KEY"] = if (productionBuild) {
            if ("MAP_PROVIDER=google" in dartDefines && "GOOGLE_MAPS_READY=true" in dartDefines) {
                providers.environmentVariable("GOOGLE_MAPS_API_KEY").get()
            } else {
                ""
            }
        } else {
            providers.gradleProperty("GOOGLE_MAPS_API_KEY")
                .orElse(providers.environmentVariable("GOOGLE_MAPS_API_KEY"))
                .getOrElse("")
        }
    }

    val playUploadSigning = if (productionBuild) {
        signingConfigs.create("playUpload") {
            storeFile = file(requireNotNull(uploadKeystore))
            storePassword = uploadStorePassword
            keyAlias = uploadKeyAlias
            keyPassword = uploadKeyPassword
        }
    } else {
        null
    }

    buildTypes {
        release {
            signingConfig = playUploadSigning ?: signingConfigs.getByName("debug")
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
