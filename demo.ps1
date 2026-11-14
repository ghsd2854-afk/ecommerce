# Interview demo for Requirements 1, 2 and 3.
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

# ---------------- Requirement 3 ----------------

function Artisan {
    docker compose exec app php artisan @args
}

function Replica-Sql([string]$sql) {
    docker compose exec -T -e MYSQL_PWD=root db-replica mysql -uroot -N -e $sql
}

function Primary-Sql([string]$sql) {
    docker compose exec -T -e MYSQL_PWD=root db mysql -uroot -N -e $sql
}

function Scenario-Cache {
    Run-Demo cache
    Write-Host "`n>>> Redis: some of the cached keys and the hit / miss counters" -ForegroundColor Yellow
    docker compose exec redis redis-cli -n 1 --scan --pattern "*products:v*" | Select-Object -First 5
    docker compose exec redis redis-cli info stats | Select-String "keyspace_hits|keyspace_misses"
}

function Scenario-Replica {
    Write-Host "`n=== Requirement 3 - Read replica: primary for writes, replica for reads ===" -ForegroundColor Cyan
    Artisan perf:replica
    Write-Host "`n>>> pausing the replica (STOP REPLICA SQL_THREAD): it stops applying changes = a big replication lag" -ForegroundColor Yellow
    Replica-Sql "STOP REPLICA SQL_THREAD"
    try {
        Run-Demo replica-edit
        Write-Host "`n>>> product #1 in each database right now:" -ForegroundColor Yellow
        Primary-Sql "select concat('primary: ', name) from shop.products where id = 1"
        Replica-Sql "select concat('replica: ', name) from shop.products where id = 1"
        Write-Host "    the replica still has the OLD name, but the API showed the NEW one (read-your-writes)" -ForegroundColor DarkGray
    }
    finally {
        Write-Host "`n>>> START REPLICA SQL_THREAD: the replica catches up" -ForegroundColor Yellow
        Replica-Sql "START REPLICA SQL_THREAD"
        Wait-Seconds 2 "replica is applying the changes"
        Replica-Sql "select concat('replica: ', name) from shop.products where id = 1"
        Run-Demo replica-restore
    }
}

function K6([string]$label) {
    Write-Host "`n>>> k6: $label ($script:Vus virtual users, $script:Duration)" -ForegroundColor Yellow
    docker compose --profile tools run --rm k6 run --quiet -e LABEL=$label -e VUS=$script:Vus -e DURATION=$script:Duration /perf/k6/load-test.js
}

function Show-Report([string[]]$labels) {
    docker compose --profile tools run --rm demo node /perf/compare.js @labels
}

$script:Vus = if ($env:VUS) { $env:VUS } else { "5" }   # 5 virtual users: fits a laptop (more = they only wait in the queue)
$script:Duration = if ($env:DURATION) { $env:DURATION } else { "30s" }

function Scenario-Perf {
    Write-Host "`n=== Requirement 3 - k6 load test: BEFORE (no optimizations) vs AFTER ===" -ForegroundColor Cyan
    Artisan perf:mode baseline "--load-test=on"
    try {
        K6 before
        Artisan perf:mode optimized
        K6 after
    }
    finally {
        Artisan perf:mode optimized "--load-test=off"
    }
    Show-Report @("before", "after")
}

function Scenario-PerfSteps {
    Write-Host "`n=== Requirement 3 - measure -> improve ONE thing -> measure ===" -ForegroundColor Cyan
    Artisan perf:mode baseline "--load-test=on"
    try {
        K6 1-baseline
        Artisan perf:mode "--indexes=on"
        K6 2-indexes
        Artisan perf:mode "--eager=on"
        K6 3-eager
        Artisan perf:mode "--cache=on"
        K6 4-cache
        Artisan perf:mode "--etag=on"
        K6 5-etag
    }
    finally {
        Artisan perf:mode optimized "--load-test=off"
    }
    Show-Report @("1-baseline", "2-indexes", "3-eager", "4-cache", "5-etag")
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
    "seed"      { Artisan perf:seed }
    "clean"     { Artisan perf:clean "--force" }
    "explain"   { Artisan perf:explain "--compare" }
    "nplus1"    { Artisan perf:nplus1 }
    "cache"     { Scenario-Cache }
    "etag"      { Run-Demo etag }
    "replica"   { Scenario-Replica }
    "perf"      { Scenario-Perf }
    "perfsteps" { Scenario-PerfSteps }
    "mode"      { Artisan perf:mode }
    "resync"    {
        # Rebuilds the replica from a fresh copy of the primary (if it was broken / reset).
        docker compose run --rm -e FORCE=1 db-replica-setup
    }
    "req3" {
        Artisan perf:explain "--compare"
        Artisan perf:nplus1
        Scenario-Cache
        Run-Demo etag
        Scenario-Replica
    }
    "all" {
        Run-Demo all            # auth + api + stock
        Scenario-Down
        Scenario-Slow
        Scenario-SaveFail
        Scenario-Race
        Scenario-Cache
        Run-Demo etag
        Scenario-Replica
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

  Requirement 3   (first, once: seed)
    seed       large fake data: 2,000 customers, 100,000 products, 300,000 orders (about 1-3 min)
    explain    EXPLAIN + time of the slow queries, BEFORE vs AFTER the indexes
    nplus1     number of SQL queries of GET /orders: N+1 vs eager loading
    cache      Redis: cache miss vs hit, a change is visible immediately (invalidation)
    etag       ETag / If-None-Match -> 304 Not Modified, 200 after a change
    replica    primary vs replica, replication lag, read-your-writes while the replica is paused
    perf       k6 load test BEFORE vs AFTER -> table p50 / p95 / p99 / requests per second (about 1.5 min)
    perfsteps  k6 after each single improvement (baseline, +indexes, +eager, +cache, +etag) (about 3 min)
    mode       which optimizations are on
    req3       explain + nplus1 + cache + etag + replica
    clean      delete the fake data of "seed" again
    resync     rebuild the read replica from a fresh copy of the primary (only if it broke)

  Other
    tests      automated tests (php artisan test)
    reset      put the stock back to the initial quantities
    all        all quick scenarios above, in order (not seed / perf)
"@
    }
}
