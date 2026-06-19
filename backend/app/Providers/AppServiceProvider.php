<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production', 'staging')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Email existence check: 20 per minute, keyed by IP
        RateLimiter::for('email-check', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please slow down.',
                    ], 429);
                });
        });

        // OTP request: 5 per 10 minutes, keyed by IP + email
        RateLimiter::for('otp-request', function (Request $request) {
            return Limit::perMinutes(10, 5)
                ->by($request->ip() . ':' . strtolower($request->input('email', '')))
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please wait before requesting another OTP.',
                    ], 429);
                });
        });

        // OTP verify: 10 per 15 minutes, keyed by IP + email
        RateLimiter::for('otp-verify', function (Request $request) {
            return Limit::perMinutes(15, 10)
                ->by($request->ip() . ':' . strtolower($request->input('email', '')))
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many verification attempts. Please wait before trying again.',
                    ], 429);
                });
        });
    }
}
