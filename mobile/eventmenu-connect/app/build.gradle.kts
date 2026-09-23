plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun envValue(name: String): String = System.getenv(name)?.trim().orEmpty()
fun envRaw(name: String): String = System.getenv(name).orEmpty()
fun buildConfigString(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

val apiBase = envValue("EVENTMENU_API_BASE_URL")
    .ifBlank { "https://go.gestao2.store/1/" }
    .trimEnd('/') + "/"
if (!apiBase.startsWith("https://", ignoreCase = true)) {
    throw GradleException("EVENTMENU_API_BASE_URL precisa usar HTTPS.")
}

val requestedTasks = gradle.startParameter.taskNames.map { it.lowercase() }
val releaseArtifactRequested = requestedTasks.any { it.contains("assemblerelease") || it.contains("bundlerelease") }
val ciBuild = System.getenv("GITHUB_RUN_NUMBER")?.toIntOrNull()
val explicitVersionCode = envValue("EVENTMENU_CONNECT_VERSION_CODE").toIntOrNull()
if (explicitVersionCode != null && explicitVersionCode !in 1..2_100_000_000) {
    throw GradleException("EVENTMENU_CONNECT_VERSION_CODE deve estar entre 1 e 2100000000.")
}
val appVersionCode = explicitVersionCode ?: ciBuild ?: 1
val appVersionName = envValue("EVENTMENU_CONNECT_VERSION_NAME").ifBlank {
    if (ciBuild != null) "1.1.$ciBuild" else "1.1.0"
}

val releaseKeystorePath = envValue("EVENTMENU_CONNECT_RELEASE_KEYSTORE_PATH")
val releaseStorePassword = envRaw("EVENTMENU_CONNECT_RELEASE_STORE_PASSWORD")
val releaseKeyAlias = envValue("EVENTMENU_CONNECT_RELEASE_KEY_ALIAS")
val releaseKeyPassword = envRaw("EVENTMENU_CONNECT_RELEASE_KEY_PASSWORD")
val releaseSigning = linkedMapOf(
    "EVENTMENU_CONNECT_RELEASE_KEYSTORE_PATH" to releaseKeystorePath,
    "EVENTMENU_CONNECT_RELEASE_STORE_PASSWORD" to releaseStorePassword,
    "EVENTMENU_CONNECT_RELEASE_KEY_ALIAS" to releaseKeyAlias,
    "EVENTMENU_CONNECT_RELEASE_KEY_PASSWORD" to releaseKeyPassword,
)
val releaseSigningCount = releaseSigning.values.count { it.isNotEmpty() }
val releaseSigningConfigured = releaseSigningCount == releaseSigning.size
val releaseSigningPartial = releaseSigningCount in 1 until releaseSigning.size

if (releaseSigningPartial) {
    val missing = releaseSigning.filterValues { it.isEmpty() }.keys.joinToString(", ")
    throw GradleException("Assinatura Release do EventMenu Connect incompleta. Faltando: $missing")
}
if (releaseArtifactRequested && !releaseSigningConfigured) {
    throw GradleException(
        "Release do EventMenu Connect exige assinatura oficial. Configure EVENTMENU_CONNECT_RELEASE_KEYSTORE_PATH, " +
            "EVENTMENU_CONNECT_RELEASE_STORE_PASSWORD, EVENTMENU_CONNECT_RELEASE_KEY_ALIAS e EVENTMENU_CONNECT_RELEASE_KEY_PASSWORD."
    )
}
if (releaseSigningConfigured && !file(releaseKeystorePath).isFile) {
    throw GradleException("Keystore Release do EventMenu Connect não encontrado.")
}

android {
    namespace = "br.com.eventmenu.connect"
    compileSdk = 36
    ndkVersion = "28.0.13004108"

    defaultConfig {
        applicationId = "br.com.eventmenu.connect"
        minSdk = 26
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName
        buildConfigField("String", "API_BASE_URL", buildConfigString(apiBase))

        ndk {
            abiFilters += listOf("arm64-v8a")
        }
        externalNativeBuild {
            cmake {
                arguments += listOf("-DANDROID_STL=c++_shared")
                cppFlags += "-std=c++20"
            }
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    externalNativeBuild {
        cmake {
            path = file("CMakeLists.txt")
            version = "3.22.1"
        }
    }

    sourceSets.getByName("main") {
        jniLibs.srcDir("libnode/bin")
    }

    packaging {
        jniLibs.useLegacyPackaging = false
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
            if (releaseSigningConfigured) signingConfig = signingConfigs.getByName("release")
        }
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2026.06.00")
    implementation(composeBom)

    implementation("androidx.core:core-ktx:1.17.0")
    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.10.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.11.0")
    implementation("com.google.zxing:core:3.5.3")
    implementation("com.googlecode.libphonenumber:libphonenumber:9.0.39")

    debugImplementation("androidx.compose.ui:ui-tooling")
}
