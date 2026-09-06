# Compilar o EventMenu GO

O projeto Android está em `mobile/eventmenu-go` e usa o servidor fixo:

`https://go.gestao2.store/1/`

## Requisitos

- JDK 17
- Android SDK Platform 36
- Android SDK Build Tools 36.0.0
- conexão com a internet na primeira compilação para baixar Gradle e dependências

O projeto usa:

- Android Gradle Plugin 9.1.0
- Gradle 9.3.1
- Kotlin integrado do AGP, fixado em KGP 2.4.10
- Compose Compiler 2.4.10
- Compose BOM 2026.06.00
- `minSdk 23`
- `targetSdk 36`
- `compileSdk 36`

## Windows

Abra o PowerShell na pasta do projeto:

```powershell
cd mobile/eventmenu-go
powershell -ExecutionPolicy Bypass -File .\build-debug.ps1
```

O script procura o Android SDK em `ANDROID_SDK_ROOT`, `ANDROID_HOME` ou `%LOCALAPPDATA%\Android\Sdk`, cria `local.properties` localmente quando necessário, baixa Gradle 9.3.1 e executa `:app:assembleDebug`.

## Linux / macOS

```bash
cd mobile/eventmenu-go
bash ./build-debug.sh
```

Se necessário, defina antes:

```bash
export ANDROID_SDK_ROOT="$HOME/Android/Sdk"
```

## Android Studio

Abra **a pasta `mobile/eventmenu-go`**, não a raiz inteira do repositório.

Configure o Gradle JDK para Java 17 e instale pelo SDK Manager:

- Android SDK Platform 36
- Android SDK Build-Tools 36.0.0

Como o repositório não contém o `gradle-wrapper.jar` binário, os scripts `build-debug.ps1` e `build-debug.sh` são a forma reproduzível de usar exatamente Gradle 9.3.1. Também é possível configurar uma instalação local do Gradle 9.3.1 no Android Studio.

## APK de debug

Após uma compilação bem-sucedida, o APK normalmente será criado em:

`app/build/outputs/apk/debug/app-debug.apk`

## Observações

- O domínio do servidor não é configurável pela interface do funcionário.
- `local.properties`, pastas de build e arquivos do Android Studio ficam fora do Git.
- A primeira compilação é o momento de descobrir eventuais erros de integração restantes; nenhuma compilação automatizada foi executada durante a etapa atual de desenvolvimento.
