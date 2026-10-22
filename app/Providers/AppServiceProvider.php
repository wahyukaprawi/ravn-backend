<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::before(function ($user, string $ability) {
            if ($user->hasRole('super_admin')) {
                return true;
            }

            return null;
        });

        $this->configureRateLimiters();
    }

    protected function configureRateLimiters(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($this->throttleKey($request)),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);

        RateLimiter::for('activation-resend', fn (Request $request) => [
            Limit::perMinute(3)->by($this->throttleKey($request)),
            Limit::perMinute(10)->by((string) $request->ip()),
        ]);

        RateLimiter::for('password-forgot', fn (Request $request) => [
            Limit::perMinute(3)->by($this->throttleKey($request)),
            Limit::perMinute(10)->by((string) $request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($this->throttleKey($request)),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        return $email.'|'.$request->ip();
    }
}
