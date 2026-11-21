<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NewsController;
use App\Http\Controllers\Api\V1\SiteNewsController;
use App\Http\Controllers\Api\V1\TeacherAttendanceController;
use App\Http\Controllers\Api\V1\TeacherGradingController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\ApplicationsController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\BalancesController;
use App\Http\Controllers\Api\V1\DeadlinesController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\ReenrolmentController;
use App\Http\Controllers\Api\V1\TariffController;
use App\Http\Controllers\Api\V1\ReviewAttendanceController;
use App\Http\Controllers\Api\V1\StudentCreationController;
use App\Http\Controllers\Api\V1\StaffDocumentsController;
use App\Http\Controllers\Api\V1\ReviewRemiseController;
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

        // Registrariat : validation des remises de notes (niveaux 1 à 3). Valider est annulable 10 secondes : rien n'est écrit avant.
        Route::prefix('review')->group(function () {
            Route::get('remises', [ReviewRemiseController::class, 'index']);
            Route::get('remises/{id}', [ReviewRemiseController::class, 'show'])->whereNumber('id');
            Route::middleware('throttle:teacher-write')->group(function () {
                Route::post('remises/bulk/approve', [ReviewRemiseController::class, 'approveMany']);
                Route::post('remises/bulk/undo', [ReviewRemiseController::class, 'undoMany']);
                Route::post('remises/bulk/finalize', [ReviewRemiseController::class, 'finalizeMany']);
                Route::post('remises/{id}/approve',[ReviewRemiseController::class, 'approve'])->whereNumber('id');
                Route::post('remises/{id}/undo', [ReviewRemiseController::class, 'undo'])->whereNumber('id');
                Route::post('remises/{id}/finalize', [ReviewRemiseController::class, 'finalize'])->whereNumber('id');
                Route::post('remises/{id}/return', [ReviewRemiseController::class, 'reject'])->whereNumber('id');
                Route::post('attendance/flags/{id}/process', [ReviewAttendanceController::class, 'process'])->whereNumber('id');
            });
            // Assiduité : signalements au-delà de 15 % (§476), retards des enseignants (§474), registres sans base. Superadmin et registrariat.
            Route::get('attendance', [ReviewAttendanceController::class, 'index']);
        });

        // Registrariat : listes (étudiants, cours, enseignants) et fiches en lecture seule. Droits et périmètres vérifiés dans le domaine.
        Route::prefix('staff')->group(function () {
            Route::get('dashboard', [DashboardController::class, 'show']);
            // Création d'un étudiant (niveaux 1 à 3) : choix du formulaire, proposition de matricule et d'adresse, création, compte Google en tâche.
            Route::get('student-creation/options', [StudentCreationController::class, 'options']);
            Route::get('student-creation/suggest', [StudentCreationController::class, 'suggest']);
            Route::get('student-creation/from-application/{id}', [StudentCreationController::class, 'fromApplication'])->whereNumber('id');
            Route::get('students/{id}/google', [StudentCreationController::class, 'google'])->whereNumber('id');
            Route::middleware('throttle:teacher-write')->group(function () {
                Route::post('students', [StudentCreationController::class, 'store']);
                Route::post('students/{id}/google/retry', [StudentCreationController::class, 'retryGoogle'])->whereNumber('id');
            });
            // Candidatures de pré-admission : instruire, archiver, relancer l'envoi des notifications (superadmin, registrariat).
            Route::get('applications', [ApplicationsController::class, 'index']);
            Route::get('applications/{id}', [ApplicationsController::class, 'show'])->whereNumber('id');
            Route::middleware('throttle:teacher-write')->group(function () {
                Route::post('applications/{id}/status', [ApplicationsController::class, 'status'])->whereNumber('id');
                Route::post('applications/{id}/archive', [ApplicationsController::class, 'archive'])->whereNumber('id');
                Route::post('applications/{id}/notifications/retry', [ApplicationsController::class, 'retry'])->whereNumber('id');
            });
            // Finance : autorisation de réinscription (caisse : niveaux 1 et 4 ; dossier : niveaux 1 et 3 ; réinitialisation globale : superadmin seul).
            Route::get('finance/reenrolment', [ReenrolmentController::class, 'index']);
            Route::get('finance/reenrolment/reset/preview', [ReenrolmentController::class, 'preview']);
            Route::middleware('throttle:teacher-write')->group(function () {
                Route::put('finance/reenrolment/students/{id}/{field}', [ReenrolmentController::class, 'toggle'])->whereNumber('id')->whereIn('field', ['authorization', 'file']);
                Route::post('finance/reenrolment/bulk', [ReenrolmentController::class, 'bulk']);
                Route::post('finance/reenrolment/reset', [ReenrolmentController::class, 'reset']);
                Route::post('finance/reenrolment/reset/{batch}/undo', [ReenrolmentController::class, 'undo'])->where('batch', '[A-Za-z0-9]{1,40}');
            });
            // Finance : solde de compte par session (niveaux 1 à 3) ; le solde se corrige ligne par ligne.
            Route::get('finance/balances', [BalancesController::class, 'index']);
            // Barème par session (niveaux 1 à 3) : grille, application à plusieurs filières, reprise de la base, retour à la base ; frais supplémentaires.
            Route::get('finance/tariff', [TariffController::class, 'show']);
            Route::middleware('throttle:teacher-write')->group(function () {
                Route::put('finance/tariff', [TariffController::class, 'save']);
                Route::post('finance/tariff/copy', [TariffController::class, 'copy']);
                Route::post('finance/tariff/reset', [TariffController::class, 'reset']);
                Route::post('finance/extra-fees', [TariffController::class, 'createFee']);
                Route::patch('finance/extra-fees/{id}', [TariffController::class, 'updateFee'])->whereNumber('id');
            });
            // Échéances de paiement : la date limite de chaque tranche, par session (niveaux 1 à 4).
            Route::get('finance/deadlines', [DeadlinesController::class, 'show']);
            Route::put('finance/deadlines/{session}', [DeadlinesController::class, 'save'])->whereNumber('session')->middleware('throttle:teacher-write');
            Route::put('finance/balances/{session}/{student}', [BalancesController::class, 'update'])->whereNumber('session')->where('student', '[0-9]{1,10}')->middleware('throttle:teacher-write');
            Route::get('lists/{list}', [DirectoryController::class, 'index'])->whereIn('list', ['students', 'courses', 'teachers']);
            Route::get('students/{id}', [DirectoryController::class, 'student'])->whereNumber('id');
            // Documents imprimables d'un étudiant (lot 6) : la fiche d'inscription, une par session (niveaux 1 à 4, périmètre de cycle appliqué).
            Route::get('students/{id}/enrollment-sheets', [StaffDocumentsController::class, 'enrollmentSheets'])->whereNumber('id');
            Route::get('students/{id}/enrollment-sheets/{session}/pdf', [StaffDocumentsController::class, 'enrollmentSheetPdf'])->whereNumber('id')->whereNumber('session')->middleware('throttle:30,1');
            // Relevé semestriel des notes (filtres facultatifs : year, semester, level, session).
            Route::get('students/{id}/transcript', [StaffDocumentsController::class, 'transcriptOptions'])->whereNumber('id');
            Route::get('students/{id}/transcript/pdf', [StaffDocumentsController::class, 'transcriptPdf'])->whereNumber('id')->middleware('throttle:30,1');
            // Certificat de scolarité (niveaux 1 à 3 et chefs de mention, pour leur mention).
            Route::get('students/{id}/certificate/pdf', [StaffDocumentsController::class, 'certificatePdf'])->whereNumber('id')->middleware('throttle:30,1');
            Route::get('courses/{id}', [DirectoryController::class, 'course'])->whereNumber('id');
            Route::get('teachers/{id}', [DirectoryController::class, 'teacher'])->whereNumber('id');
        });

        // Espace enseignant : registres de présence. Écritures limitées par compte (la file hors ligne se rejoue en rafale).
        Route::prefix('teacher')->group(function () {
            Route::get('courses', [TeacherAttendanceController::class, 'courses']);
            Route::get('courses/{course}/register', [TeacherAttendanceController::class, 'register'])->whereNumber('course');
            Route::get('sessions/{session}', [TeacherAttendanceController::class, 'session'])->whereNumber('session');

            // Remise de notes : la feuille se remplit ici ; seule la validation du registrariat écrit dans les notes des étudiants.
            Route::get('grading/courses', [TeacherGradingController::class, 'courses']);
            Route::get('grading/courses/{course}/sheet', [TeacherGradingController::class, 'sheet'])->whereNumber('course');

            Route::middleware('throttle:teacher-write')->group(function () {
                Route::put('grading/courses/{course}/components', [TeacherGradingController::class, 'components'])->whereNumber('course');
                Route::put('grading/courses/{course}/cells/{student}', [TeacherGradingController::class, 'cell'])->whereNumber('course')->where('student', '[A-Za-z0-9._-]{1,100}');
                Route::post('grading/courses/{course}/submit', [TeacherGradingController::class, 'submit'])->whereNumber('course');

                Route::put('courses/{course}/planned', [TeacherAttendanceController::class, 'setPlanned'])->whereNumber('course');
                Route::post('courses/{course}/sessions', [TeacherAttendanceController::class, 'createSession'])->whereNumber('course');
                Route::patch('sessions/{session}', [TeacherAttendanceController::class, 'updateSession'])->whereNumber('session');
                Route::delete('sessions/{session}', [TeacherAttendanceController::class, 'deleteSession'])->whereNumber('session');
                Route::put('sessions/{session}/marks/{student}', [TeacherAttendanceController::class, 'mark'])->whereNumber('session')->where('student', '[A-Za-z0-9._-]{1,100}');
                Route::put('sessions/{session}/marks/{student}/justification', [TeacherAttendanceController::class, 'justify'])->whereNumber('session')->where('student', '[A-Za-z0-9._-]{1,100}');
                Route::post('sessions/{session}/mark-all-present', [TeacherAttendanceController::class, 'markAllPresent'])->whereNumber('session');
            });
        });
    });
});
