$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$out = Join-Path $root 'release'
$browser = Join-Path $root 'frontend\dist\frontend\browser'
$web = Join-Path $out 'public_html'
$app = Join-Path $out 'brandmate-app'

if (Test-Path $out) {
    throw "Output folder already exists; move it before building: $out"
}

Write-Host '1/3 Building the Angular production app...'
Push-Location (Join-Path $root 'frontend')
try {
    if (-not (Test-Path 'node_modules')) {
        npm ci
        if ($LASTEXITCODE -ne 0) { throw 'npm ci failed' }
    }
    npm run build -- --configuration production
    if ($LASTEXITCODE -ne 0) { throw 'Angular production build failed' }
} finally {
    Pop-Location
}

if (-not (Test-Path $browser)) {
    throw "Angular browser output was not found: $browser"
}

Write-Host '2/3 Collecting the web root and Laravel source...'
New-Item -ItemType Directory -Path $web, $app -Force | Out-Null
Copy-Item (Join-Path $browser '*') $web -Recurse -Force
Copy-Item (Join-Path $PSScriptRoot 'public_html\index.php') $web -Force
Copy-Item (Join-Path $PSScriptRoot 'public_html\.htaccess') $web -Force

robocopy (Join-Path $root 'backend') $app /E /NFL /NDL /NJH /NJS `
    /XD vendor node_modules tests .git storage `
    /XF .env *.sqlite *.log | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copying the Laravel source failed' }

Write-Host '3/3 Creating upload archives...'
Push-Location $web
try {
    tar.exe -a -c -f (Join-Path $out 'public_html.zip') * .htaccess
    if ($LASTEXITCODE -ne 0) { throw 'Creating public_html.zip failed' }
} finally {
    Pop-Location
}

Push-Location $out
try {
    tar.exe -a -c -f (Join-Path $out 'brandmate-app.zip') brandmate-app
    if ($LASTEXITCODE -ne 0) { throw 'Creating brandmate-app.zip failed' }
} finally {
    Pop-Location
}

Write-Host "Release ready in $out"
Write-Host 'Upload public_html.zip into public_html/brandmate and brandmate-app.zip to your account home.'