<#
.SYNOPSIS
    Start the API, the admin panel, the member app and the staff app together.

.DESCRIPTION
    Four parts have to agree on one port, and wiring them by hand is how you
    end up with the admin panel proxying to somebody else's application and
    reporting 404 on every request.

    This finds a free port for the API, writes it into web/.env,
    mobile/app.json and mobile-staff/app.json so every client points at the
    right place, and opens each service in its own window.

.PARAMETER ApiPort
    Force a specific API port instead of picking the first free one.

.PARAMETER NoMobile
    Skip both phone apps. Useful when you are only working on the admin panel:
    Metro is the slowest thing here to start, and two of them together is a lot
    of memory.

.EXAMPLE
    .\dev.ps1
    .\dev.ps1 -ApiPort 8010
    .\dev.ps1 -NoMobile
#>
[CmdletBinding()]
param(
    [int] $ApiPort = 0,
    [int] $WebPort = 5173,
    [int] $MobilePort = 8081,
    [int] $StaffPort = 8088,
    [switch] $NoMobile
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

function Test-PortFree([int] $Port) {
    # Listening sockets only: a TIME_WAIT entry from a server that has already
    # stopped would otherwise look like the port is taken.
    $null -eq (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

function Write-TextFile([string] $Path, [string] $Text) {
    # `Set-Content -Encoding utf8` writes a BOM on Windows PowerShell 5.1. A BOM
    # makes app.json invalid JSON for a strict parser, and in a .env file it
    # becomes part of the first key's name, so the setting silently goes
    # missing and the client falls back to a default port. Write the bytes
    # ourselves.
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

# ------------------------------------------------------ the bundler ports

<#
    Metro's ports get the same treatment as the API's, and for a reason worth
    writing down: **iVMS-4200 listens on 8081**, which is Metro's default. Any
    machine that also runs the Hikvision client - which here is the same
    machine - has that port taken before Expo starts.

    Expo would normally offer another port, but in a window with nothing
    attached to its input it cannot ask, so the window simply closes and the
    phone app is quietly missing from a run that otherwise looks fine.
#>
if (-not $NoMobile) {
    $wantedMobile = $MobilePort
    $MobilePort = Find-FreePort $MobilePort

    # Searching from the staff default could land on the member app's port.
    $StaffPort = Find-FreePort ([Math]::Max($StaffPort, $MobilePort + 1))

    if ($MobilePort -ne $wantedMobile) {
        Write-Host "Port $wantedMobile is taken (iVMS-4200 uses it, if it is running)." -ForegroundColor Yellow
        Write-Host "The member app will use $MobilePort instead." -ForegroundColor Yellow
        Write-Host ""
    }
}

# ------------------------------------------------- point the clients at it

# The admin panel proxies /api through its dev server.
$webEnv = Join-Path $root 'web\.env'
$webLines = @(
    "# Written by dev.ps1 - the API port it chose for this run.",
    "API_PROXY_TARGET=$apiUrl",
    "WEB_PORT=$WebPort"
)
Write-TextFile $webEnv (($webLines -join "`r`n") + "`r`n")

# Both phone apps talk to the API directly, so they need the absolute URL.
#
# Expo inlines extra.apiUrl into the bundle when it builds, so editing app.json
# is not enough on its own: Metro will happily serve a cached bundle carrying
# the previous run's port, and the app fails with ERR_CONNECTION_REFUSED
# against a server that is no longer there. Note which apps actually changed so
# their Metro cache can be cleared, and only theirs - clearing costs about a
# minute of rebuild each.
$needsClear = @{}

foreach ($appDir in @('mobile', 'mobile-staff')) {
    $appJsonPath = Join-Path $root (Join-Path $appDir 'app.json')
    $needsClear[$appDir] = $false

    if (Test-Path $appJsonPath) {
        $appJson = Get-Content $appJsonPath -Raw | ConvertFrom-Json

        # localhost, not 127.0.0.1: src/lib/api.ts rewrites it to 10.0.2.2 for
        # Android emulators, which cannot reach the host by either loopback name.
        $wanted = "http://localhost:$ApiPort/api"
        $needsClear[$appDir] = $appJson.expo.extra.apiUrl -ne $wanted

        $appJson.expo.extra.apiUrl = $wanted
        Write-TextFile $appJsonPath (($appJson | ConvertTo-Json -Depth 20) + "`r`n")
    }
}

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

# Same reasoning as the API: rerunning this script to pick up a phone app that
# failed to start should not leave a second Vite behind on another port, with
# half the browser tabs pointing at whichever one answered first.
if ((Test-PortFree $WebPort) -and -not (Test-ServiceWindow 'GMS Admin')) {
    Start-Service 'GMS Admin' (Join-Path $root 'web') 'npm run dev'
}
else {
    Write-Host "Reusing the admin panel already running on port $WebPort." -ForegroundColor Cyan
    Write-Host ""
}

if (-not $NoMobile) {
    Start-Sleep -Seconds 2
    $memberClear = if ($needsClear['mobile']) { ' --clear' } else { '' }
    $staffClear = if ($needsClear['mobile-staff']) { ' --clear' } else { '' }

    # Same guard as the panel. Rerunning this to bring back a bundler that
    # died should not leave the surviving one duplicated on another port.
    if (-not (Test-ServiceWindow 'GMS Member App')) {
        Start-Service 'GMS Member App' (Join-Path $root 'mobile') "npx expo start --port $MobilePort$memberClear"
        Start-Sleep -Seconds 2
    }

    if (-not (Test-ServiceWindow 'GMS Staff App')) {
        Start-Service 'GMS Staff App' (Join-Path $root 'mobile-staff') "npx expo start --port $StaffPort$staffClear"
    }
}

# ------------------------------------------------------------------- report

Write-Host ""
Write-Host "  API           $apiUrl" -ForegroundColor Green
Write-Host "  Website       $apiUrl/  (publish it under Website in the admin panel)" -ForegroundColor Green
Write-Host "  Admin panel   http://localhost:$WebPort" -ForegroundColor Green

if (-not $NoMobile) {
    Write-Host "  Member app    http://localhost:$MobilePort  (or press a / i in its window)" -ForegroundColor Green
    Write-Host "  Staff app     http://localhost:$StaffPort  (or press a / i in its window)" -ForegroundColor Green
}

Write-Host ""
Write-Host "  Sign in as    admin@gms.local / ChangeMe123!" -ForegroundColor DarkGray
Write-Host "  Each service has its own window. Close a window to stop it." -ForegroundColor DarkGray
Write-Host ""
