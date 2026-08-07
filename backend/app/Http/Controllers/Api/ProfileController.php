<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\PasswordChangeLog;
use App\Models\UserRepCodeHistory;
use App\Services\Admin\AuditLogger;
use App\Services\OtpService;
use App\Services\WhatsAppOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly AuditLogger $audit,
        private readonly WhatsAppOtpService $whatsAppOtp,
    ) {}

    /**
     * Return the authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'role'            => $user->role,
            'phone_number'    => $user->phone_number,
            'whatsapp_number' => $user->whatsapp_number,
            'must_change_password' => $user->password_changed_at === null,
            'rep_code'        => $user->rep_code,
            'employee_number' => $user->employee_number,
            'updated_at'      => $user->updated_at,
        ]);
    }

    /**
     * Update the authenticated user's profile (name, phone_number, and/or rep_code).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate(
            [
                'name'         => 'sometimes|string|min:2|max:100',
                'phone_number' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{6,14}$/'],
                'whatsapp_number' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{6,14}$/'],
                'rep_code'     => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:50',
                    'regex:/^[A-Za-z0-9 ._\\-\/]+$/',
                    Rule::unique('users', 'rep_code')->ignore($user->id)->where('role', 'Sales Consultant'),
                ],
            ],
            [
                'name.min'            => 'Name must be between 2 and 100 characters.',
                'name.max'            => 'Name must be between 2 and 100 characters.',
                'phone_number.regex'  => 'Phone number must be in international format (e.g., +254712345678).',
                'whatsapp_number.regex' => 'WhatsApp number must be in international format (e.g., +254712345678).',
                'rep_code.regex'      => 'Rep code may only contain letters, numbers, spaces, dots, hyphens, underscores, and slashes.',
                'rep_code.unique'     => 'This rep code is already assigned to another Sales Consultant.',
                'rep_code.max'        => 'Rep code must not exceed 50 characters.',
            ]
        );

        // Normalise + track rep code history on self-service edits
        $newRepCode = array_key_exists('rep_code', $validated)
            ? (($validated['rep_code'] !== null && $validated['rep_code'] !== '')
                ? strtoupper(trim((string) $validated['rep_code']))
                : null)
            : null;

        $repCodeChanged = array_key_exists('rep_code', $validated)
            && $newRepCode !== $user->rep_code;

        if ($repCodeChanged) {
            UserRepCodeHistory::create([
                'user_id'         => $user->id,
                'rep_code'        => $user->rep_code,
                'changed_by_name' => $user->name,
                'changed_by'      => $user->id,
                'change_reason'   => 'Self-service profile update',
                'changed_at'      => now(),
            ]);

            $validated['rep_code'] = $newRepCode;
        } else {
            unset($validated['rep_code']);
        }

        // employee_number is read-only from profile — never mass-assigned here
        $user->fill($validated);
        $user->save();

        return response()->json([
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'role'            => $user->role,
            'phone_number'    => $user->phone_number,
            'whatsapp_number' => $user->whatsapp_number,
            'must_change_password' => $user->password_changed_at === null,
            'rep_code'        => $user->rep_code,
            'employee_number' => $user->employee_number,
            'updated_at'      => $user->updated_at,
        ]);
    }

    public function completeOnboarding(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'new_password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'phone_number' => ['nullable', 'regex:/^\+254(?:7|1)\d{8}$/'],
            'whatsapp_number' => ['nullable', 'regex:/^\+[1-9]\d{6,14}$/'],
        ], [
            'phone_number.regex' => 'Use a Kenyan mobile number in international format, for example +254712345678.',
            'whatsapp_number.regex' => 'Use international format, for example +254712345678.',
        ]);

        $user = $request->user();
        if (Hash::check($validated['new_password'], $user->password)) {
            return response()->json([
                'message' => 'Choose a password different from your temporary or current password.',
                'errors' => ['new_password' => ['Choose a different password.']],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($validated['new_password']),
            'password_changed_at' => now(),
            'phone_number' => $validated['phone_number'] ?? $user->phone_number,
            'whatsapp_number' => $validated['whatsapp_number'] ?? $user->whatsapp_number,
        ])->save();

        PasswordChangeLog::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $this->audit->log('first_login_password_changed', 'user', (string) $user->id, [
            'phone_updated' => array_key_exists('phone_number', $validated),
            'whatsapp_updated' => array_key_exists('whatsapp_number', $validated),
        ], $user->id, $request->ip());

        return response()->json([
            'message' => 'Your password and contact preferences have been saved.',
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->role, 'rep_code' => $user->rep_code,
                'phone_number' => $user->phone_number, 'whatsapp_number' => $user->whatsapp_number,
                'must_change_password' => false,
            ],
        ]);
    }

    /**
     * Return the authenticated user's sign-in logs, paginated at 20 per page.
     */
    public function signInLogs(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 5), 100);
        $logs = $request->user()
            ->signInLogs()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['id', 'created_at', 'ip_address', 'user_agent', 'login_mode', 'status']);

        return response()->json($logs);
    }

    public function sessions(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 5), 100);
        $sessions = $request->user()
            ->userSessions()
            ->orderByDesc('login_at')
            ->paginate($perPage, [
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
     * Request an OTP for password update.
     * Delivery: channel = email | whatsapp | both (default email).
     */
    public function requestPasswordUpdateOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['sometimes', 'string', Rule::in(['email', 'whatsapp', 'both'])],
        ]);

        $user = $request->user();
        $email = $user->email;
        $purpose = 'password-update';
        $channel = strtolower((string) ($validated['channel'] ?? 'email'));
        $sendEmail = in_array($channel, ['email', 'both'], true);
        $sendWhatsApp = in_array($channel, ['whatsapp', 'both'], true);

        if ($sendWhatsApp) {
            $whatsapp = trim((string) ($user->whatsapp_number ?? ''));
            if ($whatsapp === '') {
                return response()->json([
                    'message' => 'Add a WhatsApp number on your profile before receiving codes via WhatsApp.',
                    'code' => 'whatsapp_number_missing',
                ], 422);
            }
            if (! $this->whatsAppOtp->isConfigured()) {
                return response()->json([
                    'message' => 'WhatsApp delivery is not available right now. Choose Email or contact support.',
                    'code' => 'whatsapp_not_configured',
                ], 503);
            }
        }

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
                    'channel' => $channel,
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

        $delivered = [];
        $failures = [];

        if ($sendEmail) {
            try {
                Mail::to($email)->send(new OtpMail($otp, $user->name, $purpose));
                $delivered[] = 'email';
            } catch (\Throwable $e) {
                $failures['email'] = $e->getMessage();
                Log::error('password_otp_mail_failed', [
                    'email_hash' => hash('sha256', $email),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sendWhatsApp) {
            try {
                $this->whatsAppOtp->sendOtp((string) $user->whatsapp_number, $otp, $purpose);
                $delivered[] = 'whatsapp';
            } catch (\Throwable $e) {
                $failures['whatsapp'] = $e->getMessage();
                Log::error('password_otp_whatsapp_failed', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($delivered === []) {
            $otpRecord->delete();
            $this->audit->log('password_update_otp_request_failed', 'user', (string) $user->id, [
                'reason' => 'delivery_failed',
                'channel' => $channel,
                'failures' => array_keys($failures),
                'email_hash' => hash('sha256', $email),
            ], $user->id, $request->ip());

            $message = match (true) {
                isset($failures['email']) && isset($failures['whatsapp']) => 'Failed to send verification code by email and WhatsApp.',
                isset($failures['whatsapp']) => 'Failed to send verification code via WhatsApp.',
                default => 'Failed to send verification email.',
            };

            return response()->json(['message' => $message, 'code' => 'delivery_failed'], 503);
        }

        // Partial success on "both": keep OTP if at least one channel worked.
        $this->audit->log('password_update_otp_requested', 'user', (string) $user->id, [
            'email_hash' => hash('sha256', $email),
            'expires_in_minutes' => 15,
            'resend_attempts' => $resendAttempts,
            'channel' => $channel,
            'delivered' => $delivered,
            'failed' => array_keys($failures),
        ], $user->id, $request->ip());

        return response()->json([
            'message' => $this->passwordOtpDeliveryMessage($delivered, $failures),
            'channel' => $channel,
            'delivered' => $delivered,
            'failed' => array_keys($failures),
        ]);
    }

    /**
     * @param  list<string>  $delivered
     * @param  array<string, string>  $failures
     */
    private function passwordOtpDeliveryMessage(array $delivered, array $failures): string
    {
        $hasEmail = in_array('email', $delivered, true);
        $hasWhatsApp = in_array('whatsapp', $delivered, true);

        if ($hasEmail && $hasWhatsApp) {
            return 'Verification code sent to your email and WhatsApp.';
        }
        if ($hasEmail && isset($failures['whatsapp'])) {
            return 'Verification code sent to your email. WhatsApp delivery failed — use the email code.';
        }
        if ($hasWhatsApp && isset($failures['email'])) {
            return 'Verification code sent to your WhatsApp. Email delivery failed — use the WhatsApp code.';
        }
        if ($hasWhatsApp) {
            return 'Verification code sent to your WhatsApp.';
        }

        return 'Verification code sent to your email.';
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
        $user->password_changed_at = now();
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
