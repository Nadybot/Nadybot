New-Item -ItemType Directory -Force -Path php
Set-Location php
Invoke-WebRequest -Uri "https://windows.php.net/downloads/releases/latest/php-7.4-nts-Win32-vc15-x64-latest.zip" -OutFile php.zip
Expand-Archive -LiteralPath "php.zip" -DestinationPath "."
Invoke-WebRequest -Uri "https://aka.ms/vs/16/release/VC_redist.x64.exe" -OutFile vc.exe
.\vc.exe /silent
$CWD = Get-Location
[Environment]::SetEnvironmentVariable("Path", [Environment]::GetEnvironmentVariable("Path", "User") + ";$CWD", "User")
Set-Location ..