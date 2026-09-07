[CmdletBinding()]
param(
    [string]$OutputDirectory
)

$ErrorActionPreference = 'Stop'

$repository = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$wordpressSource = (Resolve-Path (Join-Path $repository 'packages\wordpress-plugin')).Path
$figmaSource = (Resolve-Path (Join-Path $repository 'packages\figma-plugin')).Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repository 'dist'
}
$output = [System.IO.Path]::GetFullPath($OutputDirectory)

if (-not $output.StartsWith($repository, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'OutputDirectory must be inside the repository.'
}

$composer = Get-Command composer -ErrorAction SilentlyContinue
$composerPhar = Join-Path $repository 'tools\composer.phar'
if ($null -eq $composer -and -not (Test-Path -LiteralPath $composerPhar)) {
    throw 'Composer is required to package the WordPress plugin. Install Composer or place composer.phar in tools/.'
}

$temporaryRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$temporary = Join-Path $temporaryRoot ('fem-release-' + [guid]::NewGuid().ToString('N'))
$wordpressPackage = Join-Path $temporary 'figma-elementor-multimodal'
$figmaPackage = Join-Path $temporary 'figma-elementor-multimodal-figma'

try {
    New-Item -ItemType Directory -Force -Path $wordpressPackage, $figmaPackage, $output | Out-Null
    Copy-Item -Recurse -Force -Path (Join-Path $wordpressSource '*') -Destination $wordpressPackage -Exclude 'tests', '.phpunit.cache', 'vendor'
    if ($null -ne $composer) {
        & $composer.Path install --working-dir=$wordpressPackage --no-dev --prefer-dist --optimize-autoloader --no-interaction
    }
    else {
        & php $composerPhar install --working-dir=$wordpressPackage --no-dev --prefer-dist --optimize-autoloader --no-interaction
    }
    if ($LASTEXITCODE -ne 0) {
        throw 'Composer could not install WordPress runtime dependencies.'
    }
    Copy-Item -Recurse -Force -Path (Join-Path $figmaSource '*') -Destination $figmaPackage -Exclude 'tests', 'README.md', 'README-IT.md', 'package.json'

    $wordpressZip = Join-Path $output 'figma-elementor-multimodal-wordpress.zip'
    $figmaZip = Join-Path $output 'figma-elementor-multimodal-figma-development.zip'
    Compress-Archive -Path $wordpressPackage -DestinationPath $wordpressZip -Force
    Compress-Archive -Path (Join-Path $figmaPackage '*') -DestinationPath $figmaZip -Force
    Write-Output "Created: $wordpressZip"
    Write-Output "Created: $figmaZip"
}
finally {
    if ((Test-Path -LiteralPath $temporary) -and (Resolve-Path -LiteralPath $temporary).Path.StartsWith($temporaryRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
