<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
 | API v1 de Registrar. Préfixe final : /api/v1.
 | Session par cookie (Sanctum « SPA », même domaine que l'application) ; l'état d'un
 | utilisateur connecté se lit avec GET /auth/me.
 */
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('csrf', [AuthController::class, 'csrf']);
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('google', [AuthController::class, 'google'])->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me/home', [MeController::class, 'home']);
        Route::get('me/grades', [MeController::class, 'grades']);
        Route::get('me/attendance', [MeController::class, 'attendance']);
    });
});
