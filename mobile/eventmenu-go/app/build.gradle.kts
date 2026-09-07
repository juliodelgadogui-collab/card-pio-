plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

// Servidor oficial do EventMenu GO. Não existe configuração de servidor na interface do app.
val apiBase = "https://go.gestao2.store/1/"
val ciBuildNumber = System.getenv("GITHUB_RUN_NUMBER")?.toIntOrNull()
val appVersionCode = ciBuildNumber ?: 3
val appVersionName = if (ciBuildNumber != null) "0.2.$ciBuildNumber" else "0.2.0"

fun envValue(primary: String, fallback: String? = null): String =
    System.getenv(primary)?.trim().orEmpty().ifBlank { fallback?.let { System.getenv(it)?.trim().orEmpty() }.orEmpty() }

fun buildConfigString(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

val firebaseProjectId = envValue("EVENTMENU_FIREBASE_PROJECT_ID", "FCM_PROJECT_ID")
val firebaseAppId = envValue("EVENTMENU_FIREBASE_APP_ID")
val firebaseApiKey = envValue("EVENTMENU_FIREBASE_API_KEY")
val firebaseSenderId = envValue("EVENTMENU_FIREBASE_SENDER_ID")

android {
    namespace = "br.com.eventmenu.go"
    compileSdk = 36

    defaultConfig {
        applicationId = "br.com.eventmenu.go"
        minSdk = 23
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName
        buildConfigField("String", "API_BASE_URL", "\"$apiBase\"")
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

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
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
