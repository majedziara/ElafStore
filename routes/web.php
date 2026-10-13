<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Nella & Vale API']));
Route::prefix('api/v1/auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('admin-login', [AuthController::class, 'adminLogin'])->middleware('throttle:auth');
    Route::post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:auth');
    Route::post('reset-password', [AuthController::class, 'reset'])->middleware('throttle:auth');
    Route::get('reset-password', [AuthController::class, 'resetForm'])->middleware('throttle:links');
    Route::match(['GET', 'POST'], 'consume/{purpose}', [AuthController::class, 'consume'])->whereIn('purpose', ['verify-email', 'change-email'])->middleware('throttle:links');
    Route::middleware(['auth:web', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('resend-verification', [AuthController::class, 'resend'])->middleware('throttle:links');
        Route::put('profile', [AuthController::class, 'profile']);
        Route::post('change-email', [AuthController::class, 'changeEmail'])->middleware('throttle:links');
        Route::put('password', [AuthController::class, 'changePassword'])->middleware('throttle:auth');
    });
});
