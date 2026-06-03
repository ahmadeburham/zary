<?php

namespace App\Services\Auth;

use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Exception;

class AuthService
{
    public function __construct()
    {
        $this->ensureRolesExist();
    }

    /**
     * Ensure default system roles exist in the database.
     */
    protected function ensureRolesExist(): void
    {
        try {
            foreach (['rental', 'owner', 'sponsor', 'admin'] as $roleName) {
                Role::firstOrCreate(['role' => $roleName]);
            }
        } catch (Exception $e) {
            Log::error("Failed to seed default roles: " . $e->getMessage());
        }
    }

    /**
     * Register a new user and generate verification OTP.
     */
    public function register(array $data): array
    {
        $this->ensureRolesExist();

        $user = User::create([
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'gender' => $data['gender'],
            'is_verified' => false,
            'liveness_passed' => false,
            'face_match_passed' => false,
            'is_profile_completed' => false,
            'onboarding_screen' => 1,
        ]);

        // Attach role
        $role = Role::where('role', $data['role'])->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        Log::info("User registered successfully: ID {$user->id}, Role: {$data['role']}");

        // Generate and send OTP
        $this->generateAndSendOtp($user);

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user->load('roles'),
            'token' => $token,
        ];
    }

    /**
     * Authenticate user with password using email or phone.
     */
    public function login(array $credentials): array
    {
        $login = $credentials['login'];
        $password = $credentials['password'];

        // Determine if login input is email or phone
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $user = User::where($field, $login)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            Log::warning("Failed login attempt for identifier: {$login}");
            throw ValidationException::withMessages([
                'login' => ['Invalid credentials.'],
            ]);
        }

        Log::info("User logged in successfully: ID {$user->id}");

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user->load('roles'),
            'token' => $token,
        ];
    }

    /**
     * Authenticate or register via Google or Facebook.
     */
    public function socialLogin(array $data): array
    {
        $this->ensureRolesExist();

        $provider = $data['provider'];
        $providerId = $data['provider_id'];
        $email = $data['email'];
        $name = $data['name'] ?? null;
        $roleName = $data['role'] ?? null;

        // Try finding by provider ID
        $field = $provider === 'google' ? 'google_id' : 'facebook_id';
        $user = User::where($field, $providerId)->first();

        if (!$user) {
            // Alternatively try finding by email
            $user = User::where('email', $email)->first();

            if ($user) {
                // Link account
                $user->update([$field => $providerId]);
                Log::info("Linked {$provider} account to existing user: ID {$user->id}");
            } else {
                // Create new user (Social registrations bypass OTP verification)
                if (!$roleName) {
                    throw ValidationException::withMessages([
                        'role' => ['The role field is required for new social registrations.'],
                    ]);
                }

                $user = User::create([
                    'email' => $email,
                    $field => $providerId,
                    'is_verified' => true, // Social accounts are pre-verified
                    'is_profile_completed' => false,
                    'onboarding_screen' => 1,
                ]);

                $role = Role::where('role', $roleName)->first();
                if ($role) {
                    $user->roles()->attach($role->id);
                }

                Log::info("New user registered via social login: ID {$user->id}, Provider: {$provider}, Role: {$roleName}");
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user->load('roles'),
            'token' => $token,
        ];
    }

    /**
     * Generate OTP code, persist it, and log/dispatch it.
     */
    public function generateAndSendOtp(User $user): Otp
    {
        // Delete any existing unused OTPs
        Otp::where('user_id', $user->id)
            ->where('type', 'registration')
            ->delete();

        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $otp = Otp::create([
            'user_id' => $user->id,
            'code' => $code,
            'type' => 'registration',
            'expires_at' => Carbon::now()->addMinutes(15),
            'attempts' => 0,
        ]);

        // Logging the generated OTP (representing SMS/Email delivery system)
        Log::info("OTP CODE SENT: To User ID {$user->id} ({$user->phone}/{$user->email}). Code: {$code}");

        return $otp;
    }

    /**
     * Verify OTP code submitted by a user.
     */
    public function verifyOtp(User $user, string $code): bool
    {
        $otp = Otp::where('user_id', $user->id)
            ->where('type', 'registration')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otp) {
            Log::warning("OTP verification failed: No active code found for User ID {$user->id}");
            return false;
        }

        if ($otp->attempts >= 5) {
            Log::warning("OTP verification failed: Too many failed attempts for User ID {$user->id}");
            return false;
        }

        if ($otp->code !== $code) {
            $otp->increment('attempts');
            Log::warning("OTP verification failed: Code mismatch for User ID {$user->id}");
            return false;
        }

        // OTP matched, mark user verified
        $user->update(['is_verified' => true]);
        $otp->delete();

        Log::info("OTP verified successfully for User ID {$user->id}");

        return true;
    }

    /**
     * Revoke current user session token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
        Log::info("User logged out: ID {$user->id}");
    }

    /**
     * Handle forgot password request: check user, generate and dispatch reset OTP.
     */
    public function forgotPassword(array $data): void
    {
        $login = $data['login'];
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $user = User::where($field, $login)->first();

        if (!$user) {
            Log::warning("Forgot password attempt failed: User not found for login '{$login}'");
            throw ValidationException::withMessages([
                'login' => ['User account not found.'],
            ]);
        }

        $this->generateAndSendPasswordResetOtp($user);
    }

    /**
     * Generate password reset OTP, persist and log it.
     */
    public function generateAndSendPasswordResetOtp(User $user): Otp
    {
        Otp::where('user_id', $user->id)
            ->where('type', 'password_reset')
            ->delete();

        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $otp = Otp::create([
            'user_id' => $user->id,
            'code' => $code,
            'type' => 'password_reset',
            'expires_at' => Carbon::now()->addMinutes(15),
            'attempts' => 0,
        ]);

        // Send OTP via both SMS and Email (logged locally)
        Log::info("PASSWORD RESET OTP SENT: To User ID {$user->id} via SMS ({$user->phone}) and Email ({$user->email}). Code: {$code}");

        return $otp;
    }

    /**
     * Handle verification and password reset via OTP.
     */
    public function resetPassword(array $data): void
    {
        $login = $data['login'];
        $code = $data['code'];
        $newPassword = $data['password'];

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user = User::where($field, $login)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'login' => ['User account not found.'],
            ]);
        }

        $otp = Otp::where('user_id', $user->id)
            ->where('type', 'password_reset')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otp) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired OTP.'],
            ]);
        }

        if ($otp->attempts >= 5) {
            throw ValidationException::withMessages([
                'code' => ['Too many failed attempts. Please request a new OTP.'],
            ]);
        }

        if ($otp->code !== $code) {
            $otp->increment('attempts');
            throw ValidationException::withMessages([
                'code' => ['Invalid OTP code.'],
            ]);
        }

        // OTP is correct: update password and delete OTP
        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        $otp->delete();

        Log::info("Password reset successfully for User ID {$user->id}");
    }

    /**
     * Handle authenticated user password change.
     */
    public function changePassword(User $user, array $data): void
    {
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The provided current password does not match our records.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        Log::info("Password changed successfully for authenticated User ID {$user->id}");
    }
}
