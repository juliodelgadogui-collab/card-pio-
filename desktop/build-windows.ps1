$ErrorActionPreference = 'Stop'

$project = Join-Path $PSScriptRoot 'EventMenu.Desktop/EventMenu.Desktop.csproj'
$output = Join-Path $PSScriptRoot 'artifacts/EventMenu-Desktop-win-x64'

if (Test-Path $output) {
    Remove-Item $output -Recurse -Force
}

Write-Host 'Restaurando dependencias...'
dotnet restore $project

Write-Host 'Compilando EventMenu Desktop...'
dotnet build $project -c Release --no-restore

Write-Host 'Gerando pacote Windows x64...'
dotnet publish $project `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:DebugType=None `
    -p:DebugSymbols=false `
    -o $output

Write-Host "Pacote gerado em: $output"
