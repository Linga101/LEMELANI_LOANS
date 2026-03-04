param(
    [string]$EnvFile = "c:\xampp\htdocs\LEMELANI_LOANS\.env",
    [switch]$Strict
)

$ErrorActionPreference = "Stop"

function Parse-EnvFile {
    param([string]$Path)
    $map = @{}
    if (!(Test-Path $Path)) {
        throw "Env file not found: $Path"
    }
    foreach ($lineRaw in Get-Content $Path) {
        $line = $lineRaw.Trim()
        if ($line -eq "" -or $line.StartsWith("#")) { continue }
        $idx = $line.IndexOf("=")
        if ($idx -lt 1) { continue }
        $k = $line.Substring(0, $idx).Trim()
        $v = $line.Substring($idx + 1).Trim()
        if (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'"))) {
            $v = $v.Substring(1, $v.Length - 2)
        }
        $map[$k] = $v
    }
    return $map
}

function Is-Truthy([string]$v) {
    if ($null -eq $v -or $v.Trim() -eq "") { return $false }
    return @("1", "true", "yes", "on") -contains $v.Trim().ToLower()
}

function Is-Falsy([string]$v) {
    if ($null -eq $v -or $v.Trim() -eq "") { return $false }
    return @("0", "false", "no", "off") -contains $v.Trim().ToLower()
}

function Require-NonEmpty {
    param($Map, [string]$Key, [string]$Reason, [System.Collections.Generic.List[object]]$Issues)
    $v = if ($Map.ContainsKey($Key)) { [string]$Map[$Key] } else { "" }
    if ($v.Trim() -eq "") {
        $Issues.Add([pscustomobject]@{ severity="ERROR"; key=$Key; message="Missing value ($Reason)" })
    }
}

function Require-BooleanFalse {
    param($Map, [string]$Key, [string]$Reason, [System.Collections.Generic.List[object]]$Issues)
    $v = if ($Map.ContainsKey($Key)) { [string]$Map[$Key] } else { "" }
    if (-not (Is-Falsy $v)) {
        $Issues.Add([pscustomobject]@{ severity="ERROR"; key=$Key; message="Must be false in production ($Reason)" })
    }
}

function Get-OrEmpty {
    param($Map, [string]$Key)
    if ($Map.ContainsKey($Key)) {
        return [string]$Map[$Key]
    }
    return ""
}

$envMap = Parse-EnvFile -Path $EnvFile
$issues = [System.Collections.Generic.List[object]]::new()

# Core app/database
Require-NonEmpty -Map $envMap -Key "APP_ENV" -Reason "set production or staging explicitly" -Issues $issues
Require-NonEmpty -Map $envMap -Key "APP_BASE_PATH" -Reason "routing base path required" -Issues $issues
Require-NonEmpty -Map $envMap -Key "DB_HOST" -Reason "database host required" -Issues $issues
Require-NonEmpty -Map $envMap -Key "DB_NAME" -Reason "database name required" -Issues $issues
Require-NonEmpty -Map $envMap -Key "DB_USER" -Reason "database user required" -Issues $issues

# Next.js cutover
Require-NonEmpty -Map $envMap -Key "NEXTJS_BASE_URL" -Reason "hybrid/cutover routing target required" -Issues $issues

$customerFlags = @(
    "FF_NEXTJS_AUTH","FF_NEXTJS_DASHBOARD","FF_NEXTJS_LOANS","FF_NEXTJS_REPAYMENTS","FF_NEXTJS_PROFILE","FF_NEXTJS_CREDIT_HISTORY","FF_NEXTJS_NOTIFICATIONS"
)
$adminFlags = @(
    "FF_NEXTJS_ADMIN_DASHBOARD","FF_NEXTJS_ADMIN_LOANS","FF_NEXTJS_ADMIN_USERS","FF_NEXTJS_ADMIN_VERIFICATIONS","FF_NEXTJS_ADMIN_PAYMENTS","FF_NEXTJS_ADMIN_SETTINGS","FF_NEXTJS_ADMIN_REPORTS","FF_NEXTJS_ADMIN_PLATFORM_ACCOUNTS"
)

