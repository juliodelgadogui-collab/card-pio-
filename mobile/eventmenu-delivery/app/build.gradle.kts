import java.util.Base64

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun envValue(name: String, fallback: String? = null): String =
    System.getenv(name)?.trim().orEmpty().ifBlank { fallback?.let { System.getenv(it)?.trim().orEmpty() }.orEmpty() }
fun envRaw(name: String): String = System.getenv(name).orEmpty()
fun configString(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

val apiBase = envValue("EVENTMENU_DELIVERY_API_BASE_URL").ifBlank { "https://go.gestao2.store/1/" }.trimEnd('/') + "/"
if (!apiBase.startsWith("https://", ignoreCase = true)) throw GradleException("A API do DELYVRE precisa usar HTTPS.")

val firebaseProjectId = envValue("EVENTMENU_FIREBASE_PROJECT_ID", "FCM_PROJECT_ID")
val firebaseAppId = envValue("EVENTMENU_DELIVERY_FIREBASE_APP_ID", "EVENTMENU_FIREBASE_APP_ID")
val firebaseApiKey = envValue("EVENTMENU_FIREBASE_API_KEY")
val firebaseSenderId = envValue("EVENTMENU_FIREBASE_SENDER_ID")
val firebaseConfig = linkedMapOf(
    "EVENTMENU_FIREBASE_PROJECT_ID" to firebaseProjectId,
    "EVENTMENU_DELIVERY_FIREBASE_APP_ID" to firebaseAppId,
    "EVENTMENU_FIREBASE_API_KEY" to firebaseApiKey,
    "EVENTMENU_FIREBASE_SENDER_ID" to firebaseSenderId,
)
val firebaseConfiguredCount = firebaseConfig.values.count { it.isNotBlank() }
val firebaseConfigured = firebaseConfiguredCount == firebaseConfig.size
val firebasePartial = firebaseConfiguredCount in 1 until firebaseConfig.size

val requestedTasks = gradle.startParameter.taskNames.map { it.lowercase() }
val releaseArtifactRequested = requestedTasks.any { it.contains("assemblerelease") || it.contains("bundlerelease") }
val firebaseRequired = envValue("DELYVRE_REQUIRE_FCM").equals("true", ignoreCase = true) || releaseArtifactRequested

if (firebasePartial) {
    val missing = firebaseConfig.filterValues { it.isBlank() }.keys.joinToString(", ")
    throw GradleException("Configuração Firebase incompleta. Faltando: $missing")
}
if (firebaseRequired && !firebaseConfigured) {
    throw GradleException(
        "Firebase Cloud Messaging é obrigatório neste build do DELYVRE. Configure EVENTMENU_FIREBASE_PROJECT_ID, " +
            "EVENTMENU_DELIVERY_FIREBASE_APP_ID, EVENTMENU_FIREBASE_API_KEY e EVENTMENU_FIREBASE_SENDER_ID."
    )
}

val explicitVersionCode = envValue("DELYVRE_VERSION_CODE").toLongOrNull()
if (explicitVersionCode != null && explicitVersionCode !in 1L..2_100_000_000L) {
    throw GradleException("DELYVRE_VERSION_CODE deve estar entre 1 e 2100000000.")
}
val ciRunNumber = envValue("GITHUB_RUN_NUMBER").toLongOrNull()
val defaultVersionCode = 100_000_000L + (ciRunNumber ?: 2L)
if (defaultVersionCode > 2_100_000_000L) throw GradleException("versionCode padrão ultrapassou o limite do Android.")
val appVersionCode = (explicitVersionCode ?: defaultVersionCode).toInt()
val appVersionName = envValue("DELYVRE_VERSION_NAME").ifBlank { "0.3.${ciRunNumber ?: 2L}-dev" }

val releaseKeystorePath = envValue("DELYVRE_RELEASE_KEYSTORE_PATH")
val releaseStorePassword = envRaw("DELYVRE_RELEASE_STORE_PASSWORD")
val releaseKeyAlias = envValue("DELYVRE_RELEASE_KEY_ALIAS")
val releaseKeyPassword = envRaw("DELYVRE_RELEASE_KEY_PASSWORD")
val releaseSigningConfig = linkedMapOf(
    "DELYVRE_RELEASE_KEYSTORE_PATH" to releaseKeystorePath,
    "DELYVRE_RELEASE_STORE_PASSWORD" to releaseStorePassword,
    "DELYVRE_RELEASE_KEY_ALIAS" to releaseKeyAlias,
    "DELYVRE_RELEASE_KEY_PASSWORD" to releaseKeyPassword,
)
val releaseSigningCount = releaseSigningConfig.values.count { it.isNotEmpty() }
val releaseSigningConfigured = releaseSigningCount == releaseSigningConfig.size
val releaseSigningPartial = releaseSigningCount in 1 until releaseSigningConfig.size
val releaseSigningRequired = envValue("DELYVRE_REQUIRE_RELEASE_SIGNING").equals("true", ignoreCase = true) || releaseArtifactRequested

if (releaseSigningPartial) {
    val missing = releaseSigningConfig.filterValues { it.isEmpty() }.keys.joinToString(", ")
    throw GradleException("Configuração de assinatura Release do DELYVRE incompleta. Faltando: $missing")
}
if (releaseSigningRequired && !releaseSigningConfigured) {
    throw GradleException(
        "Assinatura Release do DELYVRE é obrigatória. Configure DELYVRE_RELEASE_KEYSTORE_PATH, " +
            "DELYVRE_RELEASE_STORE_PASSWORD, DELYVRE_RELEASE_KEY_ALIAS e DELYVRE_RELEASE_KEY_PASSWORD."
    )
}
if (releaseSigningConfigured && !file(releaseKeystorePath).isFile) {
    throw GradleException("Keystore Release do DELYVRE não encontrado em DELYVRE_RELEASE_KEYSTORE_PATH.")
}

val delyvreIconBase64 = layout.projectDirectory.file("src/main/icon/delyvre_launcher.b64")
val delyvreLauncherMipmapDir = layout.projectDirectory.dir("src/main/res/mipmap-xxxhdpi")
val generateDelyvreLauncherIcons by tasks.registering {
    group = "build setup"
    description = "Gera os icones oficiais do DELYVRE a partir da arte aprovada."
    inputs.file(delyvreIconBase64)
    outputs.files(
        delyvreLauncherMipmapDir.file("ic_delivery.png"),
        delyvreLauncherMipmapDir.file("ic_delivery_round.png")
    )
    doLast {
        val encoded = delyvreIconBase64.asFile.readText().replace("\n", "").replace("\r", "").trim()
        val iconBytes = Base64.getDecoder().decode(encoded)
        val mipmap = delyvreLauncherMipmapDir.asFile
        mipmap.mkdirs()
        mipmap.resolve("ic_delivery.png").writeBytes(iconBytes)
        mipmap.resolve("ic_delivery_round.png").writeBytes(iconBytes)
    }
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
        buildConfigField("String", "API_BASE_URL", configString(apiBase))
        buildConfigField("boolean", "FIREBASE_ENABLED", firebaseConfigured.toString())
        buildConfigField("String", "FIREBASE_PROJECT_ID", configString(firebaseProjectId))
        buildConfigField("String", "FIREBASE_APP_ID", configString(firebaseAppId))
        buildConfigField("String", "FIREBASE_API_KEY", configString(firebaseApiKey))
        buildConfigField("String", "FIREBASE_SENDER_ID", configString(firebaseSenderId))
    }

    buildFeatures { compose = true; buildConfig = true }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }

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
            if (releaseSigningConfigured) signingConfig = signingConfigs.getByName("release")
        }
    }
}

tasks.matching { it.name == "preBuild" }.configureEach {
    dependsOn(generateDelyvreLauncherIcons)
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
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.11.0")
    implementation("io.coil-kt.coil3:coil-compose:3.3.0")
    implementation("io.coil-kt.coil3:coil-network-okhttp:3.3.0")
    implementation("com.google.firebase:firebase-messaging:25.0.1")
    implementation("com.google.zxing:core:3.5.3")

    implementation(platform("com.mercadopago.android.sdk:sdk-android-bom:1.0.0"))
    implementation("com.mercadopago.android.sdk:sdk-android")
    implementation("com.mercadopago.android.sdk:core-methods")

    testImplementation("junit:junit:4.13.2")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
