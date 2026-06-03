# Sukoon API Reference

Complete reference for every route in `routes/api.php` plus proxied ML service paths.

**Base URL:** `http://<host>:8000/api` (e.g. `php artisan serve` → `http://127.0.0.1:8000/api`)

**Health check (no `/api` prefix):** `GET /up`

### Table of contents

1. [Authentication & account](#1-authentication--account)
2. [Onboarding & profile](#2-onboarding--profile)
3. [Universities & recommendations](#3-universities--recommendations)
4. [ML & recommender proxies](#4-ml--recommender-proxies)
5. [Apartments](#5-apartments)
6. [Apartment members](#6-apartment-members)
7. [Contracts](#7-contracts)
8. [Owner & tenant profile](#8-owner--tenant-profile)
9. [Identity verification](#9-identity-verification)
10. [Payments](#10-payments)
11. [Notifications](#11-notifications)
12. [Admin API](#12-admin-api)
13. [HTTP status codes](#13-http-status-codes)

---

## Authentication

Most routes require a **Bearer token** (Laravel Sanctum):

```http
Authorization: Bearer <access_token>
Accept: application/json
Content-Type: application/json
```

Obtain `access_token` from `POST /api/auth/login` or `POST /api/auth/register` (after OTP flow).

### Roles

| Role value | Description |
|------------|-------------|
| `rental` | Tenant / student renter |
| `owner` | Apartment owner |
| `sponsor` | Sponsor profile type |
| `admin` | Platform administrator |

Many endpoints enforce role or ownership in controllers/policies (403 if not allowed).

### Idempotency (optional)

For `POST`, `PUT`, `PATCH`, `DELETE` (except `/api/ml/*` and `/api/recommender/*`), you may send:

```http
X-Idempotency-Key: <unique-uuid>
```

Repeating the same key returns the cached response. Conflicts return `409` if a duplicate is still processing.

### Response shapes

**Standard success** (most auth/onboarding endpoints using `ApiResponse` trait):

```json
{
  "success": true,
  "message": "Human-readable message",
  "data": { }
}
```

**Standard error:**

```json
{
  "success": false,
  "message": "Error description",
  "errors": { "field": ["validation message"] }
}
```

**Validation failure:** HTTP `422`

**Unauthenticated:** HTTP `401` — `{ "success": false, "message": "Unauthenticated." }`

Some endpoints return **raw JSON** without the `success` wrapper (noted below).

---

## 1. Authentication & account

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/auth/register` | No | Create account |
| `POST` | `/auth/login` | No | Login |
| `POST` | `/auth/social-login` | No | Google/Facebook login |
| `POST` | `/auth/verify-otp` | No* | Verify registration OTP |
| `POST` | `/auth/resend-otp` | No* | Resend OTP |
| `POST` | `/auth/forgot-password` | No | Start password reset |
| `POST` | `/auth/reset-password` | No | Complete password reset |
| `POST` | `/auth/logout` | Yes | Revoke current token |
| `POST` | `/auth/token/refresh` | Yes | Refresh token |
| `POST` | `/auth/token/invalidate` | Yes | Invalidate token |
| `POST` | `/auth/change-password` | Yes | Change password |
| `POST` | `/auth/fcm-token` | Yes | Update FCM push token |
| `GET` | `/auth/me` | Yes | Full profile + onboarding state |

\* `verify-otp` / `resend-otp` accept either Bearer user or `email` / `phone` in body to find the user.

### `POST /auth/register`

**Body (JSON):**

| Field | Rules |
|-------|--------|
| `email` | Required if no `phone`; unique email |
| `phone` | Required if no `email`; unique phone |
| `password` | Required; min 8 chars |
| `gender` | `male` \| `female` |
| `role` | `rental` \| `owner` \| `sponsor` |

**Response:** `201` — `data` includes user + token (see `AuthService`).

### `POST /auth/login`

**Body:**

| Field | Rules |
|-------|--------|
| `login` | Email or phone string |
| `password` | Required |

**Response:** `200` — `data.token`, user profile fields.

### `POST /auth/verify-otp`

**Body:** `code` (required), plus `email` or `phone` if not authenticated.

### `POST /auth/resend-otp`

**Body:** `email` or `phone`.

### `POST /auth/social-login`

| Field | Rules |
|-------|--------|
| `provider` | `google` \| `facebook` |
| `provider_id` | Required string |
| `email` | Required email |
| `name` | Optional |
| `role` | Optional: `rental`, `owner`, `sponsor` |

### `POST /auth/forgot-password`

| Field | Rules |
|-------|--------|
| `login` | Email or phone |

Sends OTP / reset flow to the matched account.

### `POST /auth/reset-password`

| Field | Rules |
|-------|--------|
| `login` | Email or phone |
| `code` | OTP code (6 digits) |
| `password` | New password (min 8) |
| `password_confirmation` | Must match `password` |

### `POST /auth/change-password` (authenticated)

| Field | Rules |
|-------|--------|
| `current_password` | Required |
| `password` | New password (min 8) |
| `password_confirmation` | Must match |

### `POST /auth/fcm-token`

| Field | Description |
|-------|-------------|
| `fcm_token` | Device push token string |

### `GET /auth/me`

Returns cached user with `roles`, `profile`, `rentalProfile`, `sponsorProfile`, `identityDocument`, onboarding flags.

---

## 2. Onboarding & profile

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/auth/onboarding/rental-profile` | Yes | Student/employee rental preferences (recommender input) |
| `POST` | `/auth/onboarding/user-profile` | Yes | Name, photo, age, country, city |
| `POST` | `/auth/onboarding/sponsor-profile` | Yes | Sponsor company details |
| `POST` | `/auth/profile/ocr` | Yes | Save ML verification result (multipart) |
| `POST` | `/auth/profile/verification-checkpoint` | Yes | Save single checkpoint (e.g. liveness) |

### `POST /auth/onboarding/rental-profile`

**Body (key fields):**

| Field | Notes |
|-------|--------|
| `type` | `student`, `employee`, `other`, `prefer_not_to_say` |
| `university_id` | UUID (optional) |
| `university_program_id` | UUID (optional) |
| `budget_min`, `budget_max` | Numbers |
| `preferred_location` | City/area string |
| `prefers_furnished` | Boolean |
| `company`, `job_title` | Required if `type=employee` |

### `POST /auth/profile/ocr`

**Multipart form:**

| Field | Required |
|-------|----------|
| `request_id` | Yes — ML job id |
| `result_json` | Yes — full ML pipeline JSON (or stringified JSON) |
| `front` | Optional image |
| `back` | Optional image |
| `selfie` | Optional image |

**Response (`data`):** `success`, `confirmed_checks` (`validation`, `face_match`, `ocr`, `liveness`), `remaining_checks`, `user` (`is_verified`, `liveness_passed`, `face_match_passed`, `gender`), `identity_ocr_locked`, `pipeline_status`.

**Fully verified** when ID valid + face match + OCR complete + OCR fields locked on profile.

### `POST /auth/profile/verification-checkpoint`

**Body:**

| Field | Values |
|-------|--------|
| `check` | `liveness` |
| `passed` | boolean |
| `result_json` | optional |

---

## 3. Universities & recommendations

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/universities` | Yes | List universities (with coordinates) |
| `GET` | `/universities/{id}/programs` | Yes | Programs for a university |
| `GET` | `/recommendation-faculties` | Yes | Faculty → affinity group mapping |
| `POST` | `/recommendations` | Yes | Ranked apartments for current user |

### `POST /recommendations`

**Body:**

| Field | Default | Description |
|-------|---------|-------------|
| `limit` | `10` | Max recommendations to return |

**Response:** Raw JSON from recommender service (not wrapped in `success`). Includes `recommendations[]` with `apartment`, `score`, etc. Requires completed rental profile.

**Errors:** `400` if preferences missing or recommender service down.

---

## 4. ML & recommender proxies

These forward requests to local services (configured in `ProxyController`).

| Method | Path | Auth | Proxies to |
|--------|------|------|------------|
| `*` | `/ml/{path}` | Yes | ID verification ML (`:8001`) |
| `*` | `/recommender/{path}` | Yes | Python recommender (`:8002`) |

**Examples:**

- `POST /api/ml/verify` — submit ID images (multipart); returns `request_id`, `status: processing`
- `GET /api/ml/result/{request_id}` — poll pipeline status
- `POST /api/ml/liveness` — standalone liveness check
- `POST /api/recommender/recommend` — direct recommender call (also triggers DB sync on POST recommend paths)

Timeouts: ML proxy up to ~120s PHP limit / 55s HTTP client; **not** subject to idempotency middleware.

All paths below are called as **`/api/ml/<path>`** with Bearer auth. The Laravel proxy forwards method, query, JSON body, and multipart files unchanged.

| ML path | Method | Description |
|---------|--------|-------------|
| `/health` | GET | Service health |
| `/stages` | GET | Enabled pipeline stages config |
| `/verify` | POST | Start full ID pipeline (async) |
| `/result/{job_id}` | GET | Poll job status / final result |
| `/result/{job_id}/{section}` | GET | Single section (`validation`, `face_match`, `liveness`, `ocr`, …) |
| `/jobs` | GET | List in-memory jobs (dev/debug) |
| `/face-match` | POST | Standalone face match |
| `/liveness` | POST | Standalone liveness |
| `/ocr` | POST | Standalone OCR |

#### `POST /api/ml/verify` (multipart)

| Field | Required | Notes |
|-------|----------|-------|
| `front` | Recommended | National ID front image |
| `back` | Recommended | National ID back image |
| `selfie` | Recommended | Selfie for face match / liveness |
| `enabled_stages` | Optional | JSON object overriding which stages run (e.g. skip OCR on retry) |

**Response `202`:** `request_id`, `status: processing`, `poll_url`

**Poll `GET /api/ml/result/{request_id}`:** While `status: processing`, returns `current_stage`, partial `sections`. When complete: `status`, `id_valid`, full `sections` (validation, face_match, liveness, ocr_front, ocr_back, …).

After ML completes (or partial progress), persist via **`POST /api/auth/profile/ocr`** with the same `request_id` and `result_json`.

#### `POST /api/ml/liveness` / `POST /api/ml/face-match`

Multipart images as in standalone ML service (`selfie`; face-match also needs `id_photo`).

**Recommender proxy:** `ANY /api/recommender/{path}` → `http://127.0.0.1:8002/{path}`. POST paths containing `recommend` trigger `php artisan recommender:sync` before forward.

---

## 5. Apartments

| Method | Path | Auth | Who |
|--------|------|------|-----|
| `GET` | `/apartments` | Yes | Admin: all; Owner: own; Rental: open+approved+gender match |
| `POST` | `/apartments` | Yes | Owner — create draft listing |
| `GET` | `/apartments/{id}` | Yes | Policy-based view |
| `PUT/PATCH` | `/apartments/{id}` | Yes | Owner update |
| `DELETE` | `/apartments/{id}` | Yes | Owner delete |
| `POST` | `/apartments/{id}/join` | Yes | Rental — apply/join |
| `POST` | `/apartments/{id}/leave` | Yes | Leave apartment |
| `GET` | `/apartments/{id}/lease-template` | Yes | Lease template file/meta |

**Query (list):** `page`, `per_page` (max 100)

### `POST /apartments` (create)

**Multipart — required fields:**

`price`, `insurance`, `capacity`, `gender_allowed` (`male`|`female`|`any`), `rooms_count`, `beds_count`, `latitude`, `longitude`

**Optional:** `has_ac`, `has_water`, `has_gas`, `is_furnished`, `location_label`, `rent_duration`, `photos[]`, `document`, `document_type`

Creates `status=draft`, `verification_status=pending`.

**Response:** `{ "message": "...", "data": <apartment with photos, document> }` (no `success` wrapper).

### `GET /apartments/{id}`

Returns `{ "data": <apartment> }` with `photos`, `document`, `owner.profile`. Photos/documents omit raw `file_data` in JSON.

### `POST /apartments/{id}/join`

No body required. Creates `ApartmentMember` with `membership_status: pending`.

**Error codes (422):**

| `error_code` | Meaning |
|--------------|---------|
| `APARTMENT_NOT_OPEN` | Listing not open |
| `ALREADY_MEMBER` | User already pending/active on this or another unit |
| `GENDER_MISMATCH` | `gender_allowed` excludes user |
| `GENDER_MISMATCH_OCCUPIED` | Opposite gender already in unit |
| `APARTMENT_FULL` | Capacity reached |

On success may set apartment to `closed_uploading_contracts` when full.

### `POST /apartments/{id}/leave`

Cancels membership per business rules; may trigger refund eligibility.

### `GET /apartments/{id}/lease-template`

Returns lease template metadata or file URL/path for the apartment.

---

## 6. Apartment members

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/apartments/{id}/members` | Yes | List members |
| `POST` | `/apartments/{id}/add-member` | Yes | Owner adds member |
| `POST` | `/apartments/{id}/remove-member` | Yes | Owner removes member |

---

## 7. Contracts

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/contracts` | Yes | Tenant's contracts |
| `POST` | `/contracts` | Yes | Upload contract (tenant) |
| `GET` | `/contracts/owner` | Yes | Owner's contracts |
| `GET` | `/contracts/{id}` | Yes | Single contract |
| `PUT` | `/contracts/{id}` | Yes | Update |
| `DELETE` | `/contracts/{id}` | Yes | Delete |
| `POST` | `/contracts/{id}/accept` | Yes | Accept |
| `POST` | `/contracts/{id}/refuse` | Yes | Refuse |
| `GET` | `/apartments/{apartmentId}/contracts` | Yes | Contracts for apartment |
| `DELETE` | `/apartments/{apartmentId}/contracts` | Yes | Remove apartment contracts |

---

## 8. Owner & tenant profile

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/owner/payout` | Yes | Owner payout settings |
| `POST` | `/owner/identity-document` | Yes | Upload owner ID document |
| `GET` | `/owners` | Yes | List owners (payments) |
| `POST` | `/tenant/identity-document` | Yes | Upload tenant ID document |
| `GET` | `/tenant/membership` | Yes | Current membership / application |
| `POST` | `/tenant/contracts` | Yes | Tenant upload contract |
| `POST` | `/tenant/refund` | Yes | Request refund |
| `GET` | `/tenant/payment-status` | Yes | Payment status for tenant |

---

## 9. Identity verification

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/identity/documents` | Yes | Bulk upload ID images |
| `POST` | `/identity/manual-verification-request` | Yes | Request manual admin review |
| `GET` | `/identity/verifications` | Yes | Verification history |
| `GET` | `/identity/status` | Yes | Cumulative check status |

### `GET /identity/status`

**Response (flat JSON):**

| Field | Description |
|-------|-------------|
| `is_verified` | All core checks + OCR locked |
| `confirmed_checks` | `validation`, `face_match`, `ocr`, `liveness` |
| `remaining_checks` | Checks still needed |
| `identity_ocr_locked` | ID fields locked from OCR |
| `ocr_fields` | `id_number`, `birth_date`, `id_expiry_date`, `gender` |
| `can_retry` | Whether user should retry ML |

---

## 10. Payments

### Public (no auth)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/payments/webhook/paymob` | Paymob webhook |
| `POST` | `/paymob/webhook` | Alias webhook |
| `GET` | `/acceptance/post_pay` | Post-payment redirect handler |
| `GET` | `/debug/paymob-test` | **Dev only** — test Paymob credentials |

### Authenticated — `/payment/*`

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/payment/orders` | List payment orders |
| `GET` | `/payment/orders/{id}` | Order detail |
| `POST` | `/payment/orders/{id}/sync` | Sync status from Paymob |
| `POST` | `/payment/orders/{id}/retry` | Retry payment link |
| `POST` | `/payment/orders/{id}/dummy-pay` | Test/dummy payment |
| `GET` | `/payment/transactions` | Transaction history |
| `GET` | `/payment/refund-requests` | My refund requests |
| `POST` | `/payment/refund-requests` | Submit refund request |

---

## 11. Notifications

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/notifications` | Yes | List notifications |
| `POST` | `/notifications/read-all` | Yes | Mark all read |
| `POST` | `/notifications/{id}/read` | Yes | Mark one read |
| `DELETE` | `/notifications` | Yes | Delete all |

---

## 12. Admin API

**Prefix:** `/api/admin/*` — requires **admin** role (enforced per controller).

### Identity & verification

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/admin/identity-documents/pending` | Pending identity documents |
| `POST` | `/admin/identity-documents/{id}/verify` | Approve document |
| `POST` | `/admin/identity-documents/{id}/reject` | Reject document |
| `POST` | `/admin/identity-documents/{id}/review` | Review document |
| `DELETE` | `/admin/identity-documents/{id}` | Delete document |
| `GET` | `/admin/identity-verifications/pending` | Pending ML verifications |
| `POST` | `/admin/identity-verifications/{id}/review` | Review verification record |

### Apartment documents & listings

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/admin/apartment-documents/{id}/verify` | Approve apt document |
| `POST` | `/admin/apartment-documents/{id}/reject` | Reject apt document |
| `DELETE` | `/admin/apartment-documents/{id}` | Delete apt document |
| `GET` | `/admin/apartments/{id}/moderation-details` | Moderation detail |
| `POST` | `/admin/apartments/{id}/verify` | Approve apartment |
| `POST` | `/admin/apartments/{id}/refuse` | Refuse apartment |
| `POST` | `/admin/apartments/{id}/retrigger-payment` | Retrigger payment flow |

### Tenant contracts (admin)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/admin/tenant-contracts/{id}/verify` | Verify |
| `POST` | `/admin/tenant-contracts/{id}/reject` | Reject |
| `DELETE` | `/admin/tenant-contracts/{id}` | Delete |

### Refunds

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/admin/refund-requests` | All refund requests |
| `POST` | `/admin/refund-requests/{id}/approve` | Approve |
| `POST` | `/admin/refund-requests/{id}/reject` | Reject |

### Contracts, payments, manual verification

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/admin/contracts` | All contracts (admin view) |
| `POST` | `/admin/payment/orders` | Create custom payment order for user |
| `GET` | `/admin/manual-verification-requests` | List manual requests |
| `POST` | `/admin/manual-verification-requests/{id}/approve` | Approve |
| `POST` | `/admin/manual-verification-requests/{id}/reject` | Reject |

### Users

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/admin/users` | List/search users (`page`, `per_page`, `q`, `role`) |
| `POST` | `/admin/users` | Create user |
| `PUT` | `/admin/users/{id}` | Update user |
| `POST` | `/admin/users/{id}/promote` | Promote to admin |
| `POST` | `/admin/users/{id}/demote` | Remove admin role |
| `DELETE` | `/admin/users/{id}` | Soft-delete user |

### Leases

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/admin/leases` | List leases |
| `GET` | `/admin/leases/{id}` | Lease detail |
| `PUT` | `/admin/leases/{id}` | Update lease |

### Settings & SQL

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/admin/settings` | Get settings |
| `GET` | `/admin/settings/all` | All settings |
| `PUT` | `/admin/settings/{key}` | Update one setting |
| `POST` | `/admin/settings/bulk` | Bulk update |
| `POST` | `/admin/query` | **Dangerous** — raw SQL panel (admin only) |

---

## Quick reference — endpoint count

| Section | Endpoints |
|---------|-----------|
| Auth (public + protected) | 18 |
| Onboarding / OCR | 5 |
| Universities & recommendations | 4 |
| ML / recommender proxy | 2 (wildcard paths) |
| Apartments + members | 11 |
| Contracts | 11 |
| Owner / tenant | 7 |
| Identity | 4 |
| Payments | 12 |
| Notifications | 4 |
| Admin | 38+ |

---

## Related services (not in this repo)

| Service | Default port | Purpose |
|---------|--------------|---------|
| ID verification ML | 8001 | OCR, liveness, face match (via `/api/ml/*`) |
| Python recommender | 8002 | Ranking (via `/api/recommender/*` and `/api/recommendations`) |

Sync Laravel → recommender SQLite:

```bash
php artisan recommender:sync
```

---

## 13. HTTP status codes

| Code | When |
|------|------|
| `200` | Success |
| `201` | Created (register, apartment create) |
| `202` | ML job accepted (`/ml/verify`) |
| `400` | Bad request (recommendations, business rule) |
| `401` | Missing/invalid Bearer token |
| `403` | Policy / not admin / not owner |
| `404` | Resource not found |
| `409` | Idempotency key still `processing` |
| `422` | Validation failed (`errors` object) |
| `500` | Server / database error |
| `503` | ML or recommender service unreachable |

---

## Apartment & user enums (reference)

**Apartment `status`:** `draft`, `open`, `closed`, `closed_uploading_contracts`, `rented` (MySQL), …

**Apartment `verification_status`:** `pending`, `approved`, `refused`

**Member `membership_status`:** `pending`, `active`, `cancelled`, …

**Payment order `status`:** `pending`, `paid`, `failed`, …

---

## Changelog

Document generated from `routes/api.php` in this repository. When routes change, update this file alongside `routes/api.php`.
