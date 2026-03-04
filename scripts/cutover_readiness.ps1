param(
    [string]$EnvFile = "c:\xampp\htdocs\LEMELANI_LOANS\.env"
)

$ErrorActionPreference = "Stop"

function Parse-EnvFile {
    param([string]$Path)
    $map = @{}
    if (!(Test-Path $Path)) {
        return $map
    }
    Get-Content $Path | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq "" -or $line.StartsWith("#")) { return }
        $idx = $line.IndexOf("=")
        if ($idx -lt 1) { return }
        $k = $line.Substring(0, $idx).Trim()
        $v = $line.Substring($idx + 1).Trim()
        if (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'"))) {
            $v = $v.Substring(1, $v.Length - 2)
        }
        $map[$k] = $v
    }
    return $map
}

function Is-Truthy {
    param([string]$Value)
    if ($null -eq $Value) { return $false }
    $v = $Value.Trim().ToLower()
    return @("1", "true", "yes", "on") -contains $v
}

function Get-FlagValue {
    param($Map, [string]$Name)
    if ($Map.ContainsKey($Name)) { return [string]$Map[$Name] }
    return ""
}

$envMap = Parse-EnvFile -Path $EnvFile

$customerFlags = @(
    "FF_NEXTJS_AUTH",
    "FF_NEXTJS_DASHBOARD",
    "FF_NEXTJS_LOANS",
    "FF_NEXTJS_REPAYMENTS",
    "FF_NEXTJS_PROFILE",
    "FF_NEXTJS_CREDIT_HISTORY",
    "FF_NEXTJS_NOTIFICATIONS"
)

$adminFlags = @(
    "FF_NEXTJS_ADMIN_DASHBOARD",
    "FF_NEXTJS_ADMIN_LOANS",
    "FF_NEXTJS_ADMIN_USERS",
    "FF_NEXTJS_ADMIN_VERIFICATIONS",
    "FF_NEXTJS_ADMIN_PAYMENTS",
    "FF_NEXTJS_ADMIN_SETTINGS",
    "FF_NEXTJS_ADMIN_REPORTS",
    "FF_NEXTJS_ADMIN_PLATFORM_ACCOUNTS"
)

$allGlobal = Is-Truthy (Get-FlagValue $envMap "FF_NEXTJS_ALL")
$customerGlobal = Is-Truthy (Get-FlagValue $envMap "FF_NEXTJS_CUSTOMER_ALL")
$adminGlobal = Is-Truthy (Get-FlagValue $envMap "FF_NEXTJS_ADMIN_ALL")

function Effective-Enabled {
    param($Map, [string]$Flag, [bool]$allGlobal, [bool]$customerGlobal, [bool]$adminGlobal)
    $explicit = Get-FlagValue $Map $Flag
    if ($explicit -ne "") { return Is-Truthy $explicit }
    if ($allGlobal) { return $true }
    if ($Flag.StartsWith("FF_NEXTJS_ADMIN_") -and $adminGlobal) { return $true }
    if ($Flag.StartsWith("FF_NEXTJS_") -and -not $Flag.StartsWith("FF_NEXTJS_ADMIN_") -and $customerGlobal) { return $true }
    return $false
}

$customerEnabled = 0
foreach ($flag in $customerFlags) {
    if (Effective-Enabled $envMap $flag $allGlobal $customerGlobal $adminGlobal) { $customerEnabled++ }
}
$adminEnabled = 0
foreach ($flag in $adminFlags) {
    if (Effective-Enabled $envMap $flag $allGlobal $customerGlobal $adminGlobal) { $adminEnabled++ }
}

$workerDryRun = @{
    reminders = Get-FlagValue $envMap "WORKER_REMINDERS_DRY_RUN"
    scoring = Get-FlagValue $envMap "WORKER_SCORING_DRY_RUN"
    payments = Get-FlagValue $envMap "WORKER_PAYMENTS_DRY_RUN"
    webhooks = Get-FlagValue $envMap "WORKER_WEBHOOKS_DRY_RUN"
}

function Is-Explicit-False {
    param([string]$Value)
    if ($null -eq $Value -or $Value.Trim() -eq "") { return $false }
    $v = $Value.Trim().ToLower()
    return @("0", "false", "no", "off") -contains $v
}

Write-Host "Cutover Readiness Report"
Write-Host "========================"
Write-Host "Env file: $EnvFile"
Write-Host ""
Write-Host "Global flags:"
Write-Host ("- FF_NEXTJS_ALL: " + (Get-FlagValue $envMap "FF_NEXTJS_ALL"))
Write-Host ("- FF_NEXTJS_CUSTOMER_ALL: " + (Get-FlagValue $envMap "FF_NEXTJS_CUSTOMER_ALL"))
Write-Host ("- FF_NEXTJS_ADMIN_ALL: " + (Get-FlagValue $envMap "FF_NEXTJS_ADMIN_ALL"))
Write-Host ""
Write-Host "Customer surface flags: $customerEnabled / $($customerFlags.Count) enabled"
Write-Host "Admin surface flags: $adminEnabled / $($adminFlags.Count) enabled"
Write-Host ""
Write-Host "Worker dry-run status:"
Write-Host ("- reminders: " + $workerDryRun.reminders)
Write-Host ("- scoring: " + $workerDryRun.scoring)
Write-Host ("- payments: " + $workerDryRun.payments)
Write-Host ("- webhooks: " + $workerDryRun.webhooks)
Write-Host ""

$isCustomerCutover = ($customerEnabled -eq $customerFlags.Count)
$isAdminCutover = ($adminEnabled -eq $adminFlags.Count)
$isWorkersLive =
    (Is-Explicit-False $workerDryRun.reminders) -and
    (Is-Explicit-False $workerDryRun.scoring) -and
    (Is-Explicit-False $workerDryRun.payments) -and
    (Is-Explicit-False $workerDryRun.webhooks)

Write-Host "Readiness Summary:"
Write-Host ("- Customer cutover ready: " + ($(if ($isCustomerCutover) { "YES" } else { "NO" })))
Write-Host ("- Admin cutover ready: " + ($(if ($isAdminCutover) { "YES" } else { "NO" })))
Write-Host ("- Workers live-ready (dry-run disabled): " + ($(if ($isWorkersLive) { "YES" } else { "NO" })))
