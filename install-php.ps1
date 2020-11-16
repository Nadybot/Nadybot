mkdir php
Set-Location php
Invoke-WebRequest -Uri "https://windows.php.net/downloads/releases/php-7.4.12-nts-Win32-vc15-x64.zip" -OutFile php.zip
Expand-Archive -LiteralPath "php.zip" -DestinationPath "."
$CWD = Get-Location
[Environment]::SetEnvironmentVariable("Path", [Environment]::GetEnvironmentVariable("Path", "User") + ";$CWD", "User")
Set-Location ..