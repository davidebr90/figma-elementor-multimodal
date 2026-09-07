$ErrorActionPreference = 'Stop'

$repo = Resolve-Path (Join-Path $PSScriptRoot '../..')
$compose = Join-Path $repo 'compose.fem-test.yaml'
$elementor = Join-Path $repo '.runtime/elementor'

Write-Host 'FEM runtime preflight'
Write-Host "Repository: $repo"

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI non trovato nel PATH.'
}

docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker daemon non raggiungibile. Avvia Docker Desktop e riprova.'
}

if (-not (Test-Path -LiteralPath $compose -PathType Leaf)) {
    throw "Compose FEM non trovato: $compose"
}

if (-not (Test-Path -LiteralPath $elementor -PathType Container)) {
    throw "Pacchetto Elementor mancante: $elementor"
}

$elementorMain = Join-Path $elementor 'elementor.php'
if (-not (Test-Path -LiteralPath $elementorMain -PathType Leaf)) {
    throw "Installazione Elementor incompleta: manca $elementorMain"
}

docker compose -f $compose config --quiet
if ($LASTEXITCODE -ne 0) {
    throw 'La configurazione Docker Compose non è valida.'
}

Write-Host 'Preflight superato: Docker, compose ed Elementor sono disponibili.' -ForegroundColor Green
