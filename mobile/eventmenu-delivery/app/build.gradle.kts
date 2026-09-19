plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun envValue(name: String, fallback: String? = null): String =
    System.getenv(name)?.trim().orEmpty().ifBlank { fallback?.let { System.getenv(it)?.trim().orEmpty() }.orEmpty() }
fun configString(value: String): String = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

val apiBase = envValue("EVENTMENU_DELIVERY_API_BASE_URL").ifBlank { "https://go.gestao2.store/1/" }.trimEnd('/') + "/"
if (!apiBase.startsWith("https://", ignoreCase = true)) throw GradleException("A API do Delivery precisa usar HTTPS.")

val firebaseProjectId = envValue("EVENTMENU_FIREBASE_PROJECT_ID", "FCM_PROJECT_ID")
val firebaseAppId = envValue("EVENTMENU_DELIVERY_FIREBASE_APP_ID", "EVENTMENU_FIREBASE_APP_ID")
val firebaseApiKey = envValue("EVENTMENU_FIREBASE_API_KEY")
val firebaseSenderId = envValue("EVENTMENU_FIREBASE_SENDER_ID")
val firebaseConfigured = listOf(firebaseProjectId,firebaseAppId,firebaseApiKey,firebaseSenderId).all { it.isNotBlank() }

android {
    namespace = "br.com.eventmenu.delivery"
    compileSdk = 36
    defaultConfig {
        applicationId = "br.com.eventmenu.delivery"
        minSdk = 23
        targetSdk = 36
        versionCode = 1
        versionName = "0.1.0"
        buildConfigField("String", "API_BASE_URL", configString(apiBase))
        buildConfigField("boolean", "FIREBASE_ENABLED", firebaseConfigured.toString())
        buildConfigField("String", "FIREBASE_PROJECT_ID", configString(firebaseProjectId))
        buildConfigField("String", "FIREBASE_APP_ID", configString(firebaseAppId))
        buildConfigField("String", "FIREBASE_API_KEY", configString(firebaseApiKey))
        buildConfigField("String", "FIREBASE_SENDER_ID", configString(firebaseSenderId))
    }
    buildFeatures { compose = true; buildConfig = true }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
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

    implementation(platform("com.mercadopago.android.sdk:sdk-android-bom:1.0.0"))
    implementation("com.mercadopago.android.sdk:sdk-android")
    implementation("com.mercadopago.android.sdk:core-methods")

    testImplementation("junit:junit:4.13.2")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
