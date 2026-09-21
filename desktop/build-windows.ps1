$ErrorActionPreference = 'Stop'

$desktopProject = Join-Path $PSScriptRoot 'EventMenu.Desktop/EventMenu.Desktop.csproj'
$whatsAppProject = Join-Path $PSScriptRoot 'EventMenu.WhatsAppConnect/EventMenu.WhatsAppConnect.csproj'
$output = Join-Path $PSScriptRoot 'artifacts/EventMenu-Desktop-win-x64'
$whatsAppRoot = Join-Path $output 'whatsapp-connect'
$nodeOutput = Join-Path $whatsAppRoot 'node'
$workerOutput = Join-Path $whatsAppRoot 'worker'
$workerSource = Join-Path (Split-Path $PSScriptRoot -Parent) 'integrations/whatsapp-worker'
$nodeVersion = '22.18.0'
$nodeArchiveName = "node-v$nodeVersion-win-x64.zip"
$nodeDownload = "https://nodejs.org/dist/v$nodeVersion/$nodeArchiveName"
$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) "eventmenu-whatsapp-build-$([Guid]::NewGuid().ToString('N'))"
$tempZip = Join-Path $tempRoot $nodeArchiveName
$tempExtract = Join-Path $tempRoot 'node'

if (Test-Path $output) {
    Remove-Item $output -Recurse -Force
}
New-Item -ItemType Directory -Path $output -Force | Out-Null

Write-Host 'Restaurando dependencias do EventMenu Desktop...'
dotnet restore $desktopProject
Write-Host 'Restaurando dependencias do EventMenu WhatsApp Connect...'
dotnet restore $whatsAppProject

Write-Host 'Compilando EventMenu Desktop...'
dotnet build $desktopProject -c Release --no-restore
Write-Host 'Compilando EventMenu WhatsApp Connect...'
dotnet build $whatsAppProject -c Release --no-restore

Write-Host 'Gerando EventMenu Desktop Windows x64 self-contained...'
dotnet publish $desktopProject `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:DebugType=None `
    -p:DebugSymbols=false `
    -o $output
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host 'Gerando EventMenu WhatsApp Connect Windows x64 self-contained...'
dotnet publish $whatsAppProject `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:DebugType=None `
    -p:DebugSymbols=false `
    -o $output
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

try {
    New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
    New-Item -ItemType Directory -Path $tempExtract -Force | Out-Null
    New-Item -ItemType Directory -Path $nodeOutput -Force | Out-Null
    New-Item -ItemType Directory -Path $workerOutput -Force | Out-Null

    Write-Host "Baixando Node.js $nodeVersion para o WhatsApp Connect..."
    Invoke-WebRequest -Uri $nodeDownload -OutFile $tempZip -UseBasicParsing
    Expand-Archive -Path $tempZip -DestinationPath $tempExtract -Force
    $nodeSource = Join-Path $tempExtract "node-v$nodeVersion-win-x64"
    $nodeExe = Join-Path $nodeSource 'node.exe'
    $npmCmd = Join-Path $nodeSource 'npm.cmd'
    if (-not (Test-Path $nodeExe) -or -not (Test-Path $npmCmd)) {
        throw 'O pacote oficial do Node.js não contém node.exe/npm.cmd.'
    }

    Copy-Item $nodeExe (Join-Path $nodeOutput 'node.exe') -Force
    Copy-Item (Join-Path $workerSource 'server.js') (Join-Path $workerOutput 'server.js') -Force
    Copy-Item (Join-Path $workerSource 'package.json') (Join-Path $workerOutput 'package.json') -Force

    Write-Host 'Instalando dependencias de producao do Baileys no pacote Windows...'
    Push-Location $workerOutput
    try {
        & $npmCmd install --omit=dev --no-audit --no-fund
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
    finally { Pop-Location }
}
finally {
    if (Test-Path $tempRoot) { Remove-Item $tempRoot -Recurse -Force -ErrorAction SilentlyContinue }
}

$desktopExe = Join-Path $output 'EventMenu.Desktop.exe'
$whatsAppExe = Join-Path $output 'EventMenu.WhatsAppConnect.exe'
$bundledNode = Join-Path $nodeOutput 'node.exe'
$bundledWorker = Join-Path $workerOutput 'server.js'
foreach ($required in @($desktopExe,$whatsAppExe,$bundledNode,$bundledWorker)) {
    if (-not (Test-Path $required)) { throw "Arquivo esperado nao foi gerado: $required" }
}

Write-Host "Pronto: $desktopExe"
Write-Host "Pronto: $whatsAppExe"
Write-Host 'Node.js + Baileys foram incluidos no pacote; o cliente nao precisa instalar Node manualmente.'
