<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\PasswordChangeLog;
use App\Services\Admin\AuditLogger;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProfileController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Return the authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'role'         => $user->role,
            'phone_number' => $user->phone_number,
            'updated_at'   => $user->updated_at,
        ]);
    }

    /**
     * Update the authenticated user's profile (name and/or phone_number).
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'name'         => 'sometimes|string|min:2|max:100',
                'phone_number' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{6,14}$/'],
            ],
            [
                'name.min'            => 'Name must be between 2 and 100 characters.',
                'name.max'            => 'Name must be between 2 and 100 characters.',
                'phone_number.regex'  => 'Phone number must be in international format (e.g., +254712345678).',
            ]
        );

        $user = $request->user();
        $user->fill($validated);
        $user->save();

        return response()->json([
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'role'         => $user->role,
            'phone_number' => $user->phone_number,
            'updated_at'   => $user->updated_at,
        ]);
    }

    /**
     * Return the authenticated user's sign-in logs, paginated at 20 per page.
     */
    public function signInLogs(Request $request): JsonResponse
    {
        $logs = $request->user()
            ->signInLogs()
            ->orderBy('created_at', 'desc')
            ->paginate(20, ['id', 'created_at', 'ip_address', 'user_agent', 'login_mode', 'status']);

        return response()->json($logs);
    }

    public function sessions(Request $request): JsonResponse
    {
        $sessions = $request->user()
            ->userSessions()
            ->orderByDesc('login_at')
            ->paginate(20, [
                'id',
                'login_at',
                'logout_at',
                'logout_reason',
                'duration_seconds',
                'ip_address',
                'login_mode',
            ]);

        return response()->json($sessions);
    }

    /**
     * Request an OTP for password update (authenticated user, uses current user email).
     */
    public function requestPasswordUpdateOtp(Request $request): JsonResponse
    {
        $user = $request->user();
        $email = $user->email;
        $purpose = 'password-update';

        $existingOtp = Otp::where('email', $email)->where('purpose', $purpose)->first();
        $now = now();
        $resendAttempts = 0;
        $resendWindowStart = $now;

        if ($existingOtp) {
            $windowStart = $existingOtp->resend_window_start ?? $existingOtp->created_at;
            $resendWindowActive = $windowStart && $now->diffInMinutes($windowStart) < 10;

            if ($resendWindowActive && $existingOtp->resend_attempts >= 3) {
                $this->audit->log('password_update_otp_resend_blocked', 'user', (string) $user->id, [
                    'reason' => 'too_many_resends',
                    'email_hash' => hash('sha256', $email),
                ], $user->id, $request->ip());

                return response()->json([
                    'message' => 'Too many resend attempts. Please try again in a few minutes.',
                    'code' => 'too_many_resends',
                ], 429);
            }

            $resendAttempts = $resendWindowActive ? $existingOtp->resend_attempts + 1 : 1;
            $resendWindowStart = $resendWindowActive ? $windowStart : $now;
            $existingOtp->delete();
        }

        $otp = $this->otpService->generate();
        $otpRecord = Otp::create([
            'user_id' => $user->id,
            'email' => $email,
            'purpose' => $purpose,
            'otp_hash' => $this->otpService->hash($otp),
            'expires_at' => now()->addMinutes(15),
            'attempts' => 0,
            'resend_attempts' => $resendAttempts,
            'resend_window_start' => $resendWindowStart,
        ]);

        try {
            Mail::to($email)->send(new OtpMail($otp, $user->name, $purpose));
        } catch (\Throwable $e) {
            $otpRecord->delete();
            Log::error('password_otp_mail_failed', [
                'email_hash' => hash('sha256', $email),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->audit->log('password_update_otp_request_failed', 'user', (string) $user->id, [
                'reason' => 'mail_failed',
                'email_hash' => hash('sha256', $email),
            ], $user->id, $request->ip());

            return response()->json(['message' => 'Failed to send verification email.'], 503);
        }

        $this->audit->log('password_update_otp_requested', 'user', (string) $user->id, [
            'email_hash' => hash('sha256', $email),
            'expires_in_minutes' => 15,
            'resend_attempts' => $resendAttempts,
        ], $user->id, $request->ip());

        return response()->json(['message' => 'Verification code sent to your email.']);
    }

    /**
     * Verify password update OTP.
     */
    public function verifyPasswordUpdateOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'otp' => 'required|string|size:6',
        ]);
        $user = $request->user();

        [$otpRecord, $error] = $this->validatePasswordUpdateOtp($request, $validated['otp']);
        if ($error) {
            return $error;
        }

        $this->audit->log('password_update_otp_verified', 'user', (string) $user->id, [
            'email_hash' => hash('sha256', $user->email),
            'otp_record_id' => $otpRecord->id,
        ], $user->id, $request->ip());

        return response()->json(['message' => 'OTP verified successfully.']);
    }

    /**
     * Update password (requires valid OTP).
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'otp' => 'required|string|size:6',
            'new_password' => 'required|string|min:8|confirmed',
        ]);
        $user = $request->user();

        [$otpRecord, $error] = $this->validatePasswordUpdateOtp($request, $validated['otp']);
        if ($error) {
            return $error;
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        PasswordChangeLog::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $otpRecord->delete();

        $this->audit->log('password_updated', 'user', (string) $user->id, [
            'email_hash' => hash('sha256', $user->email),
            'tokens_revoked' => true,
        ], $user->id, $request->ip());

        Log::info('password_updated', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Password updated successfully!',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);
    }

    /**
     * @return array{0: ?Otp, 1: ?JsonResponse}
     */
    private function validatePasswordUpdateOtp(Request $request, string $otp): array
    {
        $user = $request->user();
        $email = $user->email;
        $otpRecord = Otp::where('email', $email)
            ->where('purpose', 'password-update')
            ->first();

        if (! $otpRecord) {
            $this->audit->log('password_update_otp_failed', 'user', (string) $user->id, [
                'reason' => 'missing',
                'email_hash' => hash('sha256', $email),
            ], $user->id, $request->ip());

            return [null, response()->json(['message' => 'Invalid or expired verification code.'], 422)];
        }

        if ($otpRecord->expires_at->isPast()) {
            $otpRecord->delete();

            $this->audit->log('password_update_otp_failed', 'user', (string) $user->id, [
                'reason' => 'expired',
                'email_hash' => hash('sha256', $email),
            ], $user->id, $request->ip());

            return [null, response()->json(['message' => 'Verification code has expired. Please request a new one.'], 422)];
        }

        if (! $this->otpService->verify($otp, $otpRecord->otp_hash)) {
            $otpRecord->attempts += 1;

            if ($otpRecord->attempts >= 5) {
                $otpRecord->delete();

                $this->audit->log('password_update_otp_failed', 'user', (string) $user->id, [
                    'reason' => 'too_many_failed_attempts',
                    'email_hash' => hash('sha256', $email),
                ], $user->id, $request->ip());

                return [null, response()->json(['message' => 'Too many failed attempts. Please request a new verification code.'], 429)];
            }

            $otpRecord->save();

            $this->audit->log('password_update_otp_failed', 'user', (string) $user->id, [
                'reason' => 'invalid',
                'email_hash' => hash('sha256', $email),
                'attempts' => $otpRecord->attempts,
            ], $user->id, $request->ip());

            return [null, response()->json(['message' => 'Invalid verification code.'], 422)];
        }

        return [$otpRecord, null];
    }
}
