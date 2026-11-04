<#
.SYNOPSIS
    Start the API and the admin panel together.

.DESCRIPTION
    Both halves have to agree on one port, and wiring that by hand is how you
    end up with the admin panel proxying to somebody else's application and
    reporting 404 on every request.

    This finds a free port for the API, writes it into web/.env so the panel's
    dev server proxies to the right place, and opens each service in its own
    window.

    Rerunning it is safe. An API or a panel already running is reused rather
    than duplicated, so bringing one back after it died does not leave two of
    the other on different ports.

.PARAMETER ApiPort
    Force a specific API port instead of picking the first free one.

.EXAMPLE
    .\dev.ps1
    .\dev.ps1 -ApiPort 8010
#>
[CmdletBinding()]
param(
    [int] $ApiPort = 0,
    [int] $WebPort = 5173
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

function Test-PortFree([int] $Port) {
    # Listening sockets only: a TIME_WAIT entry from a server that has already
    # stopped would otherwise look like the port is taken.
    $null -eq (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

function Write-TextFile([string] $Path, [string] $Text) {
    # `Set-Content -Encoding utf8` writes a BOM on Windows PowerShell 5.1, and
    # in a .env file the BOM becomes part of the first key's name, so the
    # setting silently goes missing and the client falls back to a default
    # port. Write the bytes ourselves.
    [System.IO.File]::WriteAllText($Path, $Text, (New-Object System.Text.UTF8Encoding $false))
}

function Test-ServiceWindow([string] $Title) {
    # Whether one of our own windows is already running this service.
    #
    # The port on its own is not enough. Vite stops listening for a moment
    # while it restarts after a config change, and a run that happens to land
    # in that gap starts a second one, which falls back to 5174. Both then sit
    # there serving the same application on different ports, which is a
    # confusing thing to debug and an easy thing to avoid.
    $null -ne (Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like "*WindowTitle = '$Title'*" } |
        Select-Object -First 1)
}

function Test-GmsApi([int] $Port) {
    # "Is something listening" is not the question - the port may hold another
    # Laravel application entirely, which answers every /api/* route with its
    # own 404 and makes the admin panel look broken. Ask for a route only this
    # API serves and require a clean answer.
    try {
        $r = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/api/branding" `
            -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop
        return $r.StatusCode -eq 200
    }
    catch {
        return $false
    }
}

function Find-FreePort([int] $Start, [int] $Attempts = 20) {
    for ($port = $Start; $port -lt ($Start + $Attempts); $port++) {
        if (Test-PortFree $port) { return $port }
    }

    throw "No free port between $Start and $($Start + $Attempts)."
}

# ---------------------------------------------------------------- API port

$startApi = $true

if ($ApiPort -eq 0) {
    # An API of ours already running is the good case, not a clash: closing the
    # admin panel's window and reopening it should not strand every client on a
    # port that died with the last run.
    $running = 8000..8019 | Where-Object { -not (Test-PortFree $_) -and (Test-GmsApi $_) } | Select-Object -First 1

    if ($running) {
        $ApiPort = $running
        $startApi = $false
        Write-Host "Reusing the API already running on port $ApiPort." -ForegroundColor Cyan
        Write-Host ""
    }
    else {
        $ApiPort = Find-FreePort 8000

        if ($ApiPort -ne 8000) {
            Write-Host "Port 8000 is held by another application." -ForegroundColor Yellow
            Write-Host "Using $ApiPort instead." -ForegroundColor Yellow
            Write-Host ""
        }
    }
}
elseif (-not (Test-PortFree $ApiPort)) {
    if (Test-GmsApi $ApiPort) {
        $startApi = $false
        Write-Host "Reusing the API already running on port $ApiPort." -ForegroundColor Cyan
        Write-Host ""
    }
    else {
        throw "Port $ApiPort is held by something that is not this API. Pick another with -ApiPort, or stop whatever is on it."
    }
}

$apiUrl = "http://127.0.0.1:$ApiPort"

# -------------------------------------------------- point the panel at it

# The admin panel proxies /api through its dev server.
$webEnv = Join-Path $root 'web\.env'
$webLines = @(
    "# Written by dev.ps1 - the API port it chose for this run.",
    "API_PROXY_TARGET=$apiUrl",
    "WEB_PORT=$WebPort"
)
Write-TextFile $webEnv (($webLines -join "`r`n") + "`r`n")

# ------------------------------------------------------------------ launch

function Start-Service([string] $Title, [string] $Directory, [string] $Command) {
    Start-Process powershell -ArgumentList @(
        '-NoExit', '-Command',
        "`$Host.UI.RawUI.WindowTitle = '$Title'; Set-Location '$Directory'; $Command"
    )
}

if ($startApi) {
    Start-Service 'GMS API' (Join-Path $root 'api') "php artisan serve --host=127.0.0.1 --port=$ApiPort"
    Start-Sleep -Seconds 2
}

if ((Test-PortFree $WebPort) -and -not (Test-ServiceWindow 'GMS Admin')) {
    Start-Service 'GMS Admin' (Join-Path $root 'web') 'npm run dev'
}
else {
    Write-Host "Reusing the admin panel already running on port $WebPort." -ForegroundColor Cyan
    Write-Host ""
}

# ------------------------------------------------------------------- report

Write-Host ""
Write-Host "  API           $apiUrl" -ForegroundColor Green
Write-Host "  Website       $apiUrl/  (publish it under Website in the admin panel)" -ForegroundColor Green
Write-Host "  Admin panel   http://localhost:$WebPort" -ForegroundColor Green
Write-Host ""
Write-Host "  Sign in as    admin@gms.local / ChangeMe123!" -ForegroundColor DarkGray
Write-Host "  Each service has its own window. Close a window to stop it." -ForegroundColor DarkGray
Write-Host ""
