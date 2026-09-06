buildscript {
    dependencies {
        // AGP 9 usa Kotlin integrado. Fixamos o KGP usado pelo compilador Compose na mesma versão.
        classpath("org.jetbrains.kotlin:kotlin-gradle-plugin:2.4.10")
        classpath("org.jetbrains.kotlin.plugin.compose:org.jetbrains.kotlin.plugin.compose.gradle.plugin:2.4.10")
    }
}

plugins {
    id("com.android.application") version "9.1.0" apply false
    id("org.jetbrains.kotlin.plugin.compose") version "2.4.10" apply false
}
