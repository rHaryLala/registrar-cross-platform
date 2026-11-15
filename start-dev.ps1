<#
 Démarre l'environnement local dans trois fenêtres : API (PHP 8.3), relais, application (Next.js).

   .\start-dev.ps1          base RÉELLE locale (copie de votre sauvegarde) : connexion avec vos identifiants
   .\start-dev.ps1 -Demo    base de DÉMONSTRATION (comptes fictifs, mot de passe « mot-de-passe-test »)

 Puis ouvrir http://localhost:3000. Fermer les trois fenêtres pour tout arrêter.
 Google n'est pas activé en local : on se connecte avec identifiant + mot de passe.
#>
param([switch]$Demo)

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$php = Get-ChildItem 'C:\laragon\bin\php' -Directory -Filter 'php-8.3*' | Sort-Object Name -Descending | Select-Object -First 1
if (-not $php) { throw "PHP 8.3 introuvable dans C:\laragon\bin\php (le PHP du PATH est en 8.5, sans pdo_mysql)." }
$phpExe = Join-Path $php.FullName 'php.exe'
$base = if ($Demo) { 'registrar-crossplatform_demo' } else { 'registrar-crossplatform' }

Write-Host "Base utilisée : $base" -ForegroundColor Cyan
if (-not $Demo) { Write-Host "(copie locale de vos données réelles : ne pas lancer DemoSeeder dessus)" -ForegroundColor Yellow }

$api = "`$env:DB_DATABASE='$base'; Set-Location '$root\api'; & '$phpExe' artisan cache:clear | Out-Null; & '$phpExe' artisan serve --host=127.0.0.1 --port=8001"
$relay = "Set-Location '$root\web'; node e2e/dev-proxy.mjs"
$web = "`$env:NEXT_PUBLIC_API_URL='http://localhost:8000'; Set-Location '$root\web'; npm run dev"

Start-Process powershell -ArgumentList '-NoExit', '-Command', $api
Start-Process powershell -ArgumentList '-NoExit', '-Command', $relay
Start-Process powershell -ArgumentList '-NoExit', '-Command', $web
Write-Host "Trois fenêtres lancées. Ouvrez http://localhost:3000 dans quelques secondes." -ForegroundColor Green
