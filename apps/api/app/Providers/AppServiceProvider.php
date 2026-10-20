<?php

namespace App\Providers;

use App\Contracts\AssistantProvider;
use App\Contracts\MeterPhotoOcr;
use App\Models\User;
use App\Services\AI\AssistantToolRegistry;
use App\Services\AI\OpenAiCompatibleAssistantProvider;
use App\Services\AI\Tools\FinanceMonthSummaryTool;
use App\Services\AI\Tools\TrackingSnapshotTool;
use App\Services\AI\Tools\UtilitiesSnapshotTool;
use App\Services\AI\Tools\VehiclesSnapshotTool;
use App\Services\Auth\ConfiguredFrontendUrl;
use App\Services\Import\TesseractMeterPhotoOcr;
use App\Services\Modules\ModuleAccessService;
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
        $this->app->bind(AssistantProvider::class, OpenAiCompatibleAssistantProvider::class);
        $this->app->singleton(AssistantToolRegistry::class, function ($app): AssistantToolRegistry {
            return new AssistantToolRegistry($app->make(ModuleAccessService::class), [
                $app->make(FinanceMonthSummaryTool::class),
                $app->make(TrackingSnapshotTool::class),
                $app->make(UtilitiesSnapshotTool::class),
                $app->make(VehiclesSnapshotTool::class),
            ]);
        });
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

        RateLimiter::for('assistant', function (Request $request): array {
            $userId = $request->user('web')?->id ?? 'guest';

            return [
                Limit::perMinute(8)->by('assistant-user:'.$userId),
                Limit::perMinute(12)->by('assistant-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('mcp', function (Request $request): Limit {
            return Limit::perMinute(60)->by('mcp-ip:'.$request->ip());
        });

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return app(ConfiguredFrontendUrl::class)->route('password.reset').'#token='.urlencode($token);
        });
    }
}
