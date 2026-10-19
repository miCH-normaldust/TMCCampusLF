$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$webRoot = Join-Path $projectRoot 'web'
$php = Join-Path $env:SystemDrive 'xampp\php\php.exe'

if (-not (Test-Path $php)) {
    throw "XAMPP PHP was not found at $php. Install PHP 8.2+ or update scripts/start-backend.ps1."
}

if (-not (Test-Path (Join-Path $webRoot 'vendor\autoload.php'))) {
    throw 'Laravel dependencies are missing. Run composer install from the web folder first.'
}

if (-not (Test-Path (Join-Path $webRoot '.env'))) {
    throw 'web\.env is missing. Copy web\.env.example to web\.env and configure MySQL first.'
}

$env:Path = "$(Split-Path -Parent $php);$env:Path"
Set-Location $webRoot
& $php artisan migrate:status
if ($LASTEXITCODE -ne 0) {
    throw 'Laravel could not connect to the configured MySQL database. Check web\.env and MySQL Workbench/server.'
}

& $php artisan serve --host=0.0.0.0 --port=8000
exit $LASTEXITCODE
