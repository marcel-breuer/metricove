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
use App\Services\AI\Tools\VisibleSnapshotTool;
use App\Services\Analytics\AnalyticsService;
use App\Services\Auth\ConfiguredFrontendUrl;
use App\Services\Finance\FinanceService;
use App\Services\Import\TesseractMeterPhotoOcr;
use App\Services\MealPlanning\MealPlanningService;
use App\Services\Modules\ModuleAccessService;
use App\Services\Pantry\PantryService;
use App\Services\Recipes\RecipeService;
use App\Services\Shopping\ShoppingService;
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
                new VisibleSnapshotTool('recipes', 'list_visible_recipes', 'List recipes the signed-in member is allowed to view, including only their authorized ingredients.', fn (User $actor): array => ['recipes' => $app->make(RecipeService::class)->index($actor)]),
                new VisibleSnapshotTool('meal_planning', 'show_visible_meal_plans', 'Show the current Monday-to-Sunday meal plan slots visible to the signed-in member.', fn (User $actor): array => $app->make(MealPlanningService::class)->week($actor, now()->startOfWeek()->toDateString())),
                new VisibleSnapshotTool('pantry', 'list_visible_pantry_items', 'List pantry stock lines and quantities visible to the signed-in member.', fn (User $actor): array => ['items' => $app->make(PantryService::class)->index($actor)]),
                new VisibleSnapshotTool('shopping', 'list_visible_shopping_lists', 'List visible shopping lists and their currently visible items.', function (User $actor) use ($app): array {
                    $shopping = $app->make(ShoppingService::class);
                    $lists = array_slice($shopping->index($actor), 0, 12);

                    return ['lists' => array_map(fn (array $list): array => $shopping->show($actor, (int) $list['id']), $lists)];
                }),
                new VisibleSnapshotTool('finance', 'list_visible_financial_records', 'Show the visible finance overview, recent transactions, budgets and recurring entries, separated by currency and record scope.', function (User $actor) use ($app): array {
                    $finance = $app->make(FinanceService::class);
                    $month = now()->format('Y-m');

                    return [
                        'overview' => $finance->overview($actor, $month),
                        'transactions' => $finance->transactions($actor, $month),
                        'budgets' => $finance->budgets($actor, $month),
                        'recurring_entries' => $finance->recurringEntries($actor),
                    ];
                }),
                new VisibleSnapshotTool('analytics', 'show_visible_analytics', 'Show the signed-in member’s own analytics dashboard, with source data recalculated under current module and record permissions.', fn (User $actor): array => $app->make(AnalyticsService::class)->dashboard($actor)),
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
