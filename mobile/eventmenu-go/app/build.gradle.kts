plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

// Servidor oficial do EventMenu GO. Não existe configuração de servidor na interface do app.
val apiBase = "https://go.gestao2.store/1/"

android {
    namespace = "br.com.eventmenu.go"
    compileSdk = 36

    defaultConfig {
        applicationId = "br.com.eventmenu.go"
        minSdk = 23
        targetSdk = 36
        versionCode = 2
        versionName = "0.1.1"
        buildConfigField("String", "API_BASE_URL", "\"$apiBase\"")
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
    implementation("com.google.zxing:core:3.5.4")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.11.0")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
