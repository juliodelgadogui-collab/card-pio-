$ErrorActionPreference = "Stop"

$GradleVersion = "9.3.1"
$RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$CacheDir = Join-Path $env:USERPROFILE ".eventmenu-delivery"
$GradleHome = Join-Path $CacheDir "gradle-$GradleVersion"
$ZipFile = Join-Path $CacheDir "gradle-$GradleVersion-bin.zip"
$DistUrl = "https://services.gradle.org/distributions/gradle-$GradleVersion-bin.zip"

New-Item -ItemType Directory -Force -Path $CacheDir | Out-Null
$GradleBat = Join-Path $GradleHome "bin\gradle.bat"
if (-not (Test-Path $GradleBat)) {
    Write-Host "Baixando Gradle $GradleVersion..."
    Invoke-WebRequest -Uri $DistUrl -OutFile $ZipFile -UseBasicParsing
    if (Test-Path $GradleHome) { Remove-Item -Recurse -Force $GradleHome }
    Expand-Archive -Path $ZipFile -DestinationPath $CacheDir -Force
}

Set-Location $RootDir
if (-not (Test-Path "local.properties")) {
    $Sdk = $env:ANDROID_SDK_ROOT
    if (-not $Sdk) { $Sdk = $env:ANDROID_HOME }
    if (-not $Sdk) {
        $DefaultSdk = Join-Path $env:LOCALAPPDATA "Android\Sdk"
        if (Test-Path $DefaultSdk) { $Sdk = $DefaultSdk }
    }
    if ($Sdk) {
        $SdkForProperties = $Sdk -replace '\\','/'
        "sdk.dir=$SdkForProperties" | Set-Content -Encoding ASCII "local.properties"
    } else {
        Write-Warning "SDK Android não localizado. Abra mobile/eventmenu-delivery no Android Studio ou defina ANDROID_SDK_ROOT."
    }
}

& $GradleBat :app:assembleDebug @args
exit $LASTEXITCODE
