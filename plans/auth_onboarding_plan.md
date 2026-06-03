# Authentication and Multi-Step Onboarding Architecture

This plan describes the implementation of API-based authentication and a multi-step user onboarding flow for Rentals, Owners, and Sponsors in the `sukoon_dev` backend.

The architecture ensures clean code separation using the **Service Pattern**, logs key lifecycle events, secures API endpoints with **Laravel Sanctum**, caches user profiles for high-performance retrieval, and guarantees request safety using custom **Idempotency Middleware**.

---

## User Review Required

> [!IMPORTANT]
> - **Login Options**: Users can login using either their `email` OR `phone` number along with their password.
> - **Social Authentication**: Endpoints will support Google and Facebook authentication. A new `google_id` column will be added to the `users` table.
> - **OTP Verification**: Upon registration, an OTP code is generated, stored in the `otp` table, and dispatched. The user must call the `/api/auth/verify-otp` endpoint to verify their identity before they can proceed with any onboarding steps.
> - **Sponsor Table**: A new `sponsor_profiles` table will be created to store company name, details, and target audience.
> - **Onboarding Flow Rules**:
>   - **Rental**: Step 1 (Register & Role & OTP) $\rightarrow$ Step 2 (Rental Profile Type & Details) $\rightarrow$ Step 3 (User Profile Details) $\rightarrow$ Complete.
>   - **Owner**: Step 1 (Register & Role & OTP) $\rightarrow$ Step 2 (User Profile Details) $\rightarrow$ Complete.
>   - **Sponsor**: Step 1 (Register & Role & OTP) $\rightarrow$ Step 2 (User Profile Details) $\rightarrow$ Step 3 (Sponsor Company Details) $\rightarrow$ Complete.

---

## Proposed Changes

### Database & Schema Layer

#### [NEW] [2026_05_22_160000_add_onboarding_fields_to_users_table.php](file:///c:/xampp/htdocs/sukoon_dev/database/migrations/2026_05_22_160000_add_onboarding_fields_to_users_table.php)
Adds tracking and social login columns to the `users` table:
- `is_profile_completed` (boolean, default false)
- `onboarding_screen` (integer, default 1)
- `is_verified` (boolean, default false)
- `google_id` (string, nullable, unique)

#### [NEW] [2026_05_22_160001_create_sponsor_profiles_table.php](file:///c:/xampp/htdocs/sukoon_dev/database/migrations/2026_05_22_160001_create_sponsor_profiles_table.php)
Creates a profile table for sponsors:
- `id`
- `user_id` (foreignId to `users`, cascade delete)
- `company_name` (string)
- `company_details` (text)
- `target_audience` (string, nullable)
- `timestamps`

---

### Model Layer

#### [MODIFY] [User.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/User.php)
- Add `HasApiTokens` trait.
- Update `$fillable` with `phone`, `email`, `password`, `gender`, `is_profile_completed`, `onboarding_screen`, `is_verified`, `facebook_id`, `google_id`.
- Define relationships:
  - `roles()` (belongsToMany to `Role` via `role_user`)
  - `profile()` (hasOne `UserProfile`)
  - `rentalProfile()` (hasOne `RentalProfile`)
  - `sponsorProfile()` (hasOne `SponsorProfile`)
  - `otps()` (hasMany `Otp` or directly access `otp` table)
- Helper methods:
  - `hasRole(string $role)`
  - `isRental()`, `isOwner()`, `isSponsor()`

#### [NEW] [Role.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/Role.php)
- Model representing the `roles` table.
- Define `users()` belongsToMany relationship.

#### [NEW] [UserProfile.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/UserProfile.php)
- Model representing `user_profiles` table.
- `$fillable` for profile details and `user_id`.

#### [NEW] [RentalProfile.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/RentalProfile.php)
- Model representing `rental_profiles` table.
- Define `studentDetails()` (hasOne `StudentDetail`) and `employeeDetails()` (hasOne `EmployeeDetail`).

#### [NEW] [StudentDetail.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/StudentDetail.php)
- Model representing `student_details` table.
- Primary key `rental_profile_id` (non-incrementing).

#### [NEW] [EmployeeDetail.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/EmployeeDetail.php)
- Model representing `employee_details` table.
- Primary key `rental_profile_id` (non-incrementing).

#### [NEW] [SponsorProfile.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/SponsorProfile.php)
- Model representing `sponsor_profiles` table.

