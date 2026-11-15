<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NewsController;
use App\Http\Controllers\Api\V1\SiteNewsController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\QuizController;
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

        // Mes informations : ma fiche, en lecture seule.
        Route::get('me/profile', [ProfileController::class, 'show']);

        // Actualités de l'université (lecture seule) ; l'image est servie réduite, jamais le fichier d'origine.
        Route::get('me/news', [NewsController::class, 'index']);
        Route::get('me/news/{id}', [NewsController::class, 'show'])->whereNumber('id');
        Route::get('me/news/{id}/image', [NewsController::class, 'image'])->whereNumber('id')->middleware('throttle:120,1');

        // Actualités du SITE public de l'université (copie locale tenue par `news:sync`).
        Route::get('me/site-news', [SiteNewsController::class, 'index']);
        Route::get('me/site-news/{slug}', [SiteNewsController::class, 'show'])->where('slug', '[a-z0-9][a-z0-9-]{0,118}');

        // Ma photo (celle de la fiche étudiante), réduite.
        Route::get('me/photo', [PhotoController::class, 'show'])->middleware('throttle:60,1');

        // Mes inscriptions et fiche d'inscription (PDF fabriqué par le serveur).
        Route::get('me/enrollments', [EnrollmentController::class, 'index']);
        Route::get('me/enrollments/{session}', [EnrollmentController::class, 'show'])->whereNumber('session');
        Route::get('me/enrollments/{session}/pdf', [EnrollmentController::class, 'pdf'])->whereNumber('session')->middleware('throttle:20,1');

        // Quiz UAZ : le serveur crée la partie, juge chaque réponse et calcule le score.
        Route::get('me/quiz', [QuizController::class, 'show']);
        Route::post('me/quiz/parties', [QuizController::class, 'start'])->middleware('throttle:quiz-start');
        Route::post('me/quiz/parties/{party}/reponses', [QuizController::class, 'answer'])->whereNumber('party');
        Route::get('me/quiz/classement', [QuizController::class, 'leaderboard']);
        Route::put('me/quiz/reglages', [QuizController::class, 'settings']);
    });
});
