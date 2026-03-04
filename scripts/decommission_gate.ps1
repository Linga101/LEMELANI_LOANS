param(
    [string]$MarkersFile = "c:\xampp\htdocs\LEMELANI_LOANS\docs\release_markers.json",
    [string]$EnvFile = "c:\xampp\htdocs\LEMELANI_LOANS\.env",
    [switch]$Strict
)

$ErrorActionPreference = "Stop"

if (!(Test-Path $MarkersFile)) {
    throw "Markers file not found: $MarkersFile"
}

$markers = Get-Content $MarkersFile -Raw | ConvertFrom-Json
$fullCutoverDateText = [string]$markers.full_cutover_date
$stableDays = [int]$markers.minimum_stable_days_before_decommission
$requiredCycles = [int]$markers.required_successful_release_cycles

if ([string]::IsNullOrWhiteSpace($fullCutoverDateText)) {
    throw "full_cutover_date is missing in $MarkersFile"
}

$fullCutoverDate = [datetime]::ParseExact($fullCutoverDateText, "yyyy-MM-dd", $null)
$today = (Get-Date).Date
$daysElapsed = [int]($today - $fullCutoverDate.Date).TotalDays
$daysRemaining = [Math]::Max(0, $stableDays - $daysElapsed)

Write-Host "Decommission Gate Report"
Write-Host "========================"
Write-Host "Markers: $MarkersFile"
Write-Host "Full cutover date: $($fullCutoverDate.ToString('yyyy-MM-dd'))"
Write-Host "Days elapsed since cutover: $daysElapsed"
Write-Host "Minimum stable days required: $stableDays"
Write-Host "Required successful release cycles: $requiredCycles"
Write-Host ""

$ok = $true

if ($daysElapsed -lt $stableDays) {
    Write-Host "[BLOCK] Stable period not reached. Remaining days: $daysRemaining"
    $ok = $false
} else {
    Write-Host "[PASS ] Stable period requirement met."
}

Write-Host ""
Write-Host "Running production env validation..."
& "c:\xampp\htdocs\LEMELANI_LOANS\scripts\validate_production_env.ps1" -EnvFile $EnvFile
$validateExit = $LASTEXITCODE
if ($validateExit -ne 0) {
    Write-Host "[BLOCK] Production env validation failed (exit=$validateExit)."
    $ok = $false
} else {
    Write-Host "[PASS ] Production env validation passed."
}

Write-Host ""
if ($ok) {
    Write-Host "DECOMMISSION READY: YES"
    exit 0
}

Write-Host "DECOMMISSION READY: NO"
if ($Strict) {
    exit 2
}
exit 1
