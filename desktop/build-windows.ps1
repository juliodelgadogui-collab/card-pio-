$ErrorActionPreference = 'Stop'

$project = Join-Path $PSScriptRoot 'EventMenu.Desktop/EventMenu.Desktop.csproj'
$output = Join-Path $PSScriptRoot 'artifacts/EventMenu-Desktop-win-x64'

if (Test-Path $output) {
    Remove-Item $output -Recurse -Force
}

Write-Host 'Restaurando dependencias...'
dotnet restore $project

Write-Host 'Compilando EventMenu Desktop 0.2.0...'
dotnet build $project -c Release --no-restore

Write-Host 'Gerando pacote Windows x64 self-contained...'
dotnet publish $project `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:DebugType=None `
    -p:DebugSymbols=false `
    -o $output

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$exe = Join-Path $output 'EventMenu.Desktop.exe'
if (-not (Test-Path $exe)) {
    throw "O executavel nao foi gerado em $exe"
}

Write-Host "Pronto: $exe"
