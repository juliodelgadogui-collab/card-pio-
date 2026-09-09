plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun envValue(primary: String, fallback: String? = null): String =
    System.getenv(primary)?.trim().orEmpty().ifBlank { fallback?.let { System.getenv(it)?.trim().orEmpty() }.orEmpty() }

fun buildConfigString(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

// Servidor oficial por padrão, mas configurável somente no ambiente de compilação.
val apiBase = envValue("EVENTMENU_API_BASE_URL")
    .ifBlank { "https://go.gestao2.store/1/" }
    .trimEnd('/') + "/"
if (!apiBase.startsWith("https://", ignoreCase = true)) {
    throw GradleException("EVENTMENU_API_BASE_URL precisa usar HTTPS.")
}

val releaseRequested = gradle.startParameter.taskNames.any { it.contains("release", ignoreCase = true) }
val ciBuildNumber = System.getenv("GITHUB_RUN_NUMBER")?.toIntOrNull()
val explicitVersionCodeRaw = envValue("EVENTMENU_VERSION_CODE")
val explicitVersionCode = explicitVersionCodeRaw.toIntOrNull()
if (explicitVersionCodeRaw.isNotBlank() && explicitVersionCode == null) {
    throw GradleException("EVENTMENU_VERSION_CODE precisa ser um número inteiro.")
}
val appVersionCode = explicitVersionCode ?: ciBuildNumber ?: 3
val appVersionName = envValue("EVENTMENU_VERSION_NAME").ifBlank {
    if (releaseRequested) "1.0.0" else if (ciBuildNumber != null) "0.2.$ciBuildNumber" else "0.2.0"
}

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

val releaseKeystorePath = envValue("EVENTMENU_RELEASE_KEYSTORE_PATH")
val releaseStorePassword = envValue("EVENTMENU_RELEASE_STORE_PASSWORD")
val releaseKeyAlias = envValue("EVENTMENU_RELEASE_KEY_ALIAS")
val releaseKeyPassword = envValue("EVENTMENU_RELEASE_KEY_PASSWORD")
val releaseSigning = linkedMapOf(
    "EVENTMENU_RELEASE_KEYSTORE_PATH" to releaseKeystorePath,
    "EVENTMENU_RELEASE_STORE_PASSWORD" to releaseStorePassword,
    "EVENTMENU_RELEASE_KEY_ALIAS" to releaseKeyAlias,
    "EVENTMENU_RELEASE_KEY_PASSWORD" to releaseKeyPassword,
)
val releaseSigningCount = releaseSigning.values.count { it.isNotBlank() }
val releaseSigningConfigured = releaseSigningCount == releaseSigning.size
if (releaseSigningCount in 1 until releaseSigning.size) {
    val missing = releaseSigning.filterValues { it.isBlank() }.keys.joinToString(", ")
    throw GradleException("Assinatura Release incompleta. Faltando: $missing")
}
if (releaseRequested && !releaseSigningConfigured) {
    throw GradleException(
        "Build Release exige keystore permanente. Configure EVENTMENU_RELEASE_KEYSTORE_PATH, " +
            "EVENTMENU_RELEASE_STORE_PASSWORD, EVENTMENU_RELEASE_KEY_ALIAS e EVENTMENU_RELEASE_KEY_PASSWORD."
    )
}
if (releaseSigningConfigured && !file(releaseKeystorePath).isFile) {
    throw GradleException("Keystore Release não encontrado no caminho informado.")
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
                enableV1Signing = true
                enableV2Signing = true
                enableV3Signing = true
                enableV4Signing = true
            }
        }
    }

    buildTypes {
        release {
            isDebuggable = false
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            if (releaseSigningConfigured) signingConfig = signingConfigs.getByName("release")
        }
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2026.06.00")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    // AGP 9.1 suporta compileSdk 36. As versões seguintes de Core/Lifecycle
    // passaram a exigir API 37; mantenha estes pins até a migração coordenada do AGP.
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
