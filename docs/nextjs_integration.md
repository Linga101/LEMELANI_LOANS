# Next.js Hybrid Integration Guide

This project now supports basic feature-flag and URL helpers for phased Next.js rollout.

## Environment variables

- `NEXTJS_BASE_URL`
  - Example: `http://localhost:3000`
- `FF_<FLAG_NAME>`
  - Truthy values: `1`, `true`, `yes`, `on`
  - Falsy values: `0`, `false`, `no`, `off`

## Helper functions

Defined in `config/config.php`:

- `feature_enabled('flag_name', false)`
- `nextjs_url('/path')`

## Suggested first rollout flags

- `FF_NEXTJS_AUTH`
- `FF_NEXTJS_DASHBOARD`
- `FF_NEXTJS_LOANS`
- `FF_NEXTJS_REPAYMENTS`
- `FF_NEXTJS_ADMIN_LOANS`

## Suggested usage pattern in legacy page controllers

```php
if (feature_enabled('nextjs_dashboard') && nextjs_url('/dashboard')) {
    redirect(nextjs_url('/dashboard'));
}
```

Keep PHP pages active while flags are `false`. Enable per-surface gradually.
