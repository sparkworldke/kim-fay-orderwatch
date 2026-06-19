<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\SignInLog;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otpService) {}

    /**
     * Request an OTP for a registered, active user email address.
     */
    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if (! $user) {
            Log::info('otp_request', [
                'email_hash' => hash('sha256', $email),
                'ip' => $request->ip(),
                'outcome' => 'email_not_found',
            ]);

            return response()->json([
                'message' => 'This email is not registered in OrderWatch.',
                'code' => 'email_not_registered',
            ], 422);
        }

        if (! $user->isEligibleForOtp()) {
            Log::warning('otp_request_blocked', [
                'email_hash' => hash('sha256', $email),
                'ip' => $request->ip(),
                'outcome' => 'user_inactive_or_unverified',
            ]);

            return response()->json([
                'message' => 'This account is not active. Contact your administrator.',
                'code' => 'account_inactive',
            ], 403);
        }

        Otp::where('email', $email)->delete();

        $otp = $this->otpService->generate();
        $otpRecord = Otp::create([
            'user_id' => $user->id,
            'email' => $email,
            'otp_hash' => $this->otpService->hash($otp),
            'expires_at' => now()->addMinutes(15),
            'attempts' => 0,
        ]);

        try {
            Mail::to($user->email)->send(new OtpMail($otp, $user->name));
        } catch (\Throwable $e) {
            $otpRecord->delete();

            Log::error('otp_mail_failed', [
                'email_hash' => hash('sha256', $email),
                'mail_mailer' => config('mail.default'),
                'mail_from' => config('mail.from.address'),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send verification email. Please try again.',
                'code' => 'otp_mail_failed',
            ], 503);
        }

        Log::info('otp_request', [
            'email_hash' => hash('sha256', $email),
            'ip' => $request->ip(),
            'outcome' => 'otp_dispatched',
        ]);

        return response()->json([
            'message' => 'Verification code sent.',
        ]);
    }

    /**
     * Check whether an email belongs to an OTP-eligible registered user.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if (! $user) {
            return response()->json([
                'exists' => false,
                'eligible' => false,
                'status' => 'not_registered',
                'message' => 'This email is not registered in OrderWatch.',
            ]);
        }

        if (! $user->isEligibleForOtp()) {
            return response()->json([
                'exists' => true,
                'eligible' => false,
                'status' => 'inactive',
                'message' => 'This account is not active. Contact your administrator.',
            ]);
        }

        return response()->json([
            'exists' => true,
            'eligible' => true,
            'status' => 'registered',
        ]);
    }

    /**
     * Verify an OTP submission and, on success, issue a Sanctum token.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'login_mode' => 'required|in:otp-only,otp-and-password',
            'password' => 'required_if:login_mode,otp-and-password|string',
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = $validated['otp'];
        $loginMode = $validated['login_mode'];
        $password = $validated['password'] ?? null;

        $user = User::where('email', $email)->first();
        $otpRecord = Otp::where('email', $email)->first();

        if (! $otpRecord) {
            $this->recordSignInLog($request, $user, $email, $loginMode, 'failure');

            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        if ($otpRecord->expires_at->isPast()) {
            $otpRecord->delete();
            $this->recordSignInLog($request, $user, $email, $loginMode, 'failure');

            return response()->json(
                ['message' => 'OTP has expired. Please request a new one.'],
                422
            );
        }

        if (! $this->otpService->verify($otp, $otpRecord->otp_hash)) {
            $otpRecord->attempts += 1;

            if ($otpRecord->attempts >= 5) {
                $otpRecord->delete();
                $this->recordSignInLog($request, $user, $email, $loginMode, 'failure');

                return response()->json(
                    ['message' => 'Too many failed attempts. Please request a new OTP.'],
                    429
                );
            }

            $otpRecord->save();

            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        if ($loginMode === 'otp-and-password') {
            if (! $user || ! Hash::check($password, $user->password)) {
                $this->recordSignInLog($request, $user, $email, $loginMode, 'failure');

                return response()->json(['message' => 'Invalid credentials.'], 422);
            }
        }

        if (! $user || ! $user->isEligibleForOtp()) {
            $otpRecord->delete();
            $this->recordSignInLog($request, $user, $email, $loginMode, 'failure');

            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        $otpRecord->delete();

        $user->tokens()->delete();

        $token = $user->createToken('api-token', ['*'], now()->addHours(8));

        $this->recordSignInLog($request, $user, $email, $loginMode, 'success');

        Log::info('otp_verify', [
            'email_hash' => hash('sha256', $email),
            'ip' => $request->ip(),
            'login_mode' => $loginMode,
            'outcome' => 'verified',
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'Administrator',
            ],
        ]);
    }

    /**
     * Persist a SignInLog entry for every verification attempt.
     */
    private function recordSignInLog(
        Request $request,
        ?User $user,
        string $email,
        string $loginMode,
        string $status
    ): void {
        SignInLog::create([
            'user_id' => $user?->id,
            'email_hash' => hash('sha256', $email),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() ?? '',
            'login_mode' => $loginMode,
            'status' => $status,
        ]);
    }
}
