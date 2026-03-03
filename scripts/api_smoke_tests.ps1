param(
    [string]$BaseUrl = "http://localhost/LEMELANI_LOANS/api/v1",
    [string]$UserEmail = "",
    [string]$UserPassword = "",
    [string]$AdminEmail = "",
    [string]$AdminPassword = ""
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Text)
    Write-Host "==> $Text"
}

function Invoke-ApiJson {
    param(
        [Parameter(Mandatory=$true)][string]$Method,
        [Parameter(Mandatory=$true)][string]$Url,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [hashtable]$Headers,
        $Body
    )

    $args = @{
        Method = $Method
        Uri = $Url
        ContentType = "application/json"
    }
    if ($null -ne $Session) { $args["WebSession"] = $Session }
    if ($null -ne $Headers) { $args["Headers"] = $Headers }
    if ($null -ne $Body) { $args["Body"] = ($Body | ConvertTo-Json -Depth 8) }

    $resp = Invoke-WebRequest @args
    return @{
        StatusCode = $resp.StatusCode
        Json = ($resp.Content | ConvertFrom-Json)
        Headers = $resp.Headers
    }
}

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) {
        throw "Assertion failed: $Message"
    }
}

if ([string]::IsNullOrWhiteSpace($UserEmail) -or [string]::IsNullOrWhiteSpace($UserPassword)) {
    throw "Provide -UserEmail and -UserPassword for smoke tests."
}

Write-Step "Using API base: $BaseUrl"

$userSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession

Write-Step "Get CSRF token (public)"
$csrfResp = Invoke-ApiJson -Method GET -Url "$BaseUrl/security/csrf-token" -Session $userSession
Assert-True ($csrfResp.StatusCode -eq 200) "Expected 200 for csrf-token"
$csrfToken = $csrfResp.Json.data.token
Assert-True (-not [string]::IsNullOrWhiteSpace($csrfToken)) "Missing CSRF token"

Write-Step "User login"
$loginResp = Invoke-ApiJson -Method POST -Url "$BaseUrl/auth/login" -Session $userSession -Body @{
    email = $UserEmail
    password = $UserPassword
}
Assert-True ($loginResp.StatusCode -eq 200) "Expected 200 for login"
Assert-True ($loginResp.Json.success -eq $true) "Login failed"

$authCsrf = $loginResp.Json.data.csrfToken
if ([string]::IsNullOrWhiteSpace($authCsrf)) { $authCsrf = $csrfToken }
$csrfHeaders = @{ "X-CSRF-Token" = $authCsrf }

Write-Step "Auth me"
$meResp = Invoke-ApiJson -Method GET -Url "$BaseUrl/auth/me" -Session $userSession
Assert-True ($meResp.StatusCode -eq 200) "Expected 200 for auth/me"

Write-Step "Customer dashboard summary"
$dashResp = Invoke-ApiJson -Method GET -Url "$BaseUrl/customer/dashboard/summary" -Session $userSession
Assert-True ($dashResp.StatusCode -eq 200) "Expected 200 for dashboard summary"

Write-Step "Customer loans list"
$loansResp = Invoke-ApiJson -Method GET -Url "$BaseUrl/customer/loans" -Session $userSession
Assert-True ($loansResp.StatusCode -eq 200) "Expected 200 for customer loans"

Write-Step "Customer payments history"
$paymentsResp = Invoke-ApiJson -Method GET -Url "$BaseUrl/customer/payments/history" -Session $userSession
Assert-True ($paymentsResp.StatusCode -eq 200) "Expected 200 for payments history"

Write-Step "Customer notifications list"
$notifResp = Invoke-ApiJson -Method GET -Url "$BaseUrl/customer/notifications" -Session $userSession
Assert-True ($notifResp.StatusCode -eq 200) "Expected 200 for notifications list"

Write-Step "Customer mark all notifications read"
$markAllResp = Invoke-ApiJson -Method POST -Url "$BaseUrl/customer/notifications/read-all" -Session $userSession -Headers $csrfHeaders
Assert-True ($markAllResp.StatusCode -eq 200) "Expected 200 for notifications/read-all"

if ($AdminEmail -and $AdminPassword) {
    Write-Step "Admin flow"
    $adminSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $adminLogin = Invoke-ApiJson -Method POST -Url "$BaseUrl/auth/login" -Session $adminSession -Body @{
        email = $AdminEmail
        password = $AdminPassword
    }
    Assert-True ($adminLogin.StatusCode -eq 200) "Expected 200 for admin login"
    $adminCsrf = $adminLogin.Json.data.csrfToken
    $adminHeaders = @{ "X-CSRF-Token" = $adminCsrf }

    $adminDash = Invoke-ApiJson -Method GET -Url "$BaseUrl/admin/dashboard/summary" -Session $adminSession
    Assert-True ($adminDash.StatusCode -eq 200) "Expected 200 for admin dashboard summary"

    $pendingApps = Invoke-ApiJson -Method GET -Url "$BaseUrl/admin/loan-applications/pending" -Session $adminSession
    Assert-True ($pendingApps.StatusCode -eq 200) "Expected 200 for admin pending applications"

    $adminUsers = Invoke-ApiJson -Method GET -Url "$BaseUrl/admin/users" -Session $adminSession
    Assert-True ($adminUsers.StatusCode -eq 200) "Expected 200 for admin users"

    $adminReports = Invoke-ApiJson -Method GET -Url "$BaseUrl/admin/reports/summary" -Session $adminSession
    Assert-True ($adminReports.StatusCode -eq 200) "Expected 200 for admin reports summary"

    Write-Step "Admin logout"
    $adminLogout = Invoke-ApiJson -Method POST -Url "$BaseUrl/auth/logout" -Session $adminSession -Headers $adminHeaders
    Assert-True ($adminLogout.StatusCode -eq 200) "Expected 200 for admin logout"
}

Write-Step "User logout"
$logoutResp = Invoke-ApiJson -Method POST -Url "$BaseUrl/auth/logout" -Session $userSession -Headers $csrfHeaders
Assert-True ($logoutResp.StatusCode -eq 200) "Expected 200 for user logout"

Write-Host ""
Write-Host "Smoke tests passed."
