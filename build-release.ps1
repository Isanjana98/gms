<#
.SYNOPSIS
    Build a deployable zip: the API with the admin panel inside it.

.DESCRIPTION
    Produces one archive to upload and extract on a PHP host. Everything is
    served from one origin — the website at /, the panel at /admin, the API at
    /api, the health form at /health — so there is no CORS to configure and no
    second deployment to keep in step.

    What this deliberately leaves out matters as much as what it includes.
    `api/storage/app` on a development machine holds **real member data**:
    signed health screenings, uploaded documents, member photographs, and
    database dumps under `backups/`. None of it belongs in a file that gets
    emailed around or uploaded to shared hosting, so the staging copy keeps the
    directory structure and throws the contents away.

.PARAMETER Output
    Where to write the zip. Defaults to the repository root.

.PARAMETER SkipComposer
    Reuse the vendor directory already in the staging folder. Only useful when
    rebuilding repeatedly and composer has not changed.

.EXAMPLE
    .\build-release.ps1
    .\build-release.ps1 -Output D:\releases
#>
[CmdletBinding()]
param(
    [string] $Output = $PSScriptRoot,
    [switch] $SkipComposer
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$staging = Join-Path $env:TEMP 'gms-release'

<#
    Run a native command and judge it by its exit code.

    Windows PowerShell turns anything a native command writes to stderr into a
    terminating error when $ErrorActionPreference is 'Stop'. This machine's PHP
    prints an xdebug loading warning there on every single invocation, so
    composer would "fail" while succeeding. Only the exit code says what
    happened.
#>
function Invoke-Native {
    param([string] $What, [scriptblock] $Command)

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        & $Command 2>&1 | ForEach-Object { Write-Verbose ($_ | Out-String).TrimEnd() }
        if ($LASTEXITCODE -ne 0) { throw "$What failed (exit $LASTEXITCODE)." }
    }
    finally { $ErrorActionPreference = $previous }
}


Write-Host ''
Write-Host '  Building a release' -ForegroundColor Cyan
Write-Host ''

# ------------------------------------------------------------- the panel

Write-Host '  [1/5] Building the admin panel' -ForegroundColor Green

Push-Location (Join-Path $root 'web')
try {
    if (-not (Test-Path 'node_modules')) { Invoke-Native 'npm ci' { npm ci } }

    # Served from /admin, because Laravel already owns / for the website and
    # /health/{token} for the signed form. Vite needs the trailing slash.
    $env:ADMIN_BASE = '/admin/'
    Invoke-Native 'The panel build' { npm run build }
}
finally {
    Remove-Item Env:\ADMIN_BASE -ErrorAction SilentlyContinue
    Pop-Location
}

$index = Get-Content (Join-Path $root 'web\dist\index.html') -Raw
if ($index -notmatch '/admin/assets/') {
    throw 'The panel was built without the /admin base path. Its assets would 404.'
}

# -------------------------------------------------------------- staging

Write-Host '  [2/5] Copying the application' -ForegroundColor Green

if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory $staging | Out-Null

# Vendor is rebuilt below; node_modules and .git have no business here.
$exclude = @('node_modules', '.git', 'vendor')
Get-ChildItem (Join-Path $root 'api') -Force |
    Where-Object { $exclude -notcontains $_.Name } |
    ForEach-Object { Copy-Item $_.FullName (Join-Path $staging $_.Name) -Recurse -Force }

<#
    The panel, always from the build that just ran.

    `api/public/admin` may already hold an older build. Copying `web\dist` onto
    a directory that exists does not replace it - PowerShell puts the source
    *inside* it, as `public/admin/dist`, and leaves the stale `index.html`
    sitting where the server will actually find it. The release then ships last
    week's panel while the new one hides in a folder nothing serves, and
    because the stale file is still named `index.html` the check below would
    wave it through. Start from an empty directory and copy the contents.
#>
$panel = Join-Path $staging 'public\admin'
if (Test-Path $panel) { Remove-Item $panel -Recurse -Force }
New-Item -ItemType Directory $panel -Force | Out-Null
Copy-Item (Join-Path $root 'web\dist\*') $panel -Recurse -Force

