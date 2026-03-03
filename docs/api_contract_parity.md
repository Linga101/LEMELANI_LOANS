# API Contract Parity Check

Last updated: 2026-03-03

This checklist compares `openapi.v1.yaml` to the current PHP API implementation in `api/v1/index.php`.

## Parity status

- Implemented and aligned:
  - Auth: register, login, logout, forgot/reset, me
  - Security: csrf-token
  - Customer: profile, profile password, dashboard summary
  - Customer loans/payments: loans list/detail, loan application, repayments, payment history, payout accounts
  - Customer notifications: list
  - Admin: dashboard summary, pending applications, process/reject, users, verifications pending, settings, reports summary

- Implemented but not yet fully documented in OpenAPI response details:
  - Some responses include extra fields (for example stats blocks, unread counts, documents arrays).

- Newly implemented (Phase 3 prep) and now added to OpenAPI:
  - `POST /customer/notifications/{notificationId}/read`
  - `POST /customer/notifications/read-all`
  - `DELETE /customer/notifications/{notificationId}`
  - `GET /customer/documents/{type}`

## Known intentional compatibility differences

- Role naming:
  - OpenAPI mostly uses `user`.
  - Database uses `customer`.
  - API currently accepts both (`user` and `customer`) for compatibility.

- Logout CSRF:
  - API enforces `X-CSRF-Token` for `POST /auth/logout` (safer for cookie-based auth).
  - OpenAPI currently does not explicitly require `CsrfHeader` on logout.

## Recommended next contract cleanup

- Add explicit `CsrfHeader` requirement to `POST /auth/logout`.
- Add dedicated schemas for:
  - `AdminDashboardSummary`
  - `AdminReportsSummary`
  - `CustomerDashboardSummary`
  - `NotificationMutationResponse`
  - `DocumentStream` metadata (if you later return signed links instead of binary body).
- Standardize response field naming where mixed (`camelCase` vs source SQL aliases).
