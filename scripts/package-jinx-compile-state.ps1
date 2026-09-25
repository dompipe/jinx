$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$packageName = "jinx-pasm-compile-state-$stamp"
$packagesDir = Join-Path $root 'build/packages'
$packageRoot = Join-Path $packagesDir $packageName

New-Item -ItemType Directory -Force -Path $packageRoot | Out-Null

$paths = @(
    'scripts/php-to-jinx.php',
    'scripts/jinx-native-attach.php',
    'scripts/build-php-native-commands.php',
    'scripts/test-php-jinx.php',
    'scripts/test-php-jinx-chaos.php',
    'scripts/test-php-native-commands.php',
    'scripts/test-jinx-native-attachment.php',
    'scripts/test-jinx-math-shell.php',
    'scripts/benchmark-jinx-math-shell.php',
    'scripts/package-jinx-compile-state.ps1',
    'examples/jinx-math-shell.php',
    'include/jinx_pasm_native.h',
    'build/php-native-commands.json'
)

foreach ($rel in $paths) {
    $src = Join-Path $root $rel
    if (!(Test-Path -LiteralPath $src)) {
        throw "Missing package source: $rel"
    }
    $dst = Join-Path $packageRoot $rel
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
    Copy-Item -LiteralPath $src -Destination $dst -Force
}

$chaosSrc = Join-Path $root 'build/jinx-chaos-runs'
if (Test-Path -LiteralPath $chaosSrc) {
    $chaosDst = Join-Path $packageRoot 'build/jinx-chaos-runs'
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $chaosDst) | Out-Null
    Copy-Item -LiteralPath $chaosSrc -Destination $chaosDst -Recurse -Force
}

$readme = @(
    '# JINX PASM Compile State Package',
    '',
    "Created: $stamp",
    "Source workspace: $root",
    '',
    'This package contains the current PHP -> JINX JSON -> PASM -> C native attachment prototype files from the compiling state in this session.',
    '',
    '## Included',
    '',
    '- scripts/php-to-jinx.php',
    '- scripts/jinx-native-attach.php',
    '- scripts/build-php-native-commands.php',
    '- scripts/test-php-jinx.php',
    '- scripts/test-php-jinx-chaos.php',
    '- scripts/test-php-native-commands.php',
    '- scripts/test-jinx-native-attachment.php',
    '- scripts/test-jinx-math-shell.php',
    '- scripts/benchmark-jinx-math-shell.php',
    '- scripts/package-jinx-compile-state.ps1',
    '- examples/jinx-math-shell.php',
    '- include/jinx_pasm_native.h',
    '- build/php-native-commands.json',
    '- build/jinx-chaos-runs/*',
    '',
    '## Reproduce Test Gate',
    '',
    'From the package root:',
    '',
    '```powershell',
    'php scripts/test-php-jinx.php',
    'php scripts/test-php-jinx-chaos.php',
    'php scripts/test-php-native-commands.php',
    'php scripts/test-jinx-native-attachment.php',
    'php scripts/test-jinx-math-shell.php',
    '```',
    '',
    'The native attachment tests require WSL with gcc in Ubuntu or Ubuntu-24.04.'
)
Set-Content -LiteralPath (Join-Path $packageRoot 'README-PACKAGE.md') -Value $readme -Encoding UTF8

$zipPath = Join-Path $packagesDir "$packageName.zip"
if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}
Compress-Archive -Path (Join-Path $packageRoot '*') -DestinationPath $zipPath -Force

Get-Item -LiteralPath $zipPath | Select-Object FullName, Length
