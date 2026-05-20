# Prefer: php tools/migrate_http.php (strips bootstrap + writes root stubs)
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$http = Join-Path $root 'src\Http'
if (-not (Test-Path $http)) { New-Item -ItemType Directory -Path $http -Force | Out-Null }
$files = @(
    'results.php', 'addNew.php', 'pricing.php', 'report.php', 'error_report.php'
)
$bootstrap = "require_once __DIR__ . '/bootstrap.php';"
foreach ($name in $files) {
    $src = Join-Path $root $name
    $dest = Join-Path $http $name
    if (-not (Test-Path -LiteralPath $src)) { Write-Output "skip $name"; continue }
    $text = Get-Content -LiteralPath $src -Raw
    $text = $text -replace [regex]::Escape($bootstrap + "`r`n"), ''
    $text = $text -replace [regex]::Escape($bootstrap + "`n"), ''
    $text = $text -replace [regex]::Escape($bootstrap), ''
    Set-Content -LiteralPath $dest -Value $text -Encoding UTF8
    Write-Output "copied $name -> src\Http"
}
Write-Output 'Done. Run php tools/migrate_http.php to replace root files with stubs.'
