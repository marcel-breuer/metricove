<?php

use App\Http\Controllers\Api\AdminInvitationController;
use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InitialSetupController;
use App\Http\Controllers\Api\InvitationClaimController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
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

    Route::middleware('auth:web')->group(function (): void {
        Route::post('/auth/logout', [AuthenticationController::class, 'logout'])->name('auth.logout');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::middleware('active-admin')->prefix('admin')->name('admin.')->group(function (): void {
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
