plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun envValue(primary: String, fallback: String? = null): String =
    System.getenv(primary)?.trim().orEmpty().ifBlank { fallback?.let { System.getenv(it)?.trim().orEmpty() }.orEmpty() }

fun quoted(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

val releaseRequested = gradle.startParameter.taskNames.any { it.contains("release", ignoreCase = true) }
val allowUnsignedRelease = envValue("EVENTMENU_ALLOW_UNSIGNED_RELEASE").equals("true", ignoreCase = true)
val apiBase = envValue("EVENTMENU_DELIVERY_API_BASE_URL", "EVENTMENU_API_BASE_URL")
    .ifBlank { "https://go.gestao2.store/1/" }
    .trimEnd('/') + "/"
if (!apiBase.startsWith("https://", ignoreCase = true)) {
    throw GradleException("EVENTMENU_DELIVERY_API_BASE_URL precisa usar HTTPS.")
}

val explicitVersionCodeRaw = envValue("EVENTMENU_DELIVERY_VERSION_CODE", "EVENTMENU_VERSION_CODE")
val explicitVersionCodeLong = explicitVersionCodeRaw.toLongOrNull()
if (explicitVersionCodeRaw.isNotBlank() && (explicitVersionCodeLong == null || explicitVersionCodeLong !in 1..Int.MAX_VALUE.toLong())) {
    throw GradleException("VersionCode do DELYVRE precisa ser inteiro positivo até ${Int.MAX_VALUE}.")
}
val ciBuildNumber = System.getenv("GITHUB_RUN_NUMBER")?.toIntOrNull()
val appVersionCode = explicitVersionCodeLong?.toInt() ?: if (releaseRequested) 10001 else (ciBuildNumber?.let { 1000 + it } ?: 1)
val appVersionName = envValue("EVENTMENU_DELIVERY_VERSION_NAME", "EVENTMENU_VERSION_NAME").ifBlank {
    if (releaseRequested) "1.0.1" else ciBuildNumber?.let { "0.1.${it}-dev" } ?: "0.1.0-dev"
}
val suiteRelease = envValue("EVENTMENU_RELEASE").ifBlank { if (releaseRequested) "1.0.1" else "dev" }
val buildDateUtc = envValue("EVENTMENU_BUILD_DATE_UTC").ifBlank { "unknown" }

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
if (releaseRequested && !releaseSigningConfigured && !allowUnsignedRelease) {
    throw GradleException("Build Release do DELYVRE exige o keystore permanente configurado por ambiente/secrets.")
}
if (releaseSigningConfigured && !file(releaseKeystorePath).isFile) {
    throw GradleException("Keystore Release não encontrado no caminho informado.")
}

android {
    namespace = "br.com.eventmenu.delivery"
    compileSdk = 36

    defaultConfig {
        applicationId = "br.com.eventmenu.delivery"
        minSdk = 23
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName
        buildConfigField("String", "API_BASE_URL", quoted(apiBase))
        buildConfigField("String", "EVENTMENU_RELEASE", quoted(suiteRelease))
        buildConfigField("String", "BUILD_DATE_UTC", quoted(buildDateUtc))
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
    implementation("androidx.core:core-ktx:1.17.0")
    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.10.0")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.10.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.10.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.11.0")
    testImplementation("junit:junit:4.13.2")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
