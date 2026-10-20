<?php

use App\Http\Controllers\Api\AdminInvitationController;
use App\Http\Controllers\Api\AdminMemberAuditController;
use App\Http\Controllers\Api\AdminMemberController;
use App\Http\Controllers\Api\AdminMemberModulePermissionController;
use App\Http\Controllers\Api\AdminModuleController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InitialSetupController;
use App\Http\Controllers\Api\InvitationClaimController;
use App\Http\Controllers\Api\MealPlanningController;
use App\Http\Controllers\Api\ModuleDiscoveryController;
use App\Http\Controllers\Api\PantryController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecipesController;
use App\Http\Controllers\Api\ShoppingController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\UtilitiesController;
use App\Http\Controllers\Api\VehiclesController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::middleware('web')->group(function (): void {
    Route::get('/setup/status', [InitialSetupController::class, 'status'])->name('setup.status');
    Route::post('/setup', [InitialSetupController::class, 'complete'])
        ->middleware('throttle:5,1')
        ->name('setup.complete');

    Route::get('/auth/session', [AuthenticationController::class, 'session'])->name('auth.session');
    Route::post('/auth/login', [AuthenticationController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');
    Route::post('/auth/password/email', [PasswordResetController::class, 'send'])
        ->middleware('throttle:password-reset-request')
        ->name('auth.password.email');
    Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset-submit')
        ->name('auth.password.reset');
    Route::post('/invitations/claim', InvitationClaimController::class)
        ->middleware('throttle:invitation-claim')
        ->name('invitations.claim');

    Route::middleware(['auth:web', 'active-member'])->group(function (): void {
        Route::post('/auth/logout', [AuthenticationController::class, 'logout'])->name('auth.logout');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::get('/modules', ModuleDiscoveryController::class)->name('modules.enabled');

        Route::prefix('tracking')->name('tracking.')->group(function (): void {
            Route::get('/trackers', [TrackingController::class, 'index'])
                ->middleware('module.access:tracking,view')
                ->name('trackers.index');
            Route::post('/trackers', [TrackingController::class, 'store'])
                ->middleware('module.enabled:tracking')
                ->name('trackers.store');
            Route::get('/trackers/{tracker}', [TrackingController::class, 'show'])
                ->middleware('module.access:tracking,view')
                ->whereNumber('tracker')
                ->name('trackers.show');
            Route::put('/trackers/{tracker}', [TrackingController::class, 'update'])
                ->middleware('module.access:tracking,manage')
                ->whereNumber('tracker')
                ->name('trackers.update');
            Route::get('/trackers/{tracker}/entries', [TrackingController::class, 'entries'])
                ->middleware('module.access:tracking,view')
                ->whereNumber('tracker')
                ->name('entries.index');
            Route::post('/trackers/{tracker}/entries', [TrackingController::class, 'storeEntry'])
                ->middleware('module.access:tracking,create')
                ->whereNumber('tracker')
                ->name('entries.store');
            Route::patch('/entries/{entry}', [TrackingController::class, 'updateEntry'])
                ->middleware('module.access:tracking,edit')
                ->whereNumber('entry')
                ->name('entries.update');
        });

        Route::prefix('utilities')->name('utilities.')->group(function (): void {
            Route::get('/meters', [UtilitiesController::class, 'index'])
                ->middleware('module.access:utilities,view')
                ->name('meters.index');
            Route::post('/meters', [UtilitiesController::class, 'store'])
                ->middleware('module.enabled:utilities')
                ->name('meters.store');
            Route::get('/meters/{meter}', [UtilitiesController::class, 'show'])
                ->middleware('module.access:utilities,view')
                ->whereNumber('meter')
                ->name('meters.show');
            Route::patch('/meters/{meter}', [UtilitiesController::class, 'update'])
                ->middleware('module.access:utilities,manage')
                ->whereNumber('meter')
                ->name('meters.update');
            Route::post('/meters/{meter}/readings', [UtilitiesController::class, 'storeReading'])
                ->middleware('module.access:utilities,create')
                ->whereNumber('meter')
                ->name('readings.store');
            Route::patch('/readings/{reading}', [UtilitiesController::class, 'correctReading'])
                ->middleware('module.access:utilities,edit')
                ->whereNumber('reading')
                ->name('readings.update');
            Route::get('/readings/{reading}/revisions', [UtilitiesController::class, 'revisions'])
                ->middleware('module.access:utilities,view')
                ->whereNumber('reading')
                ->name('readings.revisions');
            Route::get('/readings/{reading}/photo', [UtilitiesController::class, 'photo'])
                ->middleware('module.access:utilities,view')
                ->whereNumber('reading')
                ->name('readings.photo');
        });

        Route::prefix('vehicles')->name('vehicles.')->group(function (): void {
            Route::get('/', [VehiclesController::class, 'index'])
                ->middleware('module.access:vehicles,view')
                ->name('index');
            Route::post('/', [VehiclesController::class, 'store'])
                ->middleware('module.enabled:vehicles')
                ->name('store');
            Route::get('/{vehicle}', [VehiclesController::class, 'show'])
                ->middleware('module.access:vehicles,view')
                ->whereNumber('vehicle')
                ->name('show');
            Route::patch('/{vehicle}', [VehiclesController::class, 'update'])
                ->middleware('module.access:vehicles,manage')
                ->whereNumber('vehicle')
                ->name('update');
            Route::post('/{vehicle}/odometer-readings', [VehiclesController::class, 'storeOdometerReading'])
                ->middleware('module.access:vehicles,create')
                ->whereNumber('vehicle')
                ->name('odometer.store');
            Route::post('/{vehicle}/maintenance', [VehiclesController::class, 'storeMaintenance'])
                ->middleware('module.access:vehicles,create')
                ->whereNumber('vehicle')
                ->name('maintenance.store');
            Route::post('/{vehicle}/insurance', [VehiclesController::class, 'storeInsurance'])
                ->middleware('module.access:vehicles,create')
                ->whereNumber('vehicle')
                ->name('insurance.store');
            Route::post('/{vehicle}/costs', [VehiclesController::class, 'storeCost'])
                ->middleware('module.access:vehicles,create')
                ->whereNumber('vehicle')
                ->name('costs.store');
            Route::patch('/odometer-readings/{reading}', [VehiclesController::class, 'correctOdometerReading'])
                ->middleware('module.access:vehicles,edit')
                ->whereNumber('reading')
                ->name('odometer.update');
            Route::get('/odometer-readings/{reading}/revisions', [VehiclesController::class, 'odometerRevisions'])
                ->middleware('module.access:vehicles,view')
                ->whereNumber('reading')
                ->name('odometer.revisions');
        });

        Route::prefix('finance')->name('finance.')->group(function (): void {
            Route::get('/overview', [FinanceController::class, 'overview'])
                ->middleware('module.access:finance,view')->name('overview');
            Route::get('/categories', [FinanceController::class, 'categories'])
                ->middleware('module.access:finance,view')->name('categories.index');
            Route::post('/categories', [FinanceController::class, 'storeCategory'])
                ->middleware('module.enabled:finance')->name('categories.store');
            Route::patch('/categories/{category}', [FinanceController::class, 'updateCategory'])
                ->middleware('module.enabled:finance')->whereNumber('category')->name('categories.update');
            Route::delete('/categories/{category}', [FinanceController::class, 'deleteCategory'])
                ->middleware('module.enabled:finance')->whereNumber('category')->name('categories.destroy');

            Route::get('/transactions', [FinanceController::class, 'transactions'])
                ->middleware('module.access:finance,view')->name('transactions.index');
            Route::post('/transactions', [FinanceController::class, 'storeTransaction'])
                ->middleware('module.enabled:finance')->name('transactions.store');
            Route::patch('/transactions/{transaction}', [FinanceController::class, 'updateTransaction'])
                ->middleware('module.enabled:finance')->whereNumber('transaction')->name('transactions.update');
            Route::delete('/transactions/{transaction}', [FinanceController::class, 'deleteTransaction'])
                ->middleware('module.enabled:finance')->whereNumber('transaction')->name('transactions.destroy');

            Route::get('/recurring', [FinanceController::class, 'recurringEntries'])
                ->middleware('module.access:finance,view')->name('recurring.index');
            Route::post('/recurring', [FinanceController::class, 'storeRecurringEntry'])
                ->middleware('module.enabled:finance')->name('recurring.store');
            Route::patch('/recurring/{entry}', [FinanceController::class, 'updateRecurringEntry'])
                ->middleware('module.enabled:finance')->whereNumber('entry')->name('recurring.update');
            Route::delete('/recurring/{entry}', [FinanceController::class, 'deleteRecurringEntry'])
                ->middleware('module.enabled:finance')->whereNumber('entry')->name('recurring.destroy');
            Route::post('/recurring/{entry}/record', [FinanceController::class, 'recordRecurringOccurrence'])
                ->middleware('module.enabled:finance')->whereNumber('entry')->name('recurring.record');

            Route::get('/budgets', [FinanceController::class, 'budgets'])
                ->middleware('module.access:finance,view')->name('budgets.index');
            Route::post('/budgets', [FinanceController::class, 'storeBudget'])
                ->middleware('module.enabled:finance')->name('budgets.store');
            Route::patch('/budgets/{budget}', [FinanceController::class, 'updateBudget'])
                ->middleware('module.enabled:finance')->whereNumber('budget')->name('budgets.update');
            Route::delete('/budgets/{budget}', [FinanceController::class, 'deleteBudget'])
                ->middleware('module.enabled:finance')->whereNumber('budget')->name('budgets.destroy');
        });

        Route::prefix('analytics')->name('analytics.')->group(function (): void {
            Route::get('/catalog', [AnalyticsController::class, 'catalog'])
                ->middleware('module.access:analytics,view')->name('catalog');
            Route::get('/dashboard', [AnalyticsController::class, 'dashboard'])
                ->middleware('module.access:analytics,view')->name('dashboard');
            Route::post('/widgets', [AnalyticsController::class, 'store'])
                ->middleware(['module.access:analytics,create', 'throttle:30,1'])->name('widgets.store');
            Route::patch('/widgets/{widget}', [AnalyticsController::class, 'update'])
                ->middleware(['module.access:analytics,edit', 'throttle:30,1'])->whereNumber('widget')->name('widgets.update');
            Route::delete('/widgets/{widget}', [AnalyticsController::class, 'destroy'])
                ->middleware(['module.access:analytics,edit', 'throttle:30,1'])->whereNumber('widget')->name('widgets.destroy');
        });

        Route::prefix('import')->name('import.')->group(function (): void {
            Route::get('/utilities/meters/{meter}/readings.csv', [ImportController::class, 'export'])
                ->middleware(['module.access:import,view', 'module.access:utilities,view'])
                ->whereNumber('meter')->name('utilities.readings.export');
            Route::post('/utilities/meters/{meter}/readings/preview', [ImportController::class, 'preview'])
                ->middleware(['module.access:import,create', 'module.access:utilities,view', 'module.access:utilities,create', 'throttle:10,1'])
                ->whereNumber('meter')->name('utilities.readings.preview');
            Route::post('/utilities/meters/{meter}/readings/confirm', [ImportController::class, 'confirm'])
                ->middleware(['module.access:import,create', 'module.access:utilities,view', 'module.access:utilities,create', 'throttle:10,1'])
                ->whereNumber('meter')->name('utilities.readings.confirm');
            Route::post('/utilities/meters/{meter}/reading-suggestions', [ImportController::class, 'suggest'])
                ->middleware(['module.access:import,create', 'module.access:utilities,view', 'module.access:utilities,create', 'throttle:10,1'])
                ->whereNumber('meter')->name('utilities.readings.suggest');
        });

        Route::prefix('recipes')->name('recipes.')->group(function (): void {
            Route::get('/', [RecipesController::class, 'index'])
                ->middleware('module.access:recipes,view')->name('index');
            Route::post('/', [RecipesController::class, 'store'])
                ->middleware('module.enabled:recipes')->name('store');
            Route::get('/{recipe}', [RecipesController::class, 'show'])
                ->middleware('module.access:recipes,view')->whereNumber('recipe')->name('show');
            Route::patch('/{recipe}', [RecipesController::class, 'update'])
                ->middleware('module.enabled:recipes')->whereNumber('recipe')->name('update');
            Route::delete('/{recipe}', [RecipesController::class, 'destroy'])
                ->middleware('module.enabled:recipes')->whereNumber('recipe')->name('destroy');
            Route::put('/{recipe}/favorite', [RecipesController::class, 'favorite'])
                ->middleware('module.access:recipes,view')->whereNumber('recipe')->name('favorite');
            Route::delete('/{recipe}/favorite', [RecipesController::class, 'unfavorite'])
                ->middleware('module.access:recipes,view')->whereNumber('recipe')->name('unfavorite');
            Route::get('/{recipe}/photo', [RecipesController::class, 'photo'])
                ->middleware('module.access:recipes,view')->whereNumber('recipe')->name('photo');
        });

        Route::prefix('meal-planning')->name('meal-planning.')->group(function (): void {
            Route::get('/', [MealPlanningController::class, 'week'])
                ->middleware('module.access:meal_planning,view')->name('week');
            Route::post('/slots', [MealPlanningController::class, 'store'])
                ->middleware('module.enabled:meal_planning')->name('slots.store');
            Route::patch('/slots/{slot}', [MealPlanningController::class, 'update'])
                ->middleware('module.enabled:meal_planning')->whereNumber('slot')->name('slots.update');
            Route::delete('/slots/{slot}', [MealPlanningController::class, 'destroy'])
                ->middleware('module.enabled:meal_planning')->whereNumber('slot')->name('slots.destroy');
        });

        Route::prefix('pantry')->name('pantry.')->group(function (): void {
            Route::get('/', [PantryController::class, 'index'])
                ->middleware('module.access:pantry,view')->name('index');
            Route::post('/', [PantryController::class, 'store'])
                ->middleware('module.enabled:pantry')->name('store');
            Route::get('/{item}/movements', [PantryController::class, 'movements'])
                ->middleware('module.access:pantry,view')->whereNumber('item')->name('movements.index');
            Route::patch('/{item}', [PantryController::class, 'update'])
                ->middleware('module.enabled:pantry')->whereNumber('item')->name('update');
            Route::post('/{item}/adjustments', [PantryController::class, 'adjust'])
                ->middleware('module.enabled:pantry')->whereNumber('item')->name('adjustments.store');
        });

        Route::prefix('shopping')->name('shopping.')->group(function (): void {
            Route::post('/lists/{list}/meal-suggestions', [ShoppingController::class, 'mealSuggestions'])
                ->middleware('module.enabled:shopping')->whereNumber('list')->name('lists.meal-suggestions.preview');
            Route::post('/lists/{list}/meal-suggestions/import', [ShoppingController::class, 'importMealSuggestions'])
                ->middleware('module.enabled:shopping')->whereNumber('list')->name('lists.meal-suggestions.import');
            Route::get('/lists', [ShoppingController::class, 'index'])
                ->middleware('module.access:shopping,view')->name('lists.index');
            Route::post('/lists', [ShoppingController::class, 'storeList'])
                ->middleware('module.enabled:shopping')->name('lists.store');
            Route::get('/lists/{list}', [ShoppingController::class, 'show'])
                ->middleware('module.access:shopping,view')->whereNumber('list')->name('lists.show');
            Route::patch('/lists/{list}', [ShoppingController::class, 'updateList'])
                ->middleware('module.enabled:shopping')->whereNumber('list')->name('lists.update');
            Route::get('/lists/{list}/history', [ShoppingController::class, 'history'])
                ->middleware('module.access:shopping,view')->whereNumber('list')->name('lists.history');
            Route::post('/lists/{list}/items', [ShoppingController::class, 'storeItem'])
                ->middleware('module.enabled:shopping')->whereNumber('list')->name('items.store');
            Route::patch('/items/{item}', [ShoppingController::class, 'updateItem'])
                ->middleware('module.enabled:shopping')->whereNumber('item')->name('items.update');
            Route::patch('/items/{item}/checked', [ShoppingController::class, 'setChecked'])
                ->middleware('module.enabled:shopping')->whereNumber('item')->name('items.checked');
            Route::post('/items/{item}/move', [ShoppingController::class, 'moveItem'])
                ->middleware('module.enabled:shopping')->whereNumber('item')->name('items.move');
            Route::delete('/items/{item}', [ShoppingController::class, 'deleteItem'])
                ->middleware('module.enabled:shopping')->whereNumber('item')->name('items.destroy');
        });

        Route::middleware('active-admin')->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/modules', [AdminModuleController::class, 'index'])->name('modules.index');
            Route::patch('/modules/{moduleKey}', [AdminModuleController::class, 'update'])
                ->middleware('throttle:module-manage')
                ->where('moduleKey', '[a-z][a-z0-9_]{0,63}')
                ->name('modules.update');
            Route::get('/members', [AdminMemberController::class, 'index'])->name('members.index');
            Route::get('/members/{member}/module-permissions', [AdminMemberModulePermissionController::class, 'index'])
                ->whereNumber('member')
                ->name('members.module-permissions.index');
            Route::put('/members/{member}/module-permissions/{moduleKey}', [AdminMemberModulePermissionController::class, 'update'])
                ->middleware('throttle:member-manage')
                ->whereNumber('member')
                ->where('moduleKey', '[a-z][a-z0-9_]{0,63}')
                ->name('members.module-permissions.update');
            Route::patch('/members/{member}', [AdminMemberController::class, 'update'])
                ->middleware('throttle:member-manage')
                ->whereNumber('member')
                ->name('members.update');
            Route::delete('/members/{member}', [AdminMemberController::class, 'destroy'])
                ->middleware('throttle:member-manage')
                ->whereNumber('member')
                ->name('members.destroy');
            Route::get('/member-audit', AdminMemberAuditController::class)->name('members.audit');

            Route::get('/invitations', [AdminInvitationController::class, 'index'])->name('invitations.index');
            Route::post('/invitations', [AdminInvitationController::class, 'store'])
                ->middleware('throttle:invitation-send')
                ->name('invitations.store');
            Route::post('/invitations/{invitation}/resend', [AdminInvitationController::class, 'resend'])
                ->middleware('throttle:invitation-send')
                ->whereNumber('invitation')
                ->name('invitations.resend');
            Route::delete('/invitations/{invitation}', [AdminInvitationController::class, 'destroy'])
                ->whereNumber('invitation')
                ->name('invitations.destroy');
        });
    });
});
