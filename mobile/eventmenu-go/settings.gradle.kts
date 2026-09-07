pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()

        // Repositórios oficiais do SumUp Tap-to-Pay SDK.
        maven { url = uri("https://maven.sumup.com/releases") }
        maven {
            url = uri("https://tap-to-pay-sdk.fleet.live.sumup.net/")
            credentials {
                // Nunca salvar estas credenciais no repositório. No CI/local elas entram
                // por variáveis de ambiente fornecidas após aprovação da integração SumUp.
                username = System.getenv("SUMUP_MAVEN_USERNAME") ?: ""
                password = System.getenv("SUMUP_MAVEN_PASSWORD") ?: ""
            }
        }
    }
}

rootProject.name = "EventMenuGO"
include(":app")
