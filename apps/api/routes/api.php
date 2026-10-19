<?php

use App\Http\Controllers\Api\AdminInvitationController;
use App\Http\Controllers\Api\AdminMemberAuditController;
use App\Http\Controllers\Api\AdminMemberController;
use App\Http\Controllers\Api\AdminMemberModulePermissionController;
use App\Http\Controllers\Api\AdminModuleController;
use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InitialSetupController;
use App\Http\Controllers\Api\InvitationClaimController;
use App\Http\Controllers\Api\ModuleDiscoveryController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\UtilitiesController;
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
