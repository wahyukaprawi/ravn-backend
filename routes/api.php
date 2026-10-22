<?php

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Auth\ActivationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    Route::prefix('auth')->group(function (): void {
        Route::post('login', LoginController::class)
            ->middleware('throttle:login')
            ->name('api.v1.auth.login');

        Route::post('activate', [ActivationController::class, 'activate'])->name('api.v1.auth.activate');

        Route::post('activation/resend', [ActivationController::class, 'resend'])
            ->middleware('throttle:activation-resend')
            ->name('api.v1.auth.activation.resend');

        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
            ->middleware('throttle:password-forgot')
            ->name('api.v1.auth.password.forgot');

        Route::post('password/reset', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:password-reset')
            ->name('api.v1.auth.password.reset');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');

            Route::prefix('mfa')->group(function (): void {
                Route::post('enroll', [MfaController::class, 'enroll'])->name('api.v1.auth.mfa.enroll');
                Route::post('confirm', [MfaController::class, 'confirm'])->name('api.v1.auth.mfa.confirm');
                Route::post('verify', [MfaController::class, 'verify'])->name('api.v1.auth.mfa.verify');
                Route::post('step-up', [MfaController::class, 'stepUp'])->name('api.v1.auth.mfa.step-up');
            });
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', MeController::class)->name('api.v1.me');

        Route::get('me/sessions', [SessionController::class, 'index'])->name('api.v1.me.sessions.index');
        Route::delete('me/sessions/{id}', [SessionController::class, 'destroy'])->name('api.v1.me.sessions.destroy');
    });

    Route::prefix('admin')->middleware('auth:sanctum')->group(function (): void {
        Route::get('users', [UserController::class, 'index'])
            ->middleware('permission:users.view')
            ->name('api.v1.admin.users.index');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('permission:users.create')
            ->name('api.v1.admin.users.store');
        Route::get('users/{id}', [UserController::class, 'show'])
            ->middleware('permission:users.view')
            ->name('api.v1.admin.users.show');
        Route::patch('users/{id}', [UserController::class, 'update'])
            ->middleware('permission:users.update')
            ->name('api.v1.admin.users.update');

        Route::post('users/{id}/deactivate', [UserController::class, 'deactivate'])
            ->middleware(['permission:users.deactivate', 'fresh.mfa'])
            ->name('api.v1.admin.users.deactivate');
        Route::post('users/{id}/reactivate', [UserController::class, 'reactivate'])
            ->middleware(['permission:users.activate', 'fresh.mfa'])
            ->name('api.v1.admin.users.reactivate');
        Route::post('users/{id}/revoke-sessions', [UserController::class, 'revokeSessions'])
            ->middleware('permission:users.revoke_sessions')
            ->name('api.v1.admin.users.revoke-sessions');
        Route::post('users/{id}/reissue-invitation', [UserController::class, 'reissueInvitation'])
            ->middleware('permission:users.update')
            ->name('api.v1.admin.users.reissue-invitation');

        Route::put('users/{id}/roles', [UserController::class, 'assignRoles'])
            ->middleware(['permission:users.assign_roles', 'fresh.mfa'])
            ->name('api.v1.admin.users.roles.update');

        Route::get('roles', [RoleController::class, 'roles'])
            ->middleware('permission:roles.view')
            ->name('api.v1.admin.roles.index');
        Route::get('permissions', [RoleController::class, 'permissions'])
            ->middleware('permission:roles.view')
            ->name('api.v1.admin.permissions.index');
    });
});
