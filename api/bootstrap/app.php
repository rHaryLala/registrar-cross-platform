<?php

use App\Exceptions\ApiProblem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session par cookie pour les requêtes venant de l'application (domaines listés dans SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Toutes les erreurs de l'API ont la même forme (RFC 9457, application/problem+json).
        $api = fn (Request $r) => $r->is('api/*');

        $exceptions->render(fn (ValidationException $e, Request $r) => $api($r)
            ? ApiProblem::response(422, 'validation_failed', 'Données invalides', 'Corrigez les champs signalés.', ['errors' => $e->errors()])
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $r) => $api($r)
            ? ApiProblem::response(401, 'auth.unauthenticated', 'Connexion requise', 'Votre session a expiré ou vous n’êtes pas connecté.')
            : null);

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e, Request $r) => $api($r)
            ? ApiProblem::response(403, 'forbidden', 'Accès refusé', 'Vous n’avez pas le droit d’effectuer cette action.')
            : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $api($r)
            ? ApiProblem::response(429, 'rate_limited', 'Trop de tentatives', 'Patientez quelques minutes avant de réessayer.', ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)], $e->getHeaders())
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => $api($r)
            ? ApiProblem::response(404, 'not_found', 'Introuvable', 'La ressource demandée n’existe pas.')
            : null);

        // 419 : jeton CSRF absent ou périmé (session expirée). Le front redemande /auth/csrf puis réessaie.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => $api($r) && $e->getStatusCode() === 419
            ? ApiProblem::response(419, 'csrf.mismatch', 'Session expirée', 'Rechargez la page, puis réessayez.')
            : null);

        // Toute autre erreur HTTP, et toute erreur imprévue : même forme, jamais de trace ni de chemin de fichier.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => $api($r)
            ? ApiProblem::response($e->getStatusCode(), 'http.'.$e->getStatusCode(), 'Requête refusée', null)
            : null);

        $exceptions->render(fn (Throwable $e, Request $r) => $api($r) && ! config('app.debug')
            ? ApiProblem::response(500, 'server_error', 'Erreur du serveur', 'Un incident est survenu ; il a été enregistré. Réessayez dans un instant.')
            : null);
    })->create();
