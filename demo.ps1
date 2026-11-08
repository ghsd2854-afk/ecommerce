# Interview demo for Requirements 1 and 2.
# Each scenario runs against the RUNNING project and shows the result in seconds.
#   .\demo.cmd <scenario>        (from PowerShell or cmd, inside the project folder)
param([string]$Scenario = "help")

Set-Location $PSScriptRoot

function Run-Demo([string]$name) {
    docker compose --profile tools run --rm demo node scripts/demo.js $name
}

function Wait-Seconds([int]$s, [string]$why) {
    Write-Host "    ($why, waiting $s s...)" -ForegroundColor DarkGray
    Start-Sleep -Seconds $s
}

function Set-SaveFailure([string]$value) {
    $path = Join-Path $PSScriptRoot "src\.env"
    $text = [IO.File]::ReadAllText($path)
    if ($text -match '(?m)^SIMULATE_ORDER_SAVE_FAILURE=') {
        $text = [regex]::Replace($text, '(?m)^SIMULATE_ORDER_SAVE_FAILURE=[^\r\n]*', "SIMULATE_ORDER_SAVE_FAILURE=$value")
    } else {
        $text += "`r`nSIMULATE_ORDER_SAVE_FAILURE=$value`r`n"
    }
    [IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false)))
    docker compose exec app php artisan optimize
    docker compose restart app
    Wait-Seconds 3 "PHP is restarting"
}

function Scenario-Down {
    Write-Host "`n>>> docker compose stop inventory" -ForegroundColor Yellow
    docker compose stop inventory
    try { Run-Demo down }
    finally {
        Write-Host "`n>>> docker compose start inventory" -ForegroundColor Yellow
        docker compose start inventory
        Wait-Seconds 4 "inventory service is starting"
    }
}

function Scenario-Slow {
    Write-Host "`n>>> inventory service restarted with a 5 s delay on every call" -ForegroundColor Yellow
    $env:INVENTORY_DELAY_MS = "5000"
    docker compose up -d inventory
    Wait-Seconds 4 "inventory service is starting"
    try { Run-Demo slow }
    finally {
        Write-Host "`n>>> inventory service back to normal" -ForegroundColor Yellow
        $env:INVENTORY_DELAY_MS = "0"
        docker compose up -d inventory
        Wait-Seconds 4 "inventory service is starting"
    }
}

function Scenario-SaveFail {
    Write-Host "`n>>> SIMULATE_ORDER_SAVE_FAILURE=true (demo switch, works only with APP_ENV=local)" -ForegroundColor Yellow
    Set-SaveFailure "true"
    try { Run-Demo savefail }
    finally {
        Write-Host "`n>>> SIMULATE_ORDER_SAVE_FAILURE=false" -ForegroundColor Yellow
        Set-SaveFailure "false"
    }
}

function Scenario-Race {
    Write-Host "`n=== Requirement 2 - Race condition: many orders for the LAST unit at the same moment ===" -ForegroundColor Cyan
    docker compose exec inventory node scripts/race-test.js --product=8 --requests=10
}

function Reset-Stock {
    Write-Host "`n>>> resetting stock to the initial quantities" -ForegroundColor Yellow
    $seed = @{ 1 = 50; 2 = 30; 3 = 25; 4 = 10; 5 = 40; 6 = 60; 7 = 5; 8 = 1 }
    foreach ($id in ($seed.Keys | Sort-Object)) {
        docker compose exec inventory node scripts/set-stock.js $id $seed[$id] | Out-Null
    }
    docker compose exec inventory node scripts/set-stock.js
}

switch ($Scenario.ToLower()) {
    "auth"      { Run-Demo auth }
    "api"       { Run-Demo api }
    "stock"     { Run-Demo stock }
    "down"      { Scenario-Down }
    "slow"      { Scenario-Slow }
    "savefail"  { Scenario-SaveFail }
    "race"      { Scenario-Race }
    "ratelimit" { Run-Demo ratelimit }
    "tests" {
        # The tests use an in-memory SQLite database; all caches (config, routes) must be cleared first,
        # otherwise Laravel would ignore phpunit.xml (TestCase stops in that case).
        docker compose exec app php artisan optimize:clear
        try { docker compose exec app php artisan test }
        finally { docker compose exec app php artisan optimize }
    }
    "reset"     { Reset-Stock }
    "all" {
        Run-Demo all            # auth + api + stock
        Scenario-Down
        Scenario-Slow
        Scenario-SaveFail
        Scenario-Race
        Run-Demo ratelimit      # last: blocks login for one minute
    }
    default {
        Write-Host @"
Usage:  .\demo.cmd <scenario>

  Requirement 1
    auth       JWT, roles, ownership: another user's order (403), admin-only actions (403), no / forged token (401)
    api        /api/v1 versioning, pagination, category filter, 422 / 404 / 405 JSON errors, security headers
    ratelimit  6 wrong logins -> 429 (blocks login for 1 minute, run it last)

  Requirement 2
    stock      stock from the inventory service (gRPC), not enough stock (409), all-or-nothing reservation
    down       stops the inventory service -> product page still works, order gets a clear 503
    slow       5 s delay in the inventory service -> order gets a clear 504 after the 2 s deadline
    savefail   saving fails after reserving -> 500 and the stock is returned
    race       10 simultaneous orders for the last unit -> exactly one succeeds

  Other
    tests      automated tests (php artisan test)
    reset      put the stock back to the initial quantities
    all        everything above, in order
"@
    }
}
