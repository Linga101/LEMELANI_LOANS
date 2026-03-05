# Next.js Hybrid Integration Guide

This project now supports basic feature-flag and URL helpers for phased Next.js rollout.

## Environment variables

- `NEXTJS_BASE_URL`
  - Example: `http://localhost:3000`
- `NEXTJS_REQUIRE_HEALTHY`
  - default: `true`
  - when `true`, PHP only redirects to Next.js if the target host/port is reachable
- `NEXTJS_HEALTHCHECK_TIMEOUT_MS`
  - default: `250`
  - TCP connectivity check timeout used by `nextjs_url()`
- `FILE_STORAGE_BACKEND`
  - `local` (default) or `object`
  - `object` supports local mirror and optional S3-compatible upload/download path
- `OBJECT_STORAGE_PROVIDER`
  - `local_mirror` (default) or `s3`
- `OBJECT_STORAGE_PATH`
  - local mirror path for object mode
- `OBJECT_STORAGE_WRITE_LOCAL_MIRROR`
  - keep local mirror copy when object backend is enabled
- `OBJECT_STORAGE_REQUIRE_REMOTE`
  - fail writes unless remote object upload succeeds
- `FF_<FLAG_NAME>`
  - Truthy values: `1`, `true`, `yes`, `on`
  - Falsy values: `0`, `false`, `no`, `off`

## Helper functions

Defined in `config/config.php`:

- `feature_enabled('flag_name', false)`
- `nextjs_url('/path')`

## Suggested first rollout flags

- `FF_NEXTJS_ALL` (global override for all surfaces)
- `FF_NEXTJS_CUSTOMER_ALL` (global override for customer surfaces)
- `FF_NEXTJS_ADMIN_ALL` (global override for admin surfaces)
- `FF_NEXTJS_AUTH`
- `FF_NEXTJS_DASHBOARD`
- `FF_NEXTJS_LOANS`
- `FF_NEXTJS_REPAYMENTS`
- `FF_NEXTJS_PROFILE`
- `FF_NEXTJS_CREDIT_HISTORY`
- `FF_NEXTJS_NOTIFICATIONS`
- `FF_NEXTJS_ADMIN_DASHBOARD`
- `FF_NEXTJS_ADMIN_LOANS`
- `FF_NEXTJS_ADMIN_USERS`
- `FF_NEXTJS_ADMIN_VERIFICATIONS`
- `FF_NEXTJS_ADMIN_PAYMENTS`
- `FF_NEXTJS_ADMIN_SETTINGS`
- `FF_NEXTJS_ADMIN_REPORTS`
- `FF_NEXTJS_ADMIN_PLATFORM_ACCOUNTS`

## Suggested usage pattern in legacy page controllers

```php
if (feature_enabled('nextjs_dashboard') && nextjs_url('/dashboard')) {
    redirect(nextjs_url('/dashboard'));
}
```

Keep PHP pages active while flags are `false`. Enable per-surface gradually.

## Cutover helpers

- `scripts/cutover_readiness.ps1`
  - Summarizes effective customer/admin flag coverage and worker dry-run status.
  - Example:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/cutover_readiness.ps1
```