# The file the server will serve, not the one we built a moment ago: those are
# the same thing only if the copy above worked.
$served = Get-Content (Join-Path $panel 'index.html') -Raw
$asset = [regex]::Match($served, '/admin/assets/(?<file>[A-Za-z0-9._-]+\.js)')

if (-not $asset.Success) {
    throw 'The staged panel does not reference /admin/assets/. Its assets would 404.'
}
if (-not (Test-Path (Join-Path $panel ('assets\' + $asset.Groups['file'].Value)))) {
    throw "The staged panel asks for assets\$($asset.Groups['file'].Value), which is not in the staged build. The copy is stale."
}

# ---------------------------------------------------------- dependencies

if (-not $SkipComposer) {
    Write-Host '  [3/5] Installing dependencies without the dev tools' -ForegroundColor Green

    Push-Location $staging
    try {
        Invoke-Native 'composer install' { composer install --no-dev --optimize-autoloader --no-interaction }
    }
    finally { Pop-Location }
}
else {
    Write-Host '  [3/5] Skipping composer' -ForegroundColor DarkGray
}

# ------------------------------------------------------------- scrubbing

Write-Host '  [4/5] Removing development data and secrets' -ForegroundColor Green

# .env carries the database password and APP_KEY for *this* machine. The
# installer writes a fresh one on the server.
Remove-Item (Join-Path $staging '.env') -Force -ErrorAction SilentlyContinue

# Tests reference dev dependencies that are no longer installed.
Remove-Item (Join-Path $staging 'tests') -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $staging 'phpunit.xml') -Force -ErrorAction SilentlyContinue

# Compiled config and routes pin the paths and settings of this machine.
Get-ChildItem (Join-Path $staging 'bootstrap\cache') -Filter '*.php' -ErrorAction SilentlyContinue |
    Remove-Item -Force

# A lock from a local install would make the server's installer refuse to run.
Remove-Item (Join-Path $staging 'storage\app\installed.lock') -Force -ErrorAction SilentlyContinue

<#
    Member data, and the directories Laravel still needs to exist.

    storage/app holds signed health screenings, member documents and
    photographs; storage/app/backups holds whole database dumps. Shipping any
    of it would put real people's records on a server it was never meant to
    reach.
#>
$wipe = @(
    'storage\app\private', 'storage\app\public', 'storage\app\backups',
    'storage\app\test-backups', 'storage\logs',
    'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views',
    'storage\framework\testing'
)

foreach ($path in $wipe) {
    $full = Join-Path $staging $path
    if (Test-Path $full) { Remove-Item $full -Recurse -Force }
}

# Recreate them empty. Laravel does not create these itself and fails with a
# permissions error that says nothing about the real problem.
foreach ($path in @(
    'storage\app\private', 'storage\app\public', 'storage\logs',
    'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views'
)) {
    New-Item -ItemType Directory (Join-Path $staging $path) -Force | Out-Null
    Set-Content (Join-Path $staging "$path\.gitignore") "*`n!.gitignore`n" -Encoding utf8
}

# public/storage is a link to storage/app/public, recreated on the server with
# `php artisan storage:link`. A copied link points at this machine.
Remove-Item (Join-Path $staging 'public\storage') -Recurse -Force -ErrorAction SilentlyContinue

# Anything that slipped through.
$leaked = Get-ChildItem $staging -Recurse -Force -Include '.env', '*.sql', '*.sqlite' -ErrorAction SilentlyContinue
if ($leaked) {
    $leaked | ForEach-Object { Write-Host "       removed $($_.FullName.Replace($staging,''))" -ForegroundColor Yellow }
    $leaked | Remove-Item -Force
}

# ------------------------------------------------------------------ zip

Write-Host '  [5/5] Packing' -ForegroundColor Green

$name = 'gms-' + (Get-Date -Format 'yyyy-MM-dd-HHmm') + '.zip'
$zip = Join-Path $Output $name

if (Test-Path $zip) { Remove-Item $zip -Force }

<#
    Written entry by entry, because Compress-Archive cannot be used here.

    Windows PowerShell 5.1's Compress-Archive stores paths with the separator
    the platform happens to use, so every entry comes out as `app\Http\foo.php`.
    The ZIP specification (4.4.17.1) allows only forward slashes. Windows and
    7-Zip quietly repair that on the way out, so the archive looks fine on the
    machine that built it - and that is exactly what makes it dangerous.

    On a Linux host a backslash is an ordinary, legal character in a filename,
    so there is nothing to repair and nothing to warn about: the extractor
    creates 7,000-odd files with backslashes in their names, all in one flat
    directory. There is no public/ directory, no vendor/autoload.php at the
    path the autoloader looks for, nothing. The site answers 500 and the log
    is empty, because PHP never got far enough to open one.

    Directory entries are written too, so the storage/ tree that Laravel
    expects to exist survives even where it holds no files.
#>
Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

<#
    Enumerated through .NET rather than Get-ChildItem, so that the prefix
    stripped below is byte-for-byte the prefix the items actually carry.

    $env:TEMP on this machine is the 8.3 short form
    (C:\Users\ISURU~1.SAN\...), while the PowerShell provider hands back
    FullName in the long form (C:\Users\isuru.sanjana\...). Measuring one
    against the other leaves the tail of the prefix on every entry - the first
    build after this change put the whole application under a directory called
    `e`, the last two characters of `gms-release`. DirectoryInfo builds each
    child's FullName by concatenating the path it was given, so the two always
    agree, whichever form was passed in.
#>
$stagingDir = New-Object System.IO.DirectoryInfo $staging
$base = $stagingDir.FullName.TrimEnd('\') + '\'
$stream = [System.IO.File]::Open($zip, [System.IO.FileMode]::CreateNew)
$count = 0

try {
    $archive = New-Object System.IO.Compression.ZipArchive(
        $stream, [System.IO.Compression.ZipArchiveMode]::Create)

    try {
        foreach ($item in $stagingDir.EnumerateFileSystemInfos('*', [System.IO.SearchOption]::AllDirectories)) {
            if (-not $item.FullName.StartsWith($base, [System.StringComparison]::OrdinalIgnoreCase)) {
                throw "'$($item.FullName)' is not under '$base'; the relative path cannot be derived."
            }

            $relative = $item.FullName.Substring($base.Length).Replace('\', '/')

            # A .NET FileSystemInfo has no PSIsContainer; ask its type instead.
            if ($item -is [System.IO.DirectoryInfo]) {
                $archive.CreateEntry($relative + '/') | Out-Null
                continue
            }

            $entry = $archive.CreateEntry($relative, [System.IO.Compression.CompressionLevel]::Optimal)
            $target = $entry.Open()

            try {
                $source = [System.IO.File]::OpenRead($item.FullName)
                try { $source.CopyTo($target) } finally { $source.Dispose() }
            }
            finally { $target.Dispose() }

            $count++
        }
    }
    finally { $archive.Dispose() }
}
finally { $stream.Dispose() }

# Read the archive back and insist on it, rather than trusting the loop above.
# A single backslashed entry means the whole upload is unusable on the host.
$verify = [System.IO.Compression.ZipFile]::OpenRead($zip)
try {
    $bad = @($verify.Entries | Where-Object { $_.FullName.Contains('\') })
    $hasIndex = @($verify.Entries | Where-Object { $_.FullName -eq 'public/index.php' }).Count -eq 1
    $hasLoader = @($verify.Entries | Where-Object { $_.FullName -eq 'vendor/autoload.php' }).Count -eq 1
}
finally { $verify.Dispose() }

if ($bad.Count) {
    throw "$($bad.Count) entries use backslashes, starting with '$($bad[0].FullName)'. This archive would not extract correctly on a Linux host."
}
if (-not $hasIndex)  { throw 'public/index.php is not in the archive at that path.' }
if (-not $hasLoader) { throw 'vendor/autoload.php is not in the archive at that path.' }

$size = [math]::Round((Get-Item $zip).Length / 1MB, 1)

Write-Host ''
Write-Host "  $zip  ($size MB)" -ForegroundColor Cyan
Write-Host ''
Write-Host '  1. Upload and extract it on the host.' -ForegroundColor DarkGray
Write-Host '  2. Point the domain document root at its public/ directory.' -ForegroundColor DarkGray
Write-Host '  3. Open https://<your domain>/install and fill the form.' -ForegroundColor DarkGray
Write-Host '  4. Add the cron entry it gives you, then delete the installer.' -ForegroundColor DarkGray
Write-Host ''
