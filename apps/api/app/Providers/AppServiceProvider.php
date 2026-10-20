<?php

namespace App\Providers;

use App\Contracts\MeterPhotoOcr;
use App\Models\User;
use App\Services\Import\TesseractMeterPhotoOcr;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MeterPhotoOcr::class, TesseractMeterPhotoOcr::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $emailKey = is_string($email) ? Str::lower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-email:'.hash('sha256', $emailKey)),
            ];
        });

        RateLimiter::for('password-reset-request', function (Request $request): Limit {
            return Limit::perMinute(3)->by('password-reset-request:'.$request->ip());
        });

        RateLimiter::for('password-reset-submit', function (Request $request): Limit {
            return Limit::perMinute(10)->by('password-reset-submit:'.$request->ip());
        });

        RateLimiter::for('invitation-send', function (Request $request): array {
            $userId = $request->user('web')?->id ?? 'guest';

            return [
                Limit::perMinute(5)->by('invitation-send-user:'.$userId),
                Limit::perMinute(10)->by('invitation-send-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('invitation-claim', function (Request $request): Limit {
            return Limit::perMinute(10)->by('invitation-claim:'.$request->ip());
        });

        RateLimiter::for('member-manage', function (Request $request): array {
            $userId = $request->user('web')?->id ?? 'guest';

            return [
                Limit::perMinute(30)->by('member-manage-user:'.$userId),
                Limit::perMinute(60)->by('member-manage-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('module-manage', function (Request $request): array {
            $userId = $request->user('web')?->id ?? 'guest';

            return [
                Limit::perMinute(30)->by('module-manage-user:'.$userId),
                Limit::perMinute(60)->by('module-manage-ip:'.$request->ip()),
            ];
        });

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return route('password.reset').'#token='.urlencode($token);
        });
    }
}
