<?php

namespace App\Providers;

use App\Events\OutboxMessageDispatched;
use App\Models\PersonalAccessToken;
use App\Notifications\CreateNotificationsFromOutbox;
use App\Notifications\Push\FcmPushGateway;
use App\Notifications\Push\LogPushGateway;
use App\Notifications\Push\PushGateway;
use App\Reporting\AttendanceDailyReport;
use App\Reporting\AttendanceSummaryReport;
use App\Reporting\AuditActivityReport;
use App\Reporting\ReportRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportRegistry::class, fn () => new ReportRegistry([
            new AttendanceSummaryReport,
            new AttendanceDailyReport,
            new AuditActivityReport,
        ]));

        $this->app->bind(PushGateway::class, fn () => match (config('notifications.push.driver')) {
            'fcm' => new FcmPushGateway((array) config('notifications.push.fcm')),
            default => new LogPushGateway,
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Event::listen(
            OutboxMessageDispatched::class,
            CreateNotificationsFromOutbox::class,
        );

        Gate::before(function ($user, string $ability) {
            if ($user === null) {
                return null;
            }

            if (in_array($ability, ['notifications.view_failures', 'notifications.retry'], true)) {
                return null;
            }

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
