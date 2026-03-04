param(
    [Parameter(Mandatory = $true)]
    [ValidateSet("legacy", "customer_full", "admin_full", "full_cutover", "rollback")]
    [string]$Profile,
    [string]$EnvFile = "c:\xampp\htdocs\LEMELANI_LOANS\.env",
    [switch]$Apply
)

$ErrorActionPreference = "Stop"

function Parse-EnvFile {
    param([string]$Path)
    $entries = [System.Collections.Generic.List[object]]::new()
    if (!(Test-Path $Path)) {
        return $entries
    }

    $lines = Get-Content $Path
    foreach ($line in $lines) {
        $trim = $line.Trim()
        if ($trim -eq "" -or $trim.StartsWith("#") -or $trim.IndexOf("=") -lt 1) {
            $entries.Add([pscustomobject]@{
                Type = "raw"
                Raw = $line
            })
            continue
        }

        $idx = $line.IndexOf("=")
        $key = $line.Substring(0, $idx).Trim()
        $value = $line.Substring($idx + 1)
        $entries.Add([pscustomobject]@{
            Type = "kv"
            Key = $key
            Value = $value
        })
    }

    return $entries
}

function Render-EnvFile {
    param($Entries)
    $out = New-Object System.Collections.Generic.List[string]
    foreach ($entry in $Entries) {
        if ($entry.Type -eq "kv") {
            $out.Add("$($entry.Key)=$($entry.Value)")
        } else {
            $out.Add($entry.Raw)
        }
    }
    return $out
}

function Set-EnvValue {
    param($Entries, [string]$Key, [string]$Value)
    $found = $false
    $indexesToRemove = @()
    $index = 0
    foreach ($entry in $Entries) {
        if ($entry.Type -eq "kv" -and $entry.Key -eq $Key) {
            if (-not $found) {
                $entry.Value = $Value
                $found = $true
            } else {
                $indexesToRemove += $index
            }
        }
        $index++
    }
    if ($indexesToRemove.Count -gt 0) {
        foreach ($i in ($indexesToRemove | Sort-Object -Descending)) {
            $Entries.RemoveAt([int]$i)
        }
    }
    if (-not $found) {
        $Entries.Add([pscustomobject]@{
            Type = "kv"
            Key = $Key
            Value = $Value
        })
    }
}

function Build-ProfileMap {
    param([string]$Name)

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

    $map = @{}
    $map["FF_NEXTJS_ALL"] = "false"
    $map["FF_NEXTJS_CUSTOMER_ALL"] = "false"
    $map["FF_NEXTJS_ADMIN_ALL"] = "false"

    foreach ($f in $customerFlags + $adminFlags) { $map[$f] = "false" }

    switch ($Name) {
        "legacy" {
            # all false already
            break
        }
        "customer_full" {
            $map["FF_NEXTJS_CUSTOMER_ALL"] = "true"
            foreach ($f in $customerFlags) { $map[$f] = "true" }
            break
        }
        "admin_full" {
            $map["FF_NEXTJS_ADMIN_ALL"] = "true"
            foreach ($f in $adminFlags) { $map[$f] = "true" }
            break
        }
        "full_cutover" {
            $map["FF_NEXTJS_ALL"] = "true"
            foreach ($f in $customerFlags + $adminFlags) { $map[$f] = "true" }
            break
        }
        "rollback" {
            # Explicitly disable all
            break
        }
    }

    return $map
}

$entries = Parse-EnvFile -Path $EnvFile
$entriesList = [System.Collections.Generic.List[object]]::new()
foreach ($entry in $entries) {
    $entriesList.Add($entry)
}
$profileMap = Build-ProfileMap -Name $Profile

foreach ($k in $profileMap.Keys) {
    Set-EnvValue -Entries $entriesList -Key $k -Value $profileMap[$k]
}

Write-Host "Cutover profile preview ($Profile):"
$orderedKeys = $profileMap.Keys | Sort-Object
foreach ($k in $orderedKeys) {
    Write-Host ("- " + $k + "=" + $profileMap[$k])
}

if ($Apply) {
    $rendered = Render-EnvFile -Entries $entriesList
    Set-Content -Path $EnvFile -Value $rendered -Encoding UTF8
    Write-Host ""
    Write-Host "Applied profile '$Profile' to $EnvFile"
} else {
    Write-Host ""
    Write-Host "Preview only. Re-run with -Apply to write changes."
}
