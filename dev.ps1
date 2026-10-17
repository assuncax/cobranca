# Roda um comando dentro do container de desenvolvimento (PHP 8.3 + Composer).
# Uso: .\dev.ps1 php artisan test
#      $env:PORTA=8000; .\dev.ps1 php artisan serve --host=0.0.0.0 --port=8000
$portas = @()
if ($env:PORTA) { $portas = @('-p', "$($env:PORTA):$($env:PORTA)") }
docker run --rm -i @portas -v "${PSScriptRoot}:/app" -w /app regua-dev @args
exit $LASTEXITCODE
