plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun envValue(name: String): String = System.getenv(name)?.trim().orEmpty()
fun buildConfigString(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

val apiBase = envValue("EVENTMENU_API_BASE_URL")
    .ifBlank { "https://go.gestao2.store/1/" }
    .trimEnd('/') + "/"
if (!apiBase.startsWith("https://", ignoreCase = true)) {
    throw GradleException("EVENTMENU_API_BASE_URL precisa usar HTTPS.")
}

val ciBuild = System.getenv("GITHUB_RUN_NUMBER")?.toIntOrNull()

android {
    namespace = "br.com.eventmenu.connect"
    compileSdk = 36

    defaultConfig {
        applicationId = "br.com.eventmenu.connect"
        minSdk = 26
        targetSdk = 36
        versionCode = ciBuild ?: 1
        versionName = if (ciBuild != null) "1.0.$ciBuild" else "1.0.0"
        buildConfigField("String", "API_BASE_URL", buildConfigString(apiBase))
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildTypes {
        release {
            isMinifyEnabled = false
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

    debugImplementation("androidx.compose.ui:ui-tooling")
}
