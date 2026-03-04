# Endpoint Backlog (Mapped to Current PHP Pages)

Last updated: 2026-03-04

Status legend:
- `implemented` = available in `api/v1/routes/main.php`
- `partial` = endpoint exists but response/contract needs cleanup
- `next` = not yet implemented

## Phase 0 Foundation

| Area | Endpoint | Source page/form action | Status | Notes |
|---|---|---|---|---|
| API namespace | `/api/v1/*` | Global | implemented | Front controller + route split in `api/v1/index.php`, `api/v1/routes/main.php` |
| CSRF bridge | `GET /security/csrf-token` | Used by SPA mutations | implemented | Session cookie + CSRF header flow |
| Auth session contract | Cookie session across endpoints | `login.php`, protected pages | implemented | `auth/login`, `auth/me`, `auth/logout` |
| Observability | `X-Request-Id` response header | All API requests | implemented | Request ID emitted in bootstrap |
| Upload externalization | storage adapter + object backend toggle | `register.php`, `view-document.php` | partial | Adapter + env toggle added (`FILE_STORAGE_BACKEND`), object provider integration still pending |

## Phase 1 Auth + Account APIs

| Endpoint | Method | Source page/form action | Status |
|---|---|---|---|
| `/auth/register` | POST | `register.php` submit | implemented |
| `/auth/login` | POST | `login.php` submit | implemented |
| `/auth/logout` | POST | `logout.php` | implemented |
| `/auth/password/forgot` | POST | `forgot-password.php` submit | implemented |
| `/auth/password/reset` | POST | `reset-password.php` submit | implemented |
| `/auth/me` | GET | session user bootstrap | implemented |
| `/customer/profile` | GET | `profile.php` load | implemented |
| `/customer/profile` | PATCH | `profile.php` update_profile | implemented |
| `/customer/profile/password` | PATCH | `profile.php` change_password | implemented |

## Phase 2 Customer Loan Journey

| Endpoint | Method | Source page/form action | Status |
|---|---|---|---|
| `/customer/dashboard/summary` | GET | `dashboard.php` data load | implemented |
| `/customer/loans` | GET | `loans.php` list/filter | implemented |
| `/customer/loans/{loanId}` | GET | `loan-details.php` view | implemented |
| `/customer/loan-applications` | POST | `apply-loan.php` submit | implemented |
| `/customer/payout-accounts` | GET | `apply-loan.php` account selection | implemented |
| `/customer/repayments` | POST | `repayments.php` submit payment | implemented |
| `/customer/payments/history` | GET | `payment-history.php` list | implemented |
| `/customer/credit-history` | GET | `credit-history.php` load | implemented |

## Phase 3 Notifications + Documents

| Endpoint | Method | Source page/form action | Status |
|---|---|---|---|
| `/customer/notifications` | GET | `notifications.php` list/filter | implemented |
| `/customer/notifications/{notificationId}/read` | POST | `notifications.php` mark_read | implemented |
| `/customer/notifications/read-all` | POST | `notifications.php` mark_all_read | implemented |
| `/customer/notifications/{notificationId}` | DELETE | `notifications.php` delete | implemented |
| `/customer/documents/{type}` | GET | `view-document.php` secure file view | implemented |

## Phase 4 Admin Operations

### P0 Admin Critical

| Endpoint | Method | Source page/form action | Status |
|---|---|---|---|
| `/admin/dashboard/summary` | GET | `admin/dashboard.php` load | implemented |
| `/admin/loan-applications/pending` | GET | `admin/loans.php` pending FIFO | implemented |
| `/admin/loan-applications/{id}/process` | POST | `admin/loans.php` approve/process | implemented |
| `/admin/loan-applications/{id}/reject` | POST | `admin/loans.php` reject | implemented |
| `/admin/users` | GET | `admin/users.php` list/filter | implemented |
| `/admin/verifications/pending` | GET | `admin/verifications.php` pending tab | implemented |

### P1 Admin Support

| Endpoint | Method | Source page/form action | Status |
|---|---|---|---|
| `/admin/settings` | GET | `admin/settings.php` load settings | implemented |
| `/admin/settings` | POST | `admin/settings.php` save settings | implemented |
| `/admin/reports/summary` | GET | `admin/reports.php` aggregate report | implemented |
| `/admin/payments` | GET | `admin/payments.php` table/filter | implemented |
| `/admin/platform-accounts` | GET | `admin/platform-accounts.php` list | implemented |
| `/admin/platform-accounts` | POST | `admin/platform-accounts.php` create | implemented |
| `/admin/platform-accounts/{id}/default` | POST | `admin/platform-accounts.php` set_default | implemented |
| `/admin/platform-accounts/{id}/status` | POST | `admin/platform-accounts.php` activate/deactivate | implemented |
| `/admin/platform-accounts/{id}/balance` | POST | `admin/platform-accounts.php` update_balance | implemented |
| `POST /admin/verifications/{userId}/verify` | POST | `admin/verifications.php` verify | implemented |
| `POST /admin/verifications/{userId}/reject` | POST | `admin/verifications.php` reject | implemented |
| `POST /admin/users/{userId}/status` | POST | `admin/users.php` suspend/activate | implemented |
| `POST /admin/users/{userId}/credit-score` | POST | `admin/users.php` adjust credit | implemented |

## Phase 5 Node Workers + Integrations

| Worker/API | Source behavior | Status |
|---|---|---|
| Reminders/escalations | overdue reminders, notifications | partial |
| Scoring refresh batch | credit scoring recalculation | partial |
| Reconciliation/import jobs | payment imports/matching | next |
| Webhook ingestion APIs | mobile money/card provider callbacks | next |

## Phase 6 Cutover + Decommission

| Task | Status |
|---|---|
| Per-page feature flags for customer/admin | implemented (major pages) |
| Route traffic to Next.js pages gradually | in progress | Additional customer/admin utility pages now support feature-flag redirects |
| Keep PHP fallback + rollback toggles | implemented |
| Remove PHP views after parity + 2 releases | next |

## Immediate Sprint Recommendation

1. API hardening and parity:
   - Validate response contract parity against OpenAPI for admin support endpoints.
2. Run smoke tests in CI:
   - `scripts/api_smoke_tests.ps1`.
3. Next.js consumption:
   - auth -> dashboard -> loans -> repayments -> profile -> notifications.
4. Storage migration:
   - introduce storage adapter + object storage for selfies/IDs.