#### [NEW] [Otp.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/Otp.php)
- Model representing the `otp` table.
- Defines fields `user_id`, `code`, `type`, `created_at`, `expires_at`, `attempts`.

#### [NEW] [IdempotencyKey.php](file:///c:/xampp/htdocs/sukoon_dev/app/Models/IdempotencyKey.php)
- Model representing `idempotency_keys` table to store request hash, status, response cache.

---

### Request & Middleware Layer

#### [NEW] [IdempotencyMiddleware.php](file:///c:/xampp/htdocs/sukoon_dev/app/Http/Middleware/IdempotencyMiddleware.php)
- Intercepts requests containing the `X-Idempotency-Key` header.
- If status is `processing`, returns `409 Conflict`.
- If status is `completed`, returns the cached JSON response.
- Otherwise, creates the key, processes request, and caches response on success.

#### [NEW] Form Requests (`App\Http\Requests\Auth\*`)
- `RegisterRequest.php`: Validates credentials (`phone` and/or `email`, `password`, `gender`, `role`).
- `LoginRequest.php`: Validates login attributes (`login` (phone/email), `password`).
- `VerifyOtpRequest.php`: Validates `code` and optional user identifier.
- `SocialLoginRequest.php`: Validates social credentials (`provider`, `provider_id`, `email`, `name`, `role`).
- `RentalProfileRequest.php`: Validates type (`student`/`employee` etc.) and dynamically requires fields (`university`/`faculty` or `company`/`job_title`).
- `UserProfileRequest.php`: Validates personal data (`first_name`, `last_name`, `age`, `country`, `city`, optional `photo`).
- `SponsorProfileRequest.php`: Validates company details.

---

### Service Layer

#### [NEW] [AuthService.php](file:///c:/xampp/htdocs/sukoon_dev/app/Services/Auth/AuthService.php)
- Handles user registration (phone/email), login, OTP generation and verification, social authentication, and Sanctum tokens.
- Seeds default roles (`rental`, `owner`, `sponsor`) dynamically if they do not exist.

#### [NEW] [OnboardingService.php](file:///c:/xampp/htdocs/sukoon_dev/app/Services/Auth/OnboardingService.php)
- Processes each step of onboarding, updating `onboarding_screen` and `is_profile_completed`.
- Clears/invalidates user profile caches upon modifications.

---

### Controller & Routing Layer

#### [NEW] [AuthController.php](file:///c:/xampp/htdocs/sukoon_dev/app/Http/Controllers/Auth/AuthController.php)
- Exposes endpoints:
  - `POST /api/auth/register` (creates user, generates & logs OTP, returns token)
  - `POST /api/auth/verify-otp` (verifies registration/reset OTP, marks user verified)
  - `POST /api/auth/resend-otp` (regenerates OTP, logs OTP)
  - `POST /api/auth/login` (supports email/phone login, checks verified state, returns token)
  - `POST /api/auth/social-login` (handles Google/Facebook tokens, bypasses OTP, returns token)
  - `POST /api/auth/logout` (revokes current token)

#### [NEW] [OnboardingController.php](file:///c:/xampp/htdocs/sukoon_dev/app/Http/Controllers/Auth/OnboardingController.php)
- Exposes onboarding step endpoints (protected by auth and verification checks):
  - `POST /api/auth/onboarding/rental-profile` (Rental only)
  - `POST /api/auth/onboarding/user-profile` (All roles)
  - `POST /api/auth/onboarding/sponsor-profile` (Sponsor only)
  - `GET /api/auth/me` (Returns cacheable full profile detail)

#### [MODIFY] [api.php](file:///c:/xampp/htdocs/sukoon_dev/routes/api.php)
Registers routes under `/api/auth` grouping. Onboarding and Profile routes will be protected by `auth:sanctum`.

#### [MODIFY] [app.php](file:///c:/xampp/htdocs/sukoon_dev/bootstrap/app.php)
Registers the global or api-specific `IdempotencyMiddleware`.

---

## Verification Plan

### Automated Tests
- Create integration test cases under `tests/Feature/AuthOnboardingTest.php` verifying:
  1. Registration via email/phone and OTP generation.
  2. Successful OTP verification, enabling further onboarding.
  3. Login using phone OR email.
  4. Social authentication flow simulation for Google and Facebook.
  5. Onboarding steps validation for Rentals, Owners, and Sponsors.
  6. Idempotency behavior using `X-Idempotency-Key` headers.
  7. Logging of key onboarding updates.
- Run tests via `php artisan test`.