$enabledCustomer = 0
foreach ($f in $customerFlags) {
    if (Is-Truthy ($envMap[$f])) { $enabledCustomer++ }
}
$enabledAdmin = 0
foreach ($f in $adminFlags) {
    if (Is-Truthy ($envMap[$f])) { $enabledAdmin++ }
}

if (-not (Is-Truthy $envMap["FF_NEXTJS_ALL"]) -and $enabledCustomer -lt $customerFlags.Count) {
    $issues.Add([pscustomobject]@{ severity="WARN"; key="FF_NEXTJS_* (customer)"; message="Customer cutover flags not fully enabled" })
}
if (-not (Is-Truthy $envMap["FF_NEXTJS_ALL"]) -and $enabledAdmin -lt $adminFlags.Count) {
    $issues.Add([pscustomobject]@{ severity="WARN"; key="FF_NEXTJS_ADMIN_*"; message="Admin cutover flags not fully enabled" })
}

# Workers should not be dry-run in production.
foreach ($k in @("WORKER_REMINDERS_DRY_RUN","WORKER_SCORING_DRY_RUN","WORKER_PAYMENTS_DRY_RUN","WORKER_WEBHOOKS_DRY_RUN")) {
    Require-BooleanFalse -Map $envMap -Key $k -Reason "worker must run live for production" -Issues $issues
}

# Webhook secrets
Require-NonEmpty -Map $envMap -Key "WEBHOOK_SECRET_AIRTEL_MONEY" -Reason "required to validate incoming webhooks" -Issues $issues
Require-NonEmpty -Map $envMap -Key "WEBHOOK_SECRET_TNM_MPAMBA" -Reason "required to validate incoming webhooks" -Issues $issues
Require-NonEmpty -Map $envMap -Key "WEBHOOK_SECRET_CARD_GATEWAY" -Reason "required to validate incoming webhooks" -Issues $issues

# Object storage checks if enabled.
if ((Get-OrEmpty $envMap "FILE_STORAGE_BACKEND").Trim().ToLower() -eq "object") {
    Require-NonEmpty -Map $envMap -Key "OBJECT_STORAGE_PROVIDER" -Reason "object backend selected" -Issues $issues
    $provider = (Get-OrEmpty $envMap "OBJECT_STORAGE_PROVIDER").Trim().ToLower()
    if ($provider -eq "s3") {
        foreach ($k in @("OBJECT_STORAGE_S3_BUCKET","OBJECT_STORAGE_S3_REGION")) {
            Require-NonEmpty -Map $envMap -Key $k -Reason "required for S3 provider" -Issues $issues
        }
        if ((Get-OrEmpty $envMap "OBJECT_STORAGE_S3_KEY").Trim() -eq "" -or (Get-OrEmpty $envMap "OBJECT_STORAGE_S3_SECRET").Trim() -eq "") {
            $issues.Add([pscustomobject]@{ severity="WARN"; key="OBJECT_STORAGE_S3_KEY/SECRET"; message="Credentials appear empty; only valid if IAM role/environment credentials are injected at runtime" })
        }
    }
}

Write-Host "Production Env Validation"
Write-Host "========================="
Write-Host "Env file: $EnvFile"
Write-Host ""

if ($issues.Count -eq 0) {
    Write-Host "PASS: no issues detected."
    exit 0
}

$errorCount = 0
$warnCount = 0
foreach ($i in $issues) {
    if ($i.severity -eq "ERROR") {
        $errorCount++
        Write-Host "[ERROR] $($i.key): $($i.message)"
    } else {
        $warnCount++
        Write-Host "[WARN ] $($i.key): $($i.message)"
    }
}

Write-Host ""
Write-Host "Summary: errors=$errorCount warnings=$warnCount"

if ($errorCount -gt 0) { exit 2 }
if ($Strict -and $warnCount -gt 0) { exit 3 }
exit 0
