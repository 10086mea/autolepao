param([string]$RuntimeZip, [string]$CaFile, [string]$CrtDirectory)
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$output = Join-Path $project 'dist'
$staging = Join-Path $output ('package-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $staging | Out-Null
$runtime = Join-Path $staging 'runtime'
if (!$RuntimeZip) {
    $metadata = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
    $package = $metadata.'8.3'.'nts-vs16-x64'.zip
    $RuntimeZip = Join-Path $output $package.path
    Invoke-WebRequest "https://windows.php.net/downloads/releases/$($package.path)" -OutFile $RuntimeZip
    if ((Get-FileHash -LiteralPath $RuntimeZip -Algorithm SHA256).Hash.ToLowerInvariant() -ne $package.sha256) { throw 'PHP runtime checksum mismatch' }
}
Expand-Archive -LiteralPath $RuntimeZip -DestinationPath $runtime
# Keep the CLI executable, its shared libraries, licences and the two required extensions.
Get-ChildItem -LiteralPath $runtime -File | Where-Object { $_.Extension -notin '.dll','.txt' -and $_.Name -ne 'php.exe' } | Remove-Item
$needed = @('php8.dll','libcrypto-3-x64.dll','libssl-3-x64.dll','libssh2.dll','nghttp2.dll','brotlicommon.dll','brotlidec.dll')
Get-ChildItem -LiteralPath $runtime -Filter '*.dll' -File | Where-Object { $_.Name -notin $needed } | Remove-Item
$extensions = Join-Path $runtime 'ext'
Get-ChildItem -LiteralPath $extensions -File | Where-Object { $_.Name -notin 'php_curl.dll','php_openssl.dll' } | Remove-Item
$runtimeRoot = (Resolve-Path -LiteralPath $runtime).Path
foreach ($directory in Get-ChildItem -LiteralPath $runtime -Directory) {
    if ($directory.Name -eq 'ext') { continue }
    if (!$directory.FullName.StartsWith($runtimeRoot + [IO.Path]::DirectorySeparatorChar)) { throw 'Unexpected package directory' }
    Remove-Item -LiteralPath $directory.FullName -Recurse
}
if (!$CrtDirectory) {
    $CrtDirectory = Get-ChildItem 'C:/Program Files/Microsoft Visual Studio/*/*/VC/Redist/MSVC/*/x64/Microsoft.VC*.CRT','C:/Program Files (x86)/Microsoft Visual Studio/*/*/VC/Redist/MSVC/*/x64/Microsoft.VC*.CRT' -Directory -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
}
if (!$CrtDirectory) { throw 'Microsoft CRT redistributable directory is required for the portable package' }
Copy-Item -Path (Join-Path $CrtDirectory '*.dll') -Destination $runtime
if ($CaFile) { Copy-Item -LiteralPath $CaFile -Destination (Join-Path $runtime 'cacert.pem') }
else { Invoke-WebRequest 'https://curl.se/ca/cacert.pem' -OutFile (Join-Path $runtime 'cacert.pem') }
Copy-Item -LiteralPath (Join-Path $output 'lepao.phar'),(Join-Path $project 'lepao.cmd'),(Join-Path $project 'README.md'),(Join-Path $project 'LICENSE') -Destination $staging
$phpOptions = @('-n','-d',"extension_dir=$extensions",'-d','extension=curl','-d','extension=openssl')
& (Join-Path $runtime 'php.exe') @phpOptions -r 'exit(extension_loaded("curl") && extension_loaded("openssl") && extension_loaded("simplexml") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'Portable PHP extensions did not load' }
& (Join-Path $runtime 'php.exe') @phpOptions (Join-Path $staging 'lepao.phar') --version
if ($LASTEXITCODE -ne 0) { throw 'Portable application startup failed' }
$start = Join-Path $staging '开始.cmd'
@'
@echo off
chcp 65001 >nul
"%~dp0runtime\php.exe" -n -d "extension_dir=%~dp0runtime\ext" -d extension=curl -d extension=openssl "%~dp0lepao.phar"
echo.
pause
'@ | Set-Content -LiteralPath $start -Encoding ascii
$archive = Join-Path $output 'autolepao-windows-x64.zip'
Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $archive -Force
Write-Output $archive
