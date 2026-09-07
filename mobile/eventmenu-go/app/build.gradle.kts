plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

// Servidor oficial do EventMenu GO. Não existe configuração de servidor na interface do app.
val apiBase = "https://go.gestao2.store/1/"
val ciBuildNumber = System.getenv("GITHUB_RUN_NUMBER")?.toIntOrNull()
val appVersionCode = ciBuildNumber ?: 3
val appVersionName = if (ciBuildNumber != null) "0.2.$ciBuildNumber" else "0.2.0"

android {
    namespace = "br.com.eventmenu.go"
    compileSdk = 36

    defaultConfig {
        applicationId = "br.com.eventmenu.go"
        // A variante sem Tap to Pay continua disponível para aparelhos antigos.
        minSdk = 23
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName
        buildConfigField("String", "API_BASE_URL", "\"$apiBase\"")
    }

    flavorDimensions += "payments"
    productFlavors {
        create("sumup") {
            dimension = "payments"
            // SumUp Tap-to-Pay 1.1.6 exige Android 11 / API 30 ou superior.
            minSdk = 30
            buildConfigField("boolean", "SUMUP_TAP_TO_PAY", "true")
        }
        create("nosumup") {
            dimension = "payments"
            versionNameSuffix = "-sem-nfc"
            buildConfigField("boolean", "SUMUP_TAP_TO_PAY", "false")
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        isCoreLibraryDesugaringEnabled = true
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildTypes {
        release {
            // Obrigatório para attestation de pagamentos reais SumUp.
            isDebuggable = false
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

    // Somente a variante sumup baixa o artefato privado. A variante nosumup é usada
    // normalmente enquanto as credenciais Maven do SDK não estão disponíveis.
    add("sumupImplementation", "com.sumup.tap-to-pay:utopia-sdk:1.1.6")
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.5")

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
