#define MyAppName "EventMenu Desktop"
#define MyAppVersion "0.3.0"
#define MyAppPublisher "EventMenu"
#define MyAppExeName "EventMenu.Desktop.exe"

[Setup]
AppId={{A3A63490-2B61-4D3B-AEAE-C6637DBBFC9C}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={localappdata}\Programs\EventMenu Desktop
DefaultGroupName=EventMenu Desktop
DisableProgramGroupPage=yes
OutputDir=..\..\artifacts\EventMenu-Desktop-installer
OutputBaseFilename=EventMenu-Desktop-Setup-v0.3.0
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
UninstallDisplayIcon={app}\{#MyAppExeName}
CloseApplications=yes
RestartApplications=no
SetupLogging=yes

[Languages]
Name: "brazilianportuguese"; MessagesFile: "compiler:Languages\BrazilianPortuguese.isl"

[Tasks]
Name: "desktopicon"; Description: "Criar atalho na área de trabalho"; GroupDescription: "Atalhos adicionais:"; Flags: unchecked

[Files]
Source: "..\..\artifacts\EventMenu-Desktop-win-x64\{#MyAppExeName}"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{autoprograms}\EventMenu Desktop"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\EventMenu Desktop"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Abrir EventMenu Desktop"; Flags: nowait postinstall skipifsilent
