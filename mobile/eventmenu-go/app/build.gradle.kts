plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun envValue(primary: String, fallback: String? = null): String =
    System.getenv(primary)?.trim().orEmpty().ifBlank { fallback?.let { System.getenv(it)?.trim().orEmpty() }.orEmpty() }

fun envRaw(name: String): String = System.getenv(name).orEmpty()

fun buildConfigString(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

val apiBase = envValue("EVENTMENU_API_BASE_URL")
    .ifBlank { "https://go.gestao2.store/1/" }
    .trimEnd('/') + "/"
if (!apiBase.startsWith("https://", ignoreCase = true)) {
    throw GradleException("EVENTMENU_API_BASE_URL precisa usar HTTPS.")
}

// Android versionCode must be positive and must not exceed the Play/Android limit.
// Never clamp an oversized CI identifier: clamping made multiple builds receive the
// same versionCode and prevented reliable upgrades. CI now injects a bounded,
// monotonically increasing code instead of GITHUB_RUN_ID.
val requestedVersionCode = envValue("EVENTMENU_VERSION_CODE").toLongOrNull()
if (requestedVersionCode != null && requestedVersionCode !in 1L..2_100_000_000L) {
    throw GradleException("EVENTMENU_VERSION_CODE deve estar entre 1 e 2100000000.")
}
val appVersionCode = requestedVersionCode?.toInt() ?: 256
val ciVersionName = envValue("EVENTMENU_VERSION_NAME")
val appVersionName = ciVersionName.ifBlank { "0.2.56-dev" }

val firebaseProjectId = envValue("EVENTMENU_FIREBASE_PROJECT_ID", "FCM_PROJECT_ID")
val firebaseAppId = envValue("EVENTMENU_FIREBASE_APP_ID")
val firebaseApiKey = envValue("EVENTMENU_FIREBASE_API_KEY")
val firebaseSenderId = envValue("EVENTMENU_FIREBASE_SENDER_ID")
val firebaseConfig = linkedMapOf(
    "EVENTMENU_FIREBASE_PROJECT_ID" to firebaseProjectId,
    "EVENTMENU_FIREBASE_APP_ID" to firebaseAppId,
    "EVENTMENU_FIREBASE_API_KEY" to firebaseApiKey,
    "EVENTMENU_FIREBASE_SENDER_ID" to firebaseSenderId,
)
val firebaseConfiguredCount = firebaseConfig.values.count { it.isNotBlank() }
val firebaseEnabled = firebaseConfiguredCount == firebaseConfig.size
val firebasePartial = firebaseConfiguredCount in 1 until firebaseConfig.size
val releaseRequested = gradle.startParameter.taskNames.any { it.contains("release", ignoreCase = true) }
val firebaseRequired = envValue("EVENTMENU_REQUIRE_FCM").equals("true", ignoreCase = true) || releaseRequested

if (firebasePartial) {
    val missing = firebaseConfig.filterValues { it.isBlank() }.keys.joinToString(", ")
    throw GradleException("Configuração Firebase incompleta. Faltando: $missing")
}
if (firebaseRequired && !firebaseEnabled) {
    throw GradleException(
        "Firebase Cloud Messaging é obrigatório neste build. Configure EVENTMENU_FIREBASE_PROJECT_ID, " +
            "EVENTMENU_FIREBASE_APP_ID, EVENTMENU_FIREBASE_API_KEY e EVENTMENU_FIREBASE_SENDER_ID."
    )
}

// Production signing is supplied only by the build environment/GitHub Secrets.
// No keystore or password belongs in source control.
val releaseKeystorePath = envValue("EVENTMENU_RELEASE_KEYSTORE_PATH")
val releaseStorePassword = envRaw("EVENTMENU_RELEASE_STORE_PASSWORD")
val releaseKeyAlias = envValue("EVENTMENU_RELEASE_KEY_ALIAS")
val releaseKeyPassword = envRaw("EVENTMENU_RELEASE_KEY_PASSWORD")
val releaseSigningConfig = linkedMapOf(
    "EVENTMENU_RELEASE_KEYSTORE_PATH" to releaseKeystorePath,
    "EVENTMENU_RELEASE_STORE_PASSWORD" to releaseStorePassword,
    "EVENTMENU_RELEASE_KEY_ALIAS" to releaseKeyAlias,
    "EVENTMENU_RELEASE_KEY_PASSWORD" to releaseKeyPassword,
)
val releaseSigningCount = releaseSigningConfig.values.count { it.isNotEmpty() }
val releaseSigningConfigured = releaseSigningCount == releaseSigningConfig.size
val releaseSigningPartial = releaseSigningCount in 1 until releaseSigningConfig.size
val releaseSigningRequired = releaseRequested || envValue("EVENTMENU_REQUIRE_RELEASE_SIGNING").equals("true", ignoreCase = true)

if (releaseSigningPartial) {
    val missing = releaseSigningConfig.filterValues { it.isEmpty() }.keys.joinToString(", ")
    throw GradleException("Configuração de assinatura release incompleta. Faltando: $missing")
}
if (releaseSigningRequired && !releaseSigningConfigured) {
    throw GradleException(
        "Assinatura release é obrigatória. Configure EVENTMENU_RELEASE_KEYSTORE_PATH, " +
            "EVENTMENU_RELEASE_STORE_PASSWORD, EVENTMENU_RELEASE_KEY_ALIAS e EVENTMENU_RELEASE_KEY_PASSWORD."
    )
}
if (releaseSigningConfigured && !file(releaseKeystorePath).isFile) {
    throw GradleException("Keystore release não encontrado em EVENTMENU_RELEASE_KEYSTORE_PATH.")
}

android {
    namespace = "br.com.eventmenu.go"
    compileSdk = 36

    defaultConfig {
        applicationId = "br.com.eventmenu.go"
        minSdk = 23
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName
        buildConfigField("String", "API_BASE_URL", buildConfigString(apiBase))
        buildConfigField("boolean", "FIREBASE_ENABLED", firebaseEnabled.toString())
        buildConfigField("String", "FIREBASE_PROJECT_ID", buildConfigString(firebaseProjectId))
        buildConfigField("String", "FIREBASE_APP_ID", buildConfigString(firebaseAppId))
        buildConfigField("String", "FIREBASE_API_KEY", buildConfigString(firebaseApiKey))
        buildConfigField("String", "FIREBASE_SENDER_ID", buildConfigString(firebaseSenderId))
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    signingConfigs {
        if (releaseSigningConfigured) {
            create("release") {
                storeFile = file(releaseKeystorePath)
                storePassword = releaseStorePassword
                keyAlias = releaseKeyAlias
                keyPassword = releaseKeyPassword
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            if (releaseSigningConfigured) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2026.06.00")
    implementation(composeBom)
    androidTestImplementation(composeBom)
    implementation("androidx.core:core-ktx:1.17.0")
    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.10.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.10.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.biometric:biometric:1.1.0")
    implementation("androidx.work:work-runtime-ktx:2.10.1")
    implementation("com.google.android.gms:play-services-code-scanner:16.1.0")
    implementation("com.google.firebase:firebase-messaging:25.0.1")
    implementation("com.google.zxing:core:3.5.4")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.11.0")
    testImplementation("junit:junit:4.13.2")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
