#define MyAppName "EventMenu Desktop"
#define MyAppVersion "1.0.1"
#define MyAppPublisher "EventMenu"
#define MyAppExeName "EventMenu.Desktop.exe"
#define MyWhatsAppExeName "EventMenu.WhatsAppConnect.exe"

[Setup]
AppId={{A3A63490-2B61-4D3B-AEAE-C6637DBBFC9C}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={localappdata}\Programs\EventMenu Desktop
DefaultGroupName=EventMenu Desktop
DisableProgramGroupPage=yes
OutputDir=..\..\artifacts\EventMenu-Desktop-installer
OutputBaseFilename=EventMenu-Desktop-Setup-v1.0.1
SetupIconFile=..\EventMenu.Desktop\Assets\EventMenu.ico
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
Name: "desktopicon"; Description: "Criar atalho do EventMenu na área de trabalho"; GroupDescription: "Atalhos adicionais:"; Flags: unchecked
Name: "whatsappstartup"; Description: "Iniciar o EventMenu WhatsApp Connect junto com o Windows"; GroupDescription: "WhatsApp:"

[Files]
Source: "..\artifacts\EventMenu-Desktop-win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\EventMenu Desktop"; Filename: "{app}\{#MyAppExeName}"
Name: "{autoprograms}\EventMenu WhatsApp Connect"; Filename: "{app}\{#MyWhatsAppExeName}"
Name: "{autodesktop}\EventMenu Desktop"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon
Name: "{userstartup}\EventMenu WhatsApp Connect"; Filename: "{app}\{#MyWhatsAppExeName}"; Parameters: "--background"; WorkingDir: "{app}"; Tasks: whatsappstartup

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Abrir EventMenu Desktop"; Flags: nowait postinstall skipifsilent
Filename: "{app}\{#MyWhatsAppExeName}"; Description: "Configurar o WhatsApp agora"; Flags: nowait postinstall skipifsilent
